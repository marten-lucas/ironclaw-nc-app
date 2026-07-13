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

		$hasResolverSeam = false;
		$lastResolverReason = 'no_resolver_seam';

		if (method_exists($room, 'getParticipantByActor')) {
			$hasResolverSeam = true;
			try {
				$value = $room->getParticipantByActor($actorType, $actorId);
				if (is_object($value)) {
					return ['isMember' => true, 'reason' => 'room_get_participant_match'];
				}
				if ($participantMismatch) {
					return ['isMember' => false, 'reason' => 'participant_mismatch_and_getParticipantByActor_empty'];
				}
				$lastResolverReason = 'getParticipantByActor_empty';
			} catch (\Throwable $e) {
				$this->logger->debug('Talk getParticipantByActor failed in membership resolver', [
					'app' => 'ironclaw_talk_bridge',
					'error' => $e->getMessage(),
				]);
				if ($participantMismatch) {
					return ['isMember' => false, 'reason' => 'participant_mismatch_and_getParticipantByActor_exception'];
				}
				$lastResolverReason = 'getParticipantByActor_exception';
			}
		}

		if (method_exists($room, 'hasParticipant')) {
			$hasResolverSeam = true;
			try {
				if ((bool)$room->hasParticipant($actorType, $actorId)) {
					return ['isMember' => true, 'reason' => 'room_has_participant_match'];
				}
				if ($participantMismatch) {
					return ['isMember' => false, 'reason' => 'participant_mismatch_and_hasParticipant_false'];
				}
				$lastResolverReason = 'hasParticipant_false';
			} catch (\Throwable $e) {
				$this->logger->debug('Talk hasParticipant failed in membership resolver', [
					'app' => 'ironclaw_talk_bridge',
					'error' => $e->getMessage(),
				]);
				if ($participantMismatch) {
					return ['isMember' => false, 'reason' => 'participant_mismatch_and_hasParticipant_exception'];
				}
				$lastResolverReason = 'hasParticipant_exception';
			}
		}

		$roomActorKeys = $this->collectRoomActorKeys($room);
		if ($roomActorKeys !== []) {
			$actorKey = $actorType . ':' . $actorId;
			if (in_array($actorKey, $roomActorKeys, true)) {
				return ['isMember' => true, 'reason' => 'room_participant_snapshot_match'];
			}
			if ($participantMismatch) {
				return ['isMember' => false, 'reason' => 'participant_mismatch_and_snapshot_missing'];
			}
			return ['isMember' => false, 'reason' => 'snapshot_missing_actor'];
		}

		// Strong mismatch signals still fail closed in strict mode.
		if ($participantMismatch) {
			return ['isMember' => false, 'reason' => 'participant_mismatch_no_resolver_seam'];
		}

		// For server-side Talk chat events with actor identity but without resolver seams,
		// avoid false negatives that would block all inbound messages on some Talk versions.
		if (!$hasResolverSeam) {
			return ['isMember' => true, 'reason' => 'actor_identity_fallback_no_resolver_seam'];
		}

		return ['isMember' => false, 'reason' => $lastResolverReason];
	}

	/**
	 * @return array<int,string>
	 */
	private function collectRoomActorKeys(object $room): array {
		$actors = [];
		foreach (['getParticipants', 'getAttendees'] as $method) {
			if (!method_exists($room, $method)) {
				continue;
			}

			try {
				$value = $room->{$method}();
				if (!is_iterable($value)) {
					continue;
				}

				foreach ($value as $participant) {
					$key = $this->participantActorKey($participant);
					if ($key !== null) {
						$actors[$key] = true;
					}
				}
			} catch (\Throwable $e) {
				$this->logger->debug('Talk room participant collection failed in membership resolver', [
					'app' => 'ironclaw_talk_bridge',
					'method' => $method,
					'error' => $e->getMessage(),
				]);
			}
		}

		return array_values(array_keys($actors));
	}

	private function participantActorKey(mixed $participant): ?string {
		$actorType = null;
		$actorId = null;

		if (is_array($participant)) {
			$actorType = isset($participant['actorType']) ? (string)$participant['actorType'] : null;
			$actorId = isset($participant['actorId']) ? (string)$participant['actorId'] : null;
		} elseif (is_object($participant)) {
			if (method_exists($participant, 'getAttendee')) {
				try {
					$attendee = $participant->getAttendee();
					if (is_object($attendee)) {
						$participant = $attendee;
					}
				} catch (\Throwable) {
				}
			}

			if (method_exists($participant, 'getActorType')) {
				try {
					$actorType = (string)$participant->getActorType();
				} catch (\Throwable) {
				}
			}

			if (method_exists($participant, 'getActorId')) {
				try {
					$actorId = (string)$participant->getActorId();
				} catch (\Throwable) {
				}
			}
		}

		$actorType = is_string($actorType) ? trim($actorType) : '';
		$actorId = is_string($actorId) ? trim($actorId) : '';
		if ($actorType === '' || $actorId === '') {
			return null;
		}

		return $actorType . ':' . $actorId;
	}
}
