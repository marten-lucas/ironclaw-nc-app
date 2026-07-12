<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Listener;

use OCA\IronclawTalkBridge\Db\OutboxRepository;
use OCA\IronclawTalkBridge\Service\AppConfig;
use OCA\IronclawTalkBridge\Service\BridgeCounters;
use OCA\IronclawTalkBridge\Service\MentionMatcher;
use OCA\IronclawTalkBridge\Service\OutboxDispatcher;
use OCA\IronclawTalkBridge\Service\RoomForwardingPolicy;
use OCA\IronclawTalkBridge\Service\TalkRoomMetadataResolver;
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
		private MentionMatcher $mentionMatcher,
		private RoomScopeService $roomScope,
		private TalkMembershipResolver $membershipResolver,
		private TalkRoomMetadataResolver $roomMetadataResolver,
		private RoomForwardingPolicy $forwardingPolicy,
		private OutboxRepository $outbox,
		private OutboxDispatcher $dispatcher,
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
		$messageParameters = is_array($payload['message']['parameters'] ?? null)
			? $payload['message']['parameters']
			: [];
		$mentionDisplayName = $this->config->getMentionDisplayName();

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
				'messageParameters' => $messageParameters,
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

		try {
			$roomMetadata = $this->roomMetadataResolver->resolve($event->getRoom(), $fakeUserId);
		} catch (\Exception $e) {
			$this->logger->warning('Routing skipped because room metadata could not be resolved', [
				'app' => 'ironclaw_talk_bridge',
				'eventId' => $payload['eventId'] ?? null,
				'roomToken' => $roomToken,
				'error' => $e->getMessage(),
			]);
			return;
		}
		$roomType = (string)($roomMetadata['roomType'] ?? RoomForwardingPolicy::ROOM_TYPE_UNKNOWN);
		$roomMetadataSource = (string)($roomMetadata['source'] ?? 'unknown');
		$fakeUserInRoom = (bool)($roomMetadata['botPresent'] ?? false);

		if (!$fakeUserInRoom) {
			$this->counters->increment(BridgeCounters::KEY_MEMBERSHIP_REJECTS);
			$this->logger->debug('Room does not contain configured fake user', [
				'app' => 'ironclaw_talk_bridge',
				'eventId' => $payload['eventId'] ?? null,
				'roomToken' => $roomToken,
				'fakeUserId' => $fakeUserId,
				'roomType' => $roomType,
				'roomMetadataSource' => $roomMetadataSource,
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

		$forwardingDecision = $this->forwardingPolicy->decide(
			$roomType
		);
		$requiresMention = (bool)($forwardingDecision['requiresMention'] ?? true);
		$this->logger->debug('Forwarding decision computed requiresMention=' . ($requiresMention ? 'true' : 'false')
			. ' matchedBy=' . (string)($forwardingDecision['matchedBy'] ?? 'mention')
			. ' roomType=' . $roomType
			. ' roomMetadataSource=' . $roomMetadataSource
			. ' message="' . $messagePreview . '"', [
			'app' => 'ironclaw_talk_bridge',
			'eventId' => $payload['eventId'] ?? null,
			'roomToken' => $roomToken,
			'requiresMention' => $requiresMention,
			'matchedByCandidate' => (string)($forwardingDecision['matchedBy'] ?? 'mention'),
			'roomType' => $roomType,
			'fakeUserId' => $fakeUserId,
			'fakeUserInRoom' => $fakeUserInRoom,
			'roomMetadataSource' => $roomMetadataSource,
		]);

		if ($requiresMention && !$this->mentionMatcher->containsMention(
			$rawMessage,
			$messageParameters,
			$mentionDisplayName,
			$fakeUserId
		)) {
			$this->counters->increment(BridgeCounters::KEY_MENTION_MISSES);
			$this->logger->debug('Forwarding denied reason=mention_required_missing message="' . $messagePreview . '"', [
				'app' => 'ironclaw_talk_bridge',
				'eventId' => $payload['eventId'] ?? null,
				'roomType' => $roomType,
				'mentionDisplayName' => $mentionDisplayName,
				'fakeUserId' => $fakeUserId,
			]);
			return;
		}

		$payload['room']['type'] = $roomType;
		$payload['room']['detectionMethod'] = (string)($roomMetadata['source'] ?? 'unknown');
		$payload['room']['botPresent'] = $fakeUserInRoom;
		$payload['mention'] = [
			'displayName' => $mentionDisplayName,
			'matchedBy' => (string)($forwardingDecision['matchedBy'] ?? 'mention'),
		];
		$payload['message']['stripped'] = $this->mentionMatcher->stripExactMention($rawMessage, $mentionDisplayName);

		$inserted = $this->outbox->enqueue($payload);
		if ($inserted) {
			$this->counters->increment(BridgeCounters::KEY_EVENTS_ENQUEUED);
			$this->logger->info('Forwarding allowed reason=' . (string)($payload['mention']['matchedBy'] ?? 'unknown')
				. ' roomType=' . $roomType
				. ' message="' . $messagePreview . '"', [
				'app' => 'ironclaw_talk_bridge',
				'eventId' => $payload['eventId'] ?? null,
				'roomToken' => $roomToken,
				'roomType' => $roomType,
			]);
			$this->logger->debug('Immediate dispatch skipped to avoid dirty table reads; queued event will be delivered by background job', [
				'app' => 'ironclaw_talk_bridge',
				'eventId' => $payload['eventId'] ?? null,
			]);
		} else {
			$this->counters->increment(BridgeCounters::KEY_EVENTS_DEDUPED);
		}
	}
}
