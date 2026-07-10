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
		if (!$this->config->isStrictMembershipResolverEnabled()) {
			return true;
		}

		if ($actorType === '' || $actorId === '') {
			return false;
		}

		$participant = method_exists($event, 'getParticipant') ? $event->getParticipant() : null;
		if (is_object($participant)) {
			$attendee = method_exists($participant, 'getAttendee') ? $participant->getAttendee() : null;
			if (is_object($attendee)
				&& method_exists($attendee, 'getActorType')
				&& method_exists($attendee, 'getActorId')) {
				return (string)$attendee->getActorType() === $actorType
					&& (string)$attendee->getActorId() === $actorId;
			}
		}

		$room = $event->getRoom();

		if (method_exists($room, 'getParticipantByActor')) {
			try {
				$value = $room->getParticipantByActor($actorType, $actorId);
				return is_object($value);
			} catch (\Throwable $e) {
				$this->logger->debug('Talk getParticipantByActor failed in membership resolver', [
					'app' => 'ironclaw_talk_bridge',
					'error' => $e->getMessage(),
				]);
			}
		}

		if (method_exists($room, 'hasParticipant')) {
			try {
				return (bool)$room->hasParticipant($actorType, $actorId);
			} catch (\Throwable $e) {
				$this->logger->debug('Talk hasParticipant failed in membership resolver', [
					'app' => 'ironclaw_talk_bridge',
					'error' => $e->getMessage(),
				]);
			}
		}

		// Fail closed in strict mode when no stable resolver seam is available.
		return false;
	}
}
