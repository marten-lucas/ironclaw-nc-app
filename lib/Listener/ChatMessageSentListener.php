<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Listener;

use OCA\IronclawTalkBridge\Db\OutboxRepository;
use OCA\IronclawTalkBridge\Service\AppConfig;
use OCA\IronclawTalkBridge\Service\BridgeCounters;
use OCA\IronclawTalkBridge\Service\MentionMatcher;
use OCA\IronclawTalkBridge\Service\OutboxDispatcher;
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
			return;
		}

		$payload = $this->mapper->map($event);
		$roomToken = (string)($payload['roomToken'] ?? '');
		$rawMessage = (string)($payload['message']['raw'] ?? '');
		$messageParameters = is_array($payload['message']['parameters'] ?? null)
			? $payload['message']['parameters']
			: [];
		$isDirectRoom = (bool)($payload['room']['isDirect'] ?? false);
		$roomDetectionMethod = (string)($payload['room']['detectionMethod'] ?? 'unknown');
		$roomParticipantCount = (int)($payload['room']['participantCount'] ?? 0);
		$roomParticipantActors = is_array($payload['room']['participantActors'] ?? null)
			? $payload['room']['participantActors']
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
		if (!$isDirectRoom) {
			$membership = $this->membershipResolver->evaluateEventActorRoomMember($event, $actorType, $actorId);
			if (!(bool)($membership['isMember'] ?? false)) {
				$this->counters->increment(BridgeCounters::KEY_MEMBERSHIP_REJECTS);
				$preview = trim((string)preg_replace('/\s+/u', ' ', $rawMessage));
				$preview = mb_substr($preview, 0, 200);
				$rejectionReason = (string)($membership['reason'] ?? 'unknown');
				$this->logger->debug('Membership resolver rejected actor for scope=room reason=' . $rejectionReason . ': message="' . $preview . '"', [
					'app' => 'ironclaw_talk_bridge',
					'eventId' => $payload['eventId'] ?? null,
					'reason' => $rejectionReason,
					'actorType' => $actorType,
					'actorId' => $actorId,
					'actorDisplayName' => (string)($payload['actor']['displayName'] ?? ''),
					'roomToken' => $roomToken,
					'isDirectRoom' => $isDirectRoom,
					'roomDetectionMethod' => $roomDetectionMethod,
					'roomParticipantCount' => $roomParticipantCount,
					'roomParticipantActors' => $roomParticipantActors,
					'messageRaw' => mb_substr($rawMessage, 0, 500),
					'messageParameters' => $messageParameters,
				]);
				return;
			}
		}

		$fakeUserId = $this->config->getFakeUserId();
		if ($fakeUserId !== '' && $actorType === 'users' && $actorId === $fakeUserId) {
			$this->logger->debug('Self-loop message ignored', [
				'app' => 'ironclaw_talk_bridge',
				'eventId' => $payload['eventId'] ?? null,
			]);
			return;
		}

		if (!$isDirectRoom && !$this->mentionMatcher->containsMention(
			$rawMessage,
			$messageParameters,
			$mentionDisplayName,
			$fakeUserId
		)) {
			$this->counters->increment(BridgeCounters::KEY_MENTION_MISSES);
			$this->logger->debug('Mention not matched', [
				'app' => 'ironclaw_talk_bridge',
				'eventId' => $payload['eventId'] ?? null,
				'isDirectRoom' => $isDirectRoom,
			]);
			return;
		}

		$payload['mention'] = [
			'displayName' => $mentionDisplayName,
			'matchedBy' => $isDirectRoom ? 'direct_room' : 'mention',
		];
		$payload['message']['stripped'] = $this->mentionMatcher->stripExactMention($rawMessage, $mentionDisplayName);

		$inserted = $this->outbox->enqueue($payload);
		if ($inserted) {
			$this->counters->increment(BridgeCounters::KEY_EVENTS_ENQUEUED);
			$this->logger->info('Inbound event queued', [
				'app' => 'ironclaw_talk_bridge',
				'eventId' => $payload['eventId'] ?? null,
			]);
			$this->dispatcher->dispatchDue(1);
		} else {
			$this->counters->increment(BridgeCounters::KEY_EVENTS_DEDUPED);
		}
	}
}
