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
				'isDirect' => $this->isDirectRoom($room),
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

	private function isDirectRoom(object $room): bool {
		if (method_exists($room, 'isOneToOne')) {
			try {
				if ((bool)$room->isOneToOne()) {
					return true;
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
						return true;
					}
				}
				if (is_int($value) && $value === 1) {
					return true;
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
					return true;
				}
			} catch (\Throwable) {
			}
		}

		return false;
	}
}
