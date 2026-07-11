<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Service;

use Psr\Log\LoggerInterface;

class TalkMembershipResolver {
	public function __construct(
		private AppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	public function isEventActorRoomMember(object $event, string $actorType, string $actorId): bool {
		$result = $this->evaluateEventActorRoomMember($event, $actorType, $actorId);
		return (bool)$result['isMember'];
	}

	/**
	 * @return array{isMember:bool,reason:string}
	 */
	public function evaluateEventActorRoomMember(object $event, string $actorType, string $actorId): array {
		if (!$this->config->isStrictMembershipResolverEnabled()) {
			return ['isMember' => true, 'reason' => 'strict_disabled'];
		}

		if ($actorType === '' || $actorId === '') {
			return ['isMember' => false, 'reason' => 'missing_actor_identity'];
		}

		$participantMismatch = false;
		$participant = method_exists($event, 'getParticipant') ? $event->getParticipant() : null;
		if (is_object($participant)) {
			$attendee = method_exists($participant, 'getAttendee') ? $participant->getAttendee() : null;
			if (is_object($attendee)
				&& method_exists($attendee, 'getActorType')
				&& method_exists($attendee, 'getActorId')) {
				if ((string)$attendee->getActorType() === $actorType
					&& (string)$attendee->getActorId() === $actorId) {
					return ['isMember' => true, 'reason' => 'participant_match'];
				}
				$participantMismatch = true;
			}
		}

		$room = $event->getRoom();

		if (method_exists($room, 'getParticipantByActor')) {
			try {
				$value = $room->getParticipantByActor($actorType, $actorId);
				if (is_object($value)) {
					return ['isMember' => true, 'reason' => 'room_get_participant_match'];
				}
				if ($participantMismatch) {
					return ['isMember' => false, 'reason' => 'participant_mismatch_and_getParticipantByActor_empty'];
				}
				return ['isMember' => false, 'reason' => 'getParticipantByActor_empty'];
			} catch (\Throwable $e) {
				$this->logger->debug('Talk getParticipantByActor failed in membership resolver', [
					'app' => 'ironclaw_talk_bridge',
					'error' => $e->getMessage(),
				]);
				if ($participantMismatch) {
					return ['isMember' => false, 'reason' => 'participant_mismatch_and_getParticipantByActor_exception'];
				}
				return ['isMember' => false, 'reason' => 'getParticipantByActor_exception'];
			}
		}

		if (method_exists($room, 'hasParticipant')) {
			try {
				if ((bool)$room->hasParticipant($actorType, $actorId)) {
					return ['isMember' => true, 'reason' => 'room_has_participant_match'];
				}
				if ($participantMismatch) {
					return ['isMember' => false, 'reason' => 'participant_mismatch_and_hasParticipant_false'];
				}
				return ['isMember' => false, 'reason' => 'hasParticipant_false'];
			} catch (\Throwable $e) {
				$this->logger->debug('Talk hasParticipant failed in membership resolver', [
					'app' => 'ironclaw_talk_bridge',
					'error' => $e->getMessage(),
				]);
				if ($participantMismatch) {
					return ['isMember' => false, 'reason' => 'participant_mismatch_and_hasParticipant_exception'];
				}
				return ['isMember' => false, 'reason' => 'hasParticipant_exception'];
			}
		}

		// Fail closed in strict mode when no stable resolver seam is available.
		if ($participantMismatch) {
			return ['isMember' => false, 'reason' => 'participant_mismatch_no_resolver_seam'];
		}
		return ['isMember' => false, 'reason' => 'no_resolver_seam'];
	}
}
