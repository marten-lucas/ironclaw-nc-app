<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Service;

use OCA\Talk\Events\ChatMessageSentEvent;
use OCP\Comments\IComment;

class TalkEventMapper {
	/**
	 * @return array<string, mixed>
	 */
	public function map(ChatMessageSentEvent $event): array {
		$comment = $event->getComment();
		$room = $event->getRoom();
		$roomContext = $this->detectRoomContext($room);
		$roomToken = method_exists($room, 'getToken') ? (string)$room->getToken() : '';
		$messageId = (int)$comment->getId();
		$messageData = $this->extractMessageData((string)$comment->getMessage());

		$replyTo = 0;
		$parent = $event->getParent();
		if ($parent instanceof IComment) {
			$replyTo = (int)$parent->getId();
		} elseif (method_exists($comment, 'getParentId')) {
			$replyTo = (int)$comment->getParentId();
		}

		$occurredAt = new \DateTimeImmutable();
		if (method_exists($comment, 'getCreationDateTime') && $comment->getCreationDateTime() instanceof \DateTimeInterface) {
			$occurredAt = \DateTimeImmutable::createFromInterface($comment->getCreationDateTime());
		}

		return [
			'eventId' => sprintf('nc-talk:%s:%d', $roomToken, $messageId),
			'source' => 'nextcloud-talk',
			'roomToken' => $roomToken,
			'messageId' => $messageId,
			'replyTo' => $replyTo,
			'actor' => [
				'type' => method_exists($comment, 'getActorType') ? (string)$comment->getActorType() : '',
				'id' => method_exists($comment, 'getActorId') ? (string)$comment->getActorId() : '',
				'displayName' => method_exists($comment, 'getActorDisplayName') ? (string)$comment->getActorDisplayName() : '',
			],
			'room' => [
				'isDirect' => $roomContext['isDirect'],
				'participantCount' => $roomContext['participantCount'],
				'detectionMethod' => $roomContext['detectionMethod'],
				'participantActors' => $roomContext['participantActors'],
			],
			'message' => [
				'raw' => $messageData['message'],
				'parameters' => $messageData['parameters'],
				'stripped' => '',
			],
			'occurredAt' => $occurredAt->format(DATE_ATOM),
		];
	}

	/**
	 * @return array{message:string,parameters:array<mixed>}
	 */
	private function extractMessageData(string $raw): array {
		$decoded = json_decode($raw, true);
		if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
			$parameters = [];
			if (isset($decoded['messageParameters']) && is_array($decoded['messageParameters'])) {
				$parameters = $decoded['messageParameters'];
			}

			return [
				'message' => $decoded['message'],
				'parameters' => $parameters,
			];
		}

		return [
			'message' => $raw,
			'parameters' => [],
		];
	}

	/**
	 * @return array{isDirect:bool,participantCount:int|null,detectionMethod:string,participantActors:array<int,string>}
	 */
	private function detectRoomContext(object $room): array {
		$participantActors = $this->collectParticipantActors($room);
		$participantCount = count($participantActors) > 0 ? count($participantActors) : null;

		if (method_exists($room, 'isOneToOne')) {
			try {
				if ((bool)$room->isOneToOne()) {
					return [
						'isDirect' => true,
						'participantCount' => $participantCount,
						'detectionMethod' => 'isOneToOne',
						'participantActors' => $participantActors,
					];
				}
			} catch (\Throwable) {
			}
		}

		foreach (['getType', 'getConversationType', 'getRoomType'] as $method) {
			if (!method_exists($room, $method)) {
				continue;
			}

			try {
				$value = $room->{$method}();
				if (is_string($value)) {
					$normalized = strtolower(trim($value));
					if (in_array($normalized, ['direct', 'one_to_one', 'one-to-one', 'one2one', 'single'], true)) {
						return [
							'isDirect' => true,
							'participantCount' => $participantCount,
							'detectionMethod' => $method,
							'participantActors' => $participantActors,
						];
					}
				}
				if (is_int($value) && $value === 1) {
					return [
						'isDirect' => true,
						'participantCount' => $participantCount,
						'detectionMethod' => $method,
						'participantActors' => $participantActors,
					];
				}
			} catch (\Throwable) {
			}
		}

		foreach (['getParticipantCount', 'countParticipants', 'getNumberOfParticipants'] as $method) {
			if (!method_exists($room, $method)) {
				continue;
			}

			try {
				$count = $room->{$method}();
				if (is_int($count) && $count <= 2) {
					return [
						'isDirect' => true,
						'participantCount' => $count,
						'detectionMethod' => $method,
						'participantActors' => $participantActors,
					];
				}
				if (is_int($count)) {
					$participantCount = $count;
				}
			} catch (\Throwable) {
			}
		}

		if ($participantCount !== null && $participantCount <= 2) {
			return [
				'isDirect' => true,
				'participantCount' => $participantCount,
				'detectionMethod' => 'participant_count_snapshot',
				'participantActors' => $participantActors,
			];
		}

		return [
			'isDirect' => false,
			'participantCount' => $participantCount,
			'detectionMethod' => 'fallback_room',
			'participantActors' => $participantActors,
		];
	}

	/**
	 * @return array<int,string>
	 */
	private function collectParticipantActors(object $room): array {
		$participants = [];
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
					$actor = $this->participantActorKey($participant);
					if ($actor !== null) {
						$participants[$actor] = true;
					}
				}
			} catch (\Throwable) {
			}
		}

		return array_values(array_keys($participants));
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
