<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Listener;

use OCA\IronclawTalkBridge\Service\AppConfig;
use OCA\IronclawTalkBridge\Service\BridgeCounters;
use OCA\IronclawTalkBridge\Service\IronclawClient;
use OCA\IronclawTalkBridge\Service\MentionMatcher;
use OCA\IronclawTalkBridge\Service\TalkParticipantInspector;
use OCA\IronclawTalkBridge\Service\RoomForwardingPolicy;
use OCA\IronclawTalkBridge\Service\RoomScopeService;
use OCA\IronclawTalkBridge\Service\TalkEventMapper;
use OCA\Talk\Events\ChatMessageSentEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 */
class ChatMessageSentListener implements IEventListener {
	public function __construct(
		private AppConfig $config,
		private TalkEventMapper $mapper,
		private RoomScopeService $roomScope,
		private MentionMatcher $mentionMatcher,
		private TalkParticipantInspector $participantInspector,
		private RoomForwardingPolicy $forwardingPolicy,
		private IronclawClient $client,
		private BridgeCounters $counters,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof ChatMessageSentEvent) {
			return;
		}

		if (!$this->config->isEnabled() || !$this->config->isReadyForDelivery()) {
			$this->logDecision('deny', 'bridge_disabled_or_not_ready', [
				'bridgeEnabled' => $this->config->isEnabled(),
				'isReadyForDelivery' => $this->config->isReadyForDelivery(),
			]);
			return;
		}

		$payload = $this->mapper->map($event);
		$this->counters->increment(BridgeCounters::KEY_EVENTS_RECEIVED);

		$roomToken = (string)($payload['roomToken'] ?? '');
		$rawMessage = (string)($payload['message']['raw'] ?? '');
		$messagePreview = trim((string)preg_replace('/\s+/u', ' ', $rawMessage));
		$messagePreview = mb_substr($messagePreview, 0, 200);

		$this->logger->debug('Inbound event received roomToken=' . $roomToken . ' message="' . $messagePreview . '" (sync-routing)', [
			'app' => 'ironclaw_talk_bridge',
			'eventId' => $payload['eventId'] ?? null,
			'roomToken' => $roomToken,
			'messageRaw' => mb_substr($rawMessage, 0, 500),
		]);

		if (!$this->roomScope->isAllowed($roomToken)) {
			$this->counters->increment(BridgeCounters::KEY_EVENTS_DENIED);
			$this->logDecision('deny', 'room_out_of_scope', [
				'eventId' => $payload['eventId'] ?? null,
				'roomToken' => $roomToken,
				'messagePreview' => $messagePreview,
			]);
			return;
		}

		$actorType = (string)($payload['actor']['type'] ?? '');
		$actorId = (string)($payload['actor']['id'] ?? '');
		$fakeUserId = $this->config->getFakeUserId();
		if ($fakeUserId === '') {
			$this->counters->increment(BridgeCounters::KEY_EVENTS_DENIED);
			$this->logDecision('deny', 'fake_user_not_configured', [
				'eventId' => $payload['eventId'] ?? null,
				'roomToken' => $roomToken,
				'messagePreview' => $messagePreview,
			]);
			return;
		}

		if ($actorType === 'users' && $actorId === $fakeUserId) {
			$this->counters->increment(BridgeCounters::KEY_EVENTS_DENIED);
			$this->logDecision('deny', 'self_loop_message', [
				'eventId' => $payload['eventId'] ?? null,
				'roomToken' => $roomToken,
				'actorId' => $actorId,
				'messagePreview' => $messagePreview,
			]);
			return;
		}

		$roomType = $this->resolveRoomType($event->getRoom());
		$forwardingDecision = $this->forwardingPolicy->decide($roomType);
		$requiresMention = (bool)($forwardingDecision['requiresMention'] ?? true);
		$matchedBy = (string)($forwardingDecision['matchedBy'] ?? 'mention');
		$mentionDisplayName = $this->config->getMentionDisplayName();
		$hasMention = $this->mentionMatcher->containsMention(
			$rawMessage,
			is_array($payload['message']['parameters'] ?? null) ? $payload['message']['parameters'] : [],
			$mentionDisplayName,
			$fakeUserId,
		);

		if ($requiresMention && !$hasMention) {
			$twoParticipantRoom = $this->participantInspector->isTwoParticipantRoomWithFakeUser($roomToken, $fakeUserId);
			if ($twoParticipantRoom === true) {
				$requiresMention = false;
				$matchedBy = 'two_participant_room_with_fake_user';
				$this->logger->debug('Participant inspector overrode mention requirement for two-participant room', [
					'app' => 'ironclaw_talk_bridge',
					'eventId' => $payload['eventId'] ?? null,
					'roomToken' => $roomToken,
					'roomType' => $roomType,
				]);
			} else {
			$this->counters->increment(BridgeCounters::KEY_EVENTS_DENIED);
			$this->counters->increment(BridgeCounters::KEY_MENTION_MISSES);
			$this->logDecision('deny', 'mention_required_missing', [
				'eventId' => $payload['eventId'] ?? null,
				'roomToken' => $roomToken,
				'roomType' => $roomType,
				'requiresMention' => true,
				'hasMention' => false,
				'messagePreview' => $messagePreview,
			]);
			return;
			}
		}

		$payload['room']['type'] = $roomType;
		$payload['room']['detectionMethod'] = 'event_room_type';
		$payload['room']['fakeUserInRoom'] = true;
		$payload['mention'] = [
			'displayName' => $mentionDisplayName,
			'matchedBy' => $matchedBy,
		];
		$payload['message']['stripped'] = $this->mentionMatcher->stripExactMention($rawMessage, $mentionDisplayName);

		$this->counters->increment(BridgeCounters::KEY_EVENTS_ALLOWED);
		$this->logDecision('allow', 'forward_to_ironclaw', [
			'eventId' => $payload['eventId'] ?? null,
			'roomToken' => $roomToken,
			'roomType' => $roomType,
			'requiresMention' => $requiresMention,
			'hasMention' => $hasMention,
			'matchedBy' => $matchedBy,
			'messagePreview' => $messagePreview,
		]);

		$wirePayload = $this->buildIronclawWebhookPayload($payload);
		$maxAttempts = 2;
		$lastError = '';
		for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
			try {
				$statusCode = $this->client->deliver($wirePayload);
				if ($statusCode >= 200 && $statusCode < 300) {
					$this->counters->increment(BridgeCounters::KEY_EVENTS_DELIVERED);
					$this->logger->info('Ironclaw event delivered (sync)', [
						'app' => 'ironclaw_talk_bridge',
						'eventId' => $payload['eventId'] ?? null,
						'status' => $statusCode,
						'attempt' => $attempt,
					]);
					$this->logDecision('allow', 'delivered', [
						'eventId' => $payload['eventId'] ?? null,
						'roomToken' => $roomToken,
						'status' => $statusCode,
						'attempt' => $attempt,
					]);
					return;
				}

				$lastError = 'Ironclaw returned HTTP ' . $statusCode;
			} catch (\Throwable $e) {
				$lastError = $e->getMessage();
			}

			if ($attempt < $maxAttempts) {
				usleep(150000);
			}
		}

		$this->counters->increment(BridgeCounters::KEY_DELIVERY_FAILURES);
		$this->logger->warning('Ironclaw event delivery failed (sync)', [
			'app' => 'ironclaw_talk_bridge',
			'eventId' => $payload['eventId'] ?? null,
			'error' => $lastError,
			'attempts' => $maxAttempts,
		]);
		$this->logDecision('deny', 'delivery_failed', [
			'eventId' => $payload['eventId'] ?? null,
			'roomToken' => $roomToken,
			'error' => $lastError,
			'attempts' => $maxAttempts,
		]);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function buildIronclawWebhookPayload(array $payload): array {
		$roomToken = trim((string)($payload['roomToken'] ?? ''));
		$messageId = trim((string)($payload['messageId'] ?? ''));
		$eventId = trim((string)($payload['eventId'] ?? ''));

		$actor = is_array($payload['actor'] ?? null) ? $payload['actor'] : [];
		$actorType = trim((string)($actor['type'] ?? 'users'));
		$actorId = trim((string)($actor['id'] ?? 'unknown-actor'));
		$actorName = trim((string)($actor['displayName'] ?? $actor['name'] ?? ''));

		$message = is_array($payload['message'] ?? null) ? $payload['message'] : [];
		$rawMessage = trim((string)($message['raw'] ?? ''));
		$strippedMessage = trim((string)($message['stripped'] ?? ''));
		$contentText = $strippedMessage !== '' ? $strippedMessage : $rawMessage;

		$mentionDisplayName = ltrim($this->config->getMentionDisplayName(), '@');
		$mentionToken = $mentionDisplayName !== '' ? '@' . $mentionDisplayName : '';
		if ($mentionToken !== '' && strpos($contentText, $mentionToken) === false) {
			$contentText = trim($mentionToken . ' ' . $contentText);
		}

		if ($contentText === '') {
			$contentText = $mentionToken !== '' ? $mentionToken : 'ping';
		}

		$wirePayload = [
			'type' => 'Create',
			'actor' => [
				'type' => $actorType !== '' ? $actorType : 'users',
				'id' => $actorId,
				'name' => $actorName,
			],
			'object' => [
				'id' => $messageId,
				'content' => $contentText,
			],
			'target' => [
				'id' => $roomToken,
			],
		];

		if ($eventId !== '') {
			$wirePayload['eventId'] = $eventId;
		}
		if (isset($payload['room']) && is_array($payload['room'])) {
			$wirePayload['room'] = $payload['room'];
		}
		if (isset($payload['mention']) && is_array($payload['mention'])) {
			$wirePayload['mention'] = $payload['mention'];
		}
		if (isset($payload['message']) && is_array($payload['message'])) {
			$wirePayload['bridgeMessage'] = $payload['message'];
		}
		if (isset($payload['occurredAt'])) {
			$wirePayload['occurredAt'] = $payload['occurredAt'];
		}

		return $wirePayload;
	}

	private function resolveRoomType(object $room): string {
		$rawType = null;
		if (method_exists($room, 'getType')) {
			try {
				$rawType = $room->getType();
			} catch (\Throwable) {
				$rawType = null;
			}
		}

		$mappedFromRaw = $this->mapRoomTypeValue($rawType, $room);
		if ($mappedFromRaw !== RoomForwardingPolicy::ROOM_TYPE_UNKNOWN) {
			return $mappedFromRaw;
		}

		if (method_exists($room, 'isPublic')) {
			try {
				if ((bool)$room->isPublic()) {
					return RoomForwardingPolicy::ROOM_TYPE_PUBLIC;
				}
			} catch (\Throwable) {
			}
		}

		if (method_exists($room, 'isSingleUserRoom')) {
			try {
				if ((bool)$room->isSingleUserRoom()) {
					return RoomForwardingPolicy::ROOM_TYPE_ONE_TO_ONE;
				}
			} catch (\Throwable) {
			}
		}

		return RoomForwardingPolicy::ROOM_TYPE_UNKNOWN;
	}

	private function mapRoomTypeValue(mixed $rawType, object $room): string {
		if (is_string($rawType)) {
			$normalized = strtolower(trim($rawType));
			if (in_array($normalized, ['one_to_one', 'one-to-one', 'direct', 'direct_message', 'dm'], true)) {
				return RoomForwardingPolicy::ROOM_TYPE_ONE_TO_ONE;
			}
			if (in_array($normalized, ['group', 'private'], true)) {
				return RoomForwardingPolicy::ROOM_TYPE_GROUP;
			}
			if ($normalized === 'public') {
				return RoomForwardingPolicy::ROOM_TYPE_PUBLIC;
			}
		}

		if (!is_int($rawType) && !is_float($rawType) && !is_numeric($rawType)) {
			return RoomForwardingPolicy::ROOM_TYPE_UNKNOWN;
		}

		$rawInt = (int)$rawType;
		$className = get_class($room);
		$constantMap = [
			'TYPE_ONE_TO_ONE' => RoomForwardingPolicy::ROOM_TYPE_ONE_TO_ONE,
			'TYPE_GROUP' => RoomForwardingPolicy::ROOM_TYPE_GROUP,
			'TYPE_PUBLIC' => RoomForwardingPolicy::ROOM_TYPE_PUBLIC,
			'ROOM_TYPE_ONE_TO_ONE' => RoomForwardingPolicy::ROOM_TYPE_ONE_TO_ONE,
			'ROOM_TYPE_GROUP' => RoomForwardingPolicy::ROOM_TYPE_GROUP,
			'ROOM_TYPE_PUBLIC' => RoomForwardingPolicy::ROOM_TYPE_PUBLIC,
		];

		foreach ($constantMap as $constantName => $mappedType) {
			$fullName = $className . '::' . $constantName;
			if (!defined($fullName)) {
				continue;
			}

			$constantValue = constant($fullName);
			if (is_int($constantValue) && $rawInt === $constantValue) {
				return $mappedType;
			}
		}

		return RoomForwardingPolicy::ROOM_TYPE_UNKNOWN;
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function logDecision(string $decision, string $reason, array $context = []): void {
		$this->logger->debug('SYNC routing decision=' . $decision . ' reason=' . $reason, array_merge([
			'app' => 'ironclaw_talk_bridge',
		], $context));
	}
}
