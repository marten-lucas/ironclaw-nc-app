<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Service;

use OCA\IronclawTalkBridge\Db\OutboxRepository;
use Psr\Log\LoggerInterface;

class OutboxDispatcher {
	private const MAX_ATTEMPTS = 20;

	public function __construct(
		private OutboxRepository $outbox,
		private IronclawClient $client,
		private AppConfig $config,
		private TalkRoomMetadataResolver $roomMetadataResolver,
		private MentionMatcher $mentionMatcher,
		private RoomForwardingPolicy $forwardingPolicy,
		private BridgeCounters $counters,
		private LoggerInterface $logger,
	) {
	}

	public function dispatchDue(?int $limit = null): int {
		if (!$this->config->isEnabled() || !$this->config->isReadyForDelivery()) {
			return 0;
		}

		$rows = $this->outbox->fetchDue($limit ?? $this->config->getDispatchBatchSize());
		$processed = 0;

		foreach ($rows as $row) {
			$processed++;
			$this->dispatchRow($row);
		}

		return $processed;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function dispatchRow(array $row): void {
		$id = (int)$row['id'];
		$attempts = (int)$row['attempts'];
		$payload = json_decode((string)$row['payload'], true);

		if (!is_array($payload)) {
			$this->counters->increment(BridgeCounters::KEY_DELIVERY_FAILURES);
			$this->outbox->markRetry($id, $attempts + 1, time() + 3600, 'Invalid payload', true);
			return;
		}

		try {
			$prepared = $this->preparePayloadForDelivery($payload);
		} catch (\Throwable $e) {
			$this->scheduleRetry($id, $attempts, $e->getMessage());
			return;
		}

		if (!$prepared['shouldDeliver']) {
			$this->outbox->markFiltered($id, (string)($prepared['reason'] ?? 'filtered'));
			$this->counters->increment(BridgeCounters::KEY_EVENTS_FILTERED);
			if ((bool)($prepared['mentionMiss'] ?? false)) {
				$this->counters->increment(BridgeCounters::KEY_MENTION_MISSES);
			}
			if ((bool)($prepared['membershipReject'] ?? false)) {
				$this->counters->increment(BridgeCounters::KEY_MEMBERSHIP_REJECTS);
			}
			$this->logger->info('Queued event filtered before delivery', [
				'app' => 'ironclaw_talk_bridge',
				'eventId' => $payload['eventId'] ?? null,
				'reason' => $prepared['reason'] ?? 'filtered',
				'roomType' => $prepared['roomType'] ?? null,
			]);
			return;
		}

		$deliveryPayload = is_array($prepared['payload'] ?? null) ? $prepared['payload'] : $payload;
		$this->logger->info('Forwarding allowed reason=' . (string)($deliveryPayload['mention']['matchedBy'] ?? 'unknown')
			. ' roomType=' . (string)($prepared['roomType'] ?? 'unknown')
			. ' message="' . (string)($deliveryPayload['message']['raw'] ?? '') . '"', [
			'app' => 'ironclaw_talk_bridge',
			'eventId' => $deliveryPayload['eventId'] ?? null,
			'roomToken' => $deliveryPayload['roomToken'] ?? null,
			'roomType' => $prepared['roomType'] ?? null,
		]);

		try {
			$statusCode = $this->client->deliver($deliveryPayload);
			if ($statusCode >= 200 && $statusCode < 300) {
				$this->outbox->markDelivered($id);
				$this->counters->increment(BridgeCounters::KEY_EVENTS_DELIVERED);
				$this->logger->info('Ironclaw event delivered', [
					'app' => 'ironclaw_talk_bridge',
					'eventId' => $deliveryPayload['eventId'] ?? null,
					'status' => $statusCode,
				]);
				return;
			}

			$this->scheduleRetry($id, $attempts, 'Ironclaw returned HTTP ' . $statusCode);
		} catch (\Throwable $e) {
			$this->scheduleRetry($id, $attempts, $e->getMessage());
		}
	}

	private function scheduleRetry(int $id, int $attempts, string $error): void {
		$this->counters->increment(BridgeCounters::KEY_DELIVERY_FAILURES);
		$newAttempts = $attempts + 1;
		$terminal = $newAttempts >= self::MAX_ATTEMPTS;
		$delay = min(1800, 5 * (2 ** min(10, $newAttempts)));
		$nextAttemptAt = time() + $delay;
		$this->outbox->markRetry($id, $newAttempts, $nextAttemptAt, $error, $terminal);

		$this->logger->warning('Ironclaw event delivery failed', [
			'app' => 'ironclaw_talk_bridge',
			'attempts' => $newAttempts,
			'terminal' => $terminal,
			'error' => $error,
		]);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{shouldDeliver:bool,payload?:array<string,mixed>,reason?:string,roomType?:string,mentionMiss?:bool,membershipReject?:bool}
	 */
	private function preparePayloadForDelivery(array $payload): array {
		$roomToken = trim((string)($payload['roomToken'] ?? ''));
		$fakeUserId = $this->config->getFakeUserId();
		if ($fakeUserId === '') {
			return [
				'shouldDeliver' => false,
				'reason' => 'fake_user_not_configured',
				'membershipReject' => true,
			];
		}

		$room = new class ($roomToken) {
			public function __construct(private string $roomToken) {
			}

			public function getToken(): string {
				return $this->roomToken;
			}
		};

		$roomMetadata = $this->roomMetadataResolver->resolve($room, $fakeUserId);
		$roomType = (string)($roomMetadata['roomType'] ?? RoomForwardingPolicy::ROOM_TYPE_UNKNOWN);
		$fakeUserInRoom = (bool)($roomMetadata['fakeUserInRoom'] ?? false);

		if (!$fakeUserInRoom) {
			return [
				'shouldDeliver' => false,
				'reason' => 'fake_user_not_in_room',
				'roomType' => $roomType,
				'membershipReject' => true,
			];
		}

		$actorType = trim((string)($payload['actor']['type'] ?? ''));
		$actorId = trim((string)($payload['actor']['id'] ?? ''));
		if ($actorType === 'users' && $actorId === $fakeUserId) {
			return [
				'shouldDeliver' => false,
				'reason' => 'self_loop_message',
				'roomType' => $roomType,
			];
		}

		$forwardingDecision = $this->forwardingPolicy->decide($roomType);
		$requiresMention = (bool)($forwardingDecision['requiresMention'] ?? true);
		$rawMessage = (string)($payload['message']['raw'] ?? '');
		$messageParameters = is_array($payload['message']['parameters'] ?? null)
			? $payload['message']['parameters']
			: [];
		$mentionDisplayName = $this->config->getMentionDisplayName();

		if ($requiresMention && !$this->mentionMatcher->containsMention(
			$rawMessage,
			$messageParameters,
			$mentionDisplayName,
			$fakeUserId
		)) {
			return [
				'shouldDeliver' => false,
				'reason' => 'mention_required_missing',
				'roomType' => $roomType,
				'mentionMiss' => true,
			];
		}

		$payload['room']['type'] = $roomType;
		$payload['room']['detectionMethod'] = (string)($roomMetadata['source'] ?? 'unknown');
		$payload['room']['fakeUserInRoom'] = $fakeUserInRoom;
		$payload['room']['participantCount'] = $roomMetadata['participantCount'] ?? null;
		$payload['mention'] = [
			'displayName' => $mentionDisplayName,
			'matchedBy' => (string)($forwardingDecision['matchedBy'] ?? 'mention'),
		];
		$payload['message']['stripped'] = $this->mentionMatcher->stripExactMention($rawMessage, $mentionDisplayName);

		$this->logger->debug('Deferred forwarding decision computed requiresMention=' . ($requiresMention ? 'true' : 'false')
			. ' matchedBy=' . (string)($forwardingDecision['matchedBy'] ?? 'mention')
			. ' roomType=' . $roomType
			. ' roomMetadataSource=' . (string)($roomMetadata['source'] ?? 'unknown'), [
			'app' => 'ironclaw_talk_bridge',
			'eventId' => $payload['eventId'] ?? null,
			'roomToken' => $roomToken,
			'requiresMention' => $requiresMention,
			'roomType' => $roomType,
			'fakeUserInRoom' => $fakeUserInRoom,
		]);

		return [
			'shouldDeliver' => true,
			'payload' => $payload,
			'roomType' => $roomType,
		];
	}
}
