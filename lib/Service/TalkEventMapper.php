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
			'message' => [
				'raw' => $this->extractMessage((string)$comment->getMessage()),
				'stripped' => '',
			],
			'occurredAt' => $occurredAt->format(DATE_ATOM),
		];
	}

	private function extractMessage(string $raw): string {
		$decoded = json_decode($raw, true);
		if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
			return $decoded['message'];
		}

		return $raw;
	}
}
