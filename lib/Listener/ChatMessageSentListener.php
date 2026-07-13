<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Listener;

use OCA\IronclawTalkBridge\Db\OutboxRepository;
use OCA\IronclawTalkBridge\Service\AppConfig;
use OCA\IronclawTalkBridge\Service\BridgeCounters;
use OCA\IronclawTalkBridge\Service\RoomScopeService;
use OCA\IronclawTalkBridge\Service\TalkMembershipResolver;
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
		private TalkMembershipResolver $membershipResolver,
		private OutboxRepository $outbox,
		private BridgeCounters $counters,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof ChatMessageSentEvent) {
			return;
		}

		if (!$this->config->isEnabled() || !$this->config->isReadyForDelivery()) {
			$this->logger->debug('Inbound event skipped reason=bridge_disabled_or_not_ready', [
				'app' => 'ironclaw_talk_bridge',
				'bridgeEnabled' => $this->config->isEnabled(),
				'isReadyForDelivery' => $this->config->isReadyForDelivery(),
			]);
			return;
		}

		$payload = $this->mapper->map($event);
		$roomToken = (string)($payload['roomToken'] ?? '');
		$rawMessage = (string)($payload['message']['raw'] ?? '');
		$messagePreview = trim((string)preg_replace('/\s+/u', ' ', $rawMessage));
		$messagePreview = mb_substr($messagePreview, 0, 200);
		$this->logger->debug('Inbound event received roomToken=' . $roomToken . ' message="' . $messagePreview . '"', [
			'app' => 'ironclaw_talk_bridge',
			'eventId' => $payload['eventId'] ?? null,
			'roomToken' => $roomToken,
			'messageRaw' => mb_substr($rawMessage, 0, 500),
		]);
		if (!$this->roomScope->isAllowed($roomToken)) {
			$this->logger->debug('Room out of scope', [
				'app' => 'ironclaw_talk_bridge',
				'roomToken' => $roomToken,
			]);
			return;
		}

		$actorType = (string)($payload['actor']['type'] ?? '');
		$actorId = (string)($payload['actor']['id'] ?? '');
		$membership = $this->membershipResolver->evaluateEventActorRoomMember($event, $actorType, $actorId);
		if (!(bool)($membership['isMember'] ?? false)) {
			$this->counters->increment(BridgeCounters::KEY_MEMBERSHIP_REJECTS);
			$rejectionReason = (string)($membership['reason'] ?? 'unknown');
			$this->logger->debug('Membership resolver rejected actor for scope=room reason=' . $rejectionReason . ': message="' . $messagePreview . '"', [
				'app' => 'ironclaw_talk_bridge',
				'eventId' => $payload['eventId'] ?? null,
				'reason' => $rejectionReason,
				'actorType' => $actorType,
				'actorId' => $actorId,
				'actorDisplayName' => (string)($payload['actor']['displayName'] ?? ''),
				'roomToken' => $roomToken,
				'messageRaw' => mb_substr($rawMessage, 0, 500),
			]);
			return;
		}

		$fakeUserId = $this->config->getFakeUserId();
		if ($fakeUserId === '') {
			$this->logger->debug('Routing skipped because fake user id is not configured', [
				'app' => 'ironclaw_talk_bridge',
				'eventId' => $payload['eventId'] ?? null,
				'roomToken' => $roomToken,
			]);
			return;
		}

		if ($fakeUserId !== '' && $actorType === 'users' && $actorId === $fakeUserId) {
			$this->logger->debug('Self-loop message ignored', [
				'app' => 'ironclaw_talk_bridge',
				'eventId' => $payload['eventId'] ?? null,
			]);
			return;
		}

		$inserted = $this->outbox->enqueue($payload);
		if ($inserted) {
			$this->counters->increment(BridgeCounters::KEY_EVENTS_ENQUEUED);
			$this->logger->info('Inbound event queued for deferred routing message="' . $messagePreview . '"', [
				'app' => 'ironclaw_talk_bridge',
				'eventId' => $payload['eventId'] ?? null,
				'roomToken' => $roomToken,
			]);
			$this->logger->debug('Deferred routing enabled; queued event will be evaluated by background job', [
				'app' => 'ironclaw_talk_bridge',
				'eventId' => $payload['eventId'] ?? null,
			]);
		} else {
			$this->counters->increment(BridgeCounters::KEY_EVENTS_DEDUPED);
		}
	}
}
