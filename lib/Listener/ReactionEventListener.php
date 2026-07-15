<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Listener;

use OCA\IronclawTalkBridge\Service\AppConfig;
use OCA\IronclawTalkBridge\Service\BridgeCounters;
use OCA\IronclawTalkBridge\Service\IronclawClient;
use OCA\IronclawTalkBridge\Service\RoomScopeService;
use OCA\IronclawTalkBridge\Service\TalkEventMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 */
class ReactionEventListener implements IEventListener {
	public function __construct(
		private AppConfig $config,
		private TalkEventMapper $mapper,
		private RoomScopeService $roomScope,
		private IronclawClient $client,
		private BridgeCounters $counters,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		$eventType = $event::class;
		if (!str_ends_with($eventType, 'ReactionAddedEvent') && !str_ends_with($eventType, 'ReactionRemovedEvent')) {
			return;
		}

		if (!$this->config->isEnabled() || !$this->config->isReadyForDelivery()) {
			$this->logDecision('deny', 'bridge_disabled_or_not_ready', ['eventType' => $eventType]);
			return;
		}

		$mappedType = str_ends_with($eventType, 'ReactionAddedEvent') ? 'ReactionAdded' : 'ReactionRemoved';
		$payload = $this->mapper->mapReactionEvent($event, $mappedType);
		$this->counters->increment(BridgeCounters::KEY_EVENTS_RECEIVED);

		$roomToken = (string)($payload['roomToken'] ?? '');
		if (!$this->roomScope->isAllowed($roomToken)) {
			$this->counters->increment(BridgeCounters::KEY_EVENTS_DENIED);
			$this->logDecision('deny', 'room_out_of_scope', [
				'eventId' => $payload['eventId'] ?? null,
				'roomToken' => $roomToken,
			]);
			return;
		}

		$actorType = (string)($payload['actor']['type'] ?? '');
		$actorId = (string)($payload['actor']['id'] ?? '');
		$fakeUserId = $this->config->getFakeUserId();
		if ($actorType === 'users' && $fakeUserId !== '' && $actorId === $fakeUserId) {
			$this->counters->increment(BridgeCounters::KEY_EVENTS_DENIED);
			$this->logDecision('deny', 'self_loop_reaction', [
				'eventId' => $payload['eventId'] ?? null,
				'roomToken' => $roomToken,
				'actorId' => $actorId,
			]);
			return;
		}

		$reaction = (string)($payload['reaction']['emoji'] ?? '');
		$reactionSignal = $this->buildReactionSignal($reaction, $mappedType, $actorType, $actorId);
		$payload['reaction'] = array_merge(
			is_array($payload['reaction']) ? $payload['reaction'] : [],
			$reactionSignal
		);

		$wirePayload = $this->buildIronclawWebhookPayload($payload);
		$maxAttempts = 2;
		$lastError = '';
		for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
			try {
				$statusCode = $this->client->deliver($wirePayload);
				if ($statusCode >= 200 && $statusCode < 300) {
					$this->counters->increment(BridgeCounters::KEY_EVENTS_DELIVERED);
					$this->logDecision('allow', 'reaction_delivered', [
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
		$this->logDecision('deny', 'reaction_delivery_failed', [
			'eventId' => $payload['eventId'] ?? null,
			'roomToken' => $roomToken,
			'error' => $lastError,
			'attempts' => $maxAttempts,
		]);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function buildReactionSignal(string $emoji, string $eventType, string $actorType, string $actorId): array {
		$signal = [
			'semantic' => 'reaction_event',
			'uiAction' => 'informational',
			'requiresAuthorization' => false,
			'isAuthorized' => true,
			'text' => sprintf('Reaction event (%s): %s', $eventType, $emoji),
		];

		if ($emoji === '👍') {
			$signal['semantic'] = 'helpful_feedback';
			$signal['uiAction'] = 'mark_helpful';
			$signal['text'] = 'User marked the bot answer as helpful (thumbs up).';
		} elseif ($emoji === '👎') {
			$signal['semantic'] = 'needs_rephrase';
			$signal['uiAction'] = 'offer_rephrase';
			$signal['text'] = 'User gave thumbs down. Offer a rephrased answer or a different focus.';
		} elseif ($emoji === '🔁') {
			$signal['semantic'] = 'regenerate_same_context';
			$signal['uiAction'] = 'regenerate';
			$signal['text'] = 'User requested regeneration with the same context.';
		} elseif ($emoji === '✅') {
			$signal['semantic'] = 'human_approved';
			$signal['uiAction'] = 'approval';
			$signal['requiresAuthorization'] = true;
			$signal['isAuthorized'] = $this->config->isReactionApprovalActor($actorType, $actorId);
			$signal['text'] = $signal['isAuthorized']
				? 'Authorized human-in-the-loop approval received.'
				: 'Approval reaction received from a non-authorized actor.';
		} elseif ($emoji === '❌') {
			$signal['semantic'] = 'human_not_approved';
			$signal['uiAction'] = 'approval';
			$signal['requiresAuthorization'] = true;
			$signal['isAuthorized'] = $this->config->isReactionApprovalActor($actorType, $actorId);
			$signal['text'] = $signal['isAuthorized']
				? 'Authorized human-in-the-loop rejection received.'
				: 'Rejection reaction received from a non-authorized actor.';
		} elseif ($emoji === '❗') {
			$signal['semantic'] = 'escalation_flag';
			$signal['uiAction'] = 'escalate';
			$signal['text'] = 'User requested escalation/review for this response.';
		}

		if ($eventType === 'ReactionRemoved') {
			$signal['semantic'] = 'reaction_removed';
			$signal['uiAction'] = 'informational';
			$signal['text'] = sprintf('Reaction removed: %s', $emoji);
		}

		return $signal;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function buildIronclawWebhookPayload(array $payload): array {
		$roomToken = trim((string)($payload['roomToken'] ?? ''));
		$room = is_array($payload['room'] ?? null) ? $payload['room'] : [];
		$roomName = trim((string)($room['displayName'] ?? $room['name'] ?? ''));
		$messageId = trim((string)($payload['messageId'] ?? ''));
		$eventId = trim((string)($payload['eventId'] ?? ''));
		$reaction = is_array($payload['reaction'] ?? null) ? $payload['reaction'] : [];

		$actor = is_array($payload['actor'] ?? null) ? $payload['actor'] : [];
		$actorType = trim((string)($actor['type'] ?? 'users'));
		$actorId = trim((string)($actor['id'] ?? 'unknown-actor'));
		$actorName = trim((string)($actor['displayName'] ?? ''));

		$wirePayload = [
			'type' => (string)($reaction['eventType'] ?? 'ReactionAdded'),
			'actor' => [
				'type' => $actorType !== '' ? $actorType : 'users',
				'id' => $actorId,
				'name' => $actorName,
			],
			'object' => [
				'id' => $messageId,
				'content' => (string)($reaction['text'] ?? ''),
			],
			'target' => [
				'id' => $roomToken,
				'name' => $roomName,
			],
			'reaction' => [
				'emoji' => (string)($reaction['emoji'] ?? ''),
				'eventType' => (string)($reaction['eventType'] ?? ''),
				'targetMessageId' => (int)($reaction['targetMessageId'] ?? 0),
				'reactionMessageId' => (int)($reaction['reactionMessageId'] ?? 0),
				'semantic' => (string)($reaction['semantic'] ?? 'reaction_event'),
				'uiAction' => (string)($reaction['uiAction'] ?? 'informational'),
				'requiresAuthorization' => (bool)($reaction['requiresAuthorization'] ?? false),
				'isAuthorized' => (bool)($reaction['isAuthorized'] ?? true),
			],
		];

		if ($eventId !== '') {
			$wirePayload['eventId'] = $eventId;
		}
		if (isset($payload['occurredAt'])) {
			$wirePayload['occurredAt'] = $payload['occurredAt'];
		}

		return $wirePayload;
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function logDecision(string $decision, string $reason, array $context = []): void {
		$this->logger->info('Talk reaction routing decision', [
			'app' => 'ironclaw_talk_bridge',
			'decision' => $decision,
			'reason' => $reason,
		] + $context);
	}
}
