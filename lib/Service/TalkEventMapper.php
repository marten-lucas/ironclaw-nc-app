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
		$attachments = $this->extractAttachments($messageData['parameters']);

		$replyTo = 0;
		$parent = $event->getParent();
		if ($parent instanceof IComment) {
			$replyTo = (int)$parent->getId();
		} elseif (method_exists($comment, 'getParentId')) {
			$replyTo = (int)$comment->getParentId();
		}
		$replyContext = $this->buildReplyContext($parent, $replyTo);

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
				'attachments' => $attachments,
				'stripped' => '',
			],
			'replyContext' => $replyContext,
			'occurredAt' => $occurredAt->format(DATE_ATOM),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function mapReactionEvent(object $event, string $eventType): array {
		$room = method_exists($event, 'getRoom') ? $event->getRoom() : null;
		$roomToken = is_object($room) && method_exists($room, 'getToken') ? (string)$room->getToken() : '';
		$roomName = is_object($room) ? $this->resolveRoomName($room) : '';

		$message = method_exists($event, 'getMessage') ? $event->getMessage() : null;
		$messageId = $message instanceof IComment ? (int)$message->getId() : 0;
		$reactionMessage = method_exists($event, 'getReactionMessage') ? $event->getReactionMessage() : null;
		$reactionMessageId = $reactionMessage instanceof IComment ? (int)$reactionMessage->getId() : 0;

		$actorType = method_exists($event, 'getActorType') ? (string)$event->getActorType() : '';
		$actorId = method_exists($event, 'getActorId') ? (string)$event->getActorId() : '';
		$actorDisplayName = method_exists($event, 'getActorDisplayName') ? (string)$event->getActorDisplayName() : '';
		$reaction = method_exists($event, 'getReaction') ? (string)$event->getReaction() : '';

		$occurredAt = new \\DateTimeImmutable();
		$eventId = sprintf(
			'nc-talk:%s:reaction:%d:%s:%s',
			$roomToken,
			$messageId,
			$reactionMessageId > 0 ? (string)$reactionMessageId : $reaction,
			$eventType
		);

		return [
			'eventId' => $eventId,
			'source' => 'nextcloud-talk',
			'roomToken' => $roomToken,
			'messageId' => $messageId,
			'actor' => [
				'type' => $actorType,
				'id' => $actorId,
				'displayName' => $actorDisplayName,
			],
			'room' => [
				'name' => $roomName,
				'displayName' => $roomName,
				'roomName' => $roomName,
				'type' => RoomForwardingPolicy::ROOM_TYPE_UNKNOWN,
				'detectionMethod' => 'event_reaction',
				'fakeUserInRoom' => false,
			],
			'reaction' => [
				'eventType' => $eventType,
				'emoji' => $reaction,
				'targetMessageId' => $messageId,
				'reactionMessageId' => $reactionMessageId,
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

	/**
	 * @param array<mixed> $parameters
	 * @return array<int,array<string,mixed>>
	 */
	private function extractAttachments(array $parameters): array {
		$attachments = [];
		foreach ($parameters as $slot => $parameter) {
			if (!is_array($parameter)) {
				continue;
			}

			$attachment = $this->normalizeAttachment((string)$slot, $parameter);
			if ($attachment !== null) {
				$attachments[] = $attachment;
			}
		}

		return $attachments;
	}

	/**
	 * @param array<string,mixed> $parameter
	 * @return array<string,mixed>|null
	 */
	private function normalizeAttachment(string $slot, array $parameter): ?array {
		$type = strtolower(trim((string)($parameter['type'] ?? '')));
		if (in_array($type, ['user', 'call', 'conversation'], true)) {
			return null;
		}

		$hasAttachmentSignals = $type !== ''
			|| isset($parameter['id'])
			|| isset($parameter['fileId'])
			|| isset($parameter['fileid'])
			|| isset($parameter['mimetype'])
			|| isset($parameter['mimeType'])
			|| isset($parameter['size'])
			|| isset($parameter['path'])
			|| isset($parameter['link'])
			|| isset($parameter['url']);

		if (!$hasAttachmentSignals) {
			return null;
		}

		$attachment = [
			'slot' => $slot,
			'sourceType' => $type !== '' ? $type : 'parameter',
			'name' => trim((string)($parameter['name'] ?? $parameter['fileName'] ?? $parameter['displayName'] ?? '')),
			'id' => trim((string)($parameter['fileId'] ?? $parameter['fileid'] ?? $parameter['id'] ?? '')),
			'mimeType' => trim((string)($parameter['mimeType'] ?? $parameter['mimetype'] ?? $parameter['mime-type'] ?? '')),
			'path' => trim((string)($parameter['path'] ?? '')),
			'link' => trim((string)($parameter['link'] ?? $parameter['url'] ?? '')),
			'raw' => $parameter,
		];

		$size = $parameter['size'] ?? $parameter['bytes'] ?? $parameter['fileSize'] ?? null;
		if (is_numeric($size)) {
			$attachment['sizeBytes'] = (int)$size;
		}

		return $attachment;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function buildReplyContext(mixed $parent, int $replyTo): ?array {
		if ($replyTo <= 0) {
			return null;
		}

		$context = ['parentMessageId' => $replyTo];
		if (!$parent instanceof IComment) {
			return $context;
		}

		$parentData = $this->extractMessageData((string)$parent->getMessage());
		$context['parentMessage'] = [
			'raw' => $parentData['message'],
			'parameters' => $parentData['parameters'],
			'actor' => [
				'type' => method_exists($parent, 'getActorType') ? (string)$parent->getActorType() : '',
				'id' => method_exists($parent, 'getActorId') ? (string)$parent->getActorId() : '',
				'displayName' => method_exists($parent, 'getActorDisplayName') ? (string)$parent->getActorDisplayName() : '',
			],
		];

		return $context;
	}
}
