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
		$roomToken = method_exists($room, 'getToken') ? (string)$room->getToken() : '';
		$roomName = $this->resolveRoomName($room);
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
				'name' => $roomName,
				'displayName' => $roomName,
				'roomName' => $roomName,
				'type' => RoomForwardingPolicy::ROOM_TYPE_UNKNOWN,
				'detectionMethod' => 'event_minimal',
				'fakeUserInRoom' => false,
			],
			'message' => [
				'raw' => $messageData['message'],
				'parameters' => $messageData['parameters'],
				'stripped' => '',
			],
			'occurredAt' => $occurredAt->format(DATE_ATOM),
		];
	}

	private function resolveRoomName(object $room): string {
		$methods = ['getDisplayName', 'getName'];
		foreach ($methods as $method) {
			if (!method_exists($room, $method)) {
				continue;
			}
			try {
				$value = trim((string)$room->{$method}());
				if ($value !== '') {
					return $value;
				}
			} catch (\Throwable) {
			}
		}

		return '';
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
}
