<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Listener;

use OCA\IronclawTalkBridge\Service\AppConfig;
use OCA\IronclawTalkBridge\Service\AttachmentContextBuilder;
use OCA\IronclawTalkBridge\Service\BridgeCounters;
use OCA\IronclawTalkBridge\Service\IronclawClient;
use OCA\IronclawTalkBridge\Service\MentionMatcher;
use OCA\IronclawTalkBridge\Service\TalkParticipantInspector;
use OCA\IronclawTalkBridge\Service\RoomForwardingPolicy;
use OCA\IronclawTalkBridge\Service\RoomScopeService;
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
		private RoomScopeService $roomScope,
		private MentionMatcher $mentionMatcher,
		private AttachmentContextBuilder $attachmentContextBuilder,
		private TalkParticipantInspector $participantInspector,
		private RoomForwardingPolicy $forwardingPolicy,
		private IronclawClient $client,
		private BridgeCounters $counters,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof ChatMessageSentEvent) {
			return;
		}

		if (!$this->config->isEnabled() || !$this->config->isReadyForDelivery()) {
			$this->logDecision('deny', 'bridge_disabled_or_not_ready', [
				'bridgeEnabled' => $this->config->isEnabled(),
				'isReadyForDelivery' => $this->config->isReadyForDelivery(),
			]);
			return;
		}

		$payload = $this->mapper->map($event);
		$this->counters->increment(BridgeCounters::KEY_EVENTS_RECEIVED);

		$roomToken = (string)($payload['roomToken'] ?? '');
		$rawMessage = (string)($payload['message']['raw'] ?? '');
		$messagePreview = trim((string)preg_replace('/\s+/u', ' ', $rawMessage));
		$messagePreview = mb_substr($messagePreview, 0, 200);

		$this->logger->debug('Inbound event received roomToken=' . $roomToken . ' message="' . $messagePreview . '" (sync-routing)', [
			'app' => 'ironclaw_talk_bridge',
			'eventId' => $payload['eventId'] ?? null,
			'roomToken' => $roomToken,
			'messageRaw' => mb_substr($rawMessage, 0, 500),
		]);

		if (!$this->roomScope->isAllowed($roomToken)) {
			$this->counters->increment(BridgeCounters::KEY_EVENTS_DENIED);
			$this->logDecision('deny', 'room_out_of_scope', [
				'eventId' => $payload['eventId'] ?? null,
				'roomToken' => $roomToken,
				'messagePreview' => $messagePreview,
			]);
			return;
		}

		$actorType = (string)($payload['actor']['type'] ?? '');
		$actorId = (string)($payload['actor']['id'] ?? '');
		$fakeUserId = $this->config->getFakeUserId();
		if ($fakeUserId === '') {
			$this->counters->increment(BridgeCounters::KEY_EVENTS_DENIED);
			$this->logDecision('deny', 'fake_user_not_configured', [
				'eventId' => $payload['eventId'] ?? null,
				'roomToken' => $roomToken,
				'messagePreview' => $messagePreview,
			]);
			return;
		}

		if ($actorType === 'users' && $actorId === $fakeUserId) {
			$this->counters->increment(BridgeCounters::KEY_EVENTS_DENIED);
			$this->logDecision('deny', 'self_loop_message', [
				'eventId' => $payload['eventId'] ?? null,
				'roomToken' => $roomToken,
				'actorId' => $actorId,
				'messagePreview' => $messagePreview,
			]);
			return;
		}

		$roomType = $this->resolveRoomType($event->getRoom());
		$forwardingDecision = $this->forwardingPolicy->decide($roomType);
		$requiresMention = (bool)($forwardingDecision['requiresMention'] ?? true);
		$matchedBy = (string)($forwardingDecision['matchedBy'] ?? 'mention');
		$mentionDisplayName = $this->config->getMentionDisplayName();
		$fakeUserName = $this->config->getFakeUserName();
		$hasMention = $this->mentionMatcher->containsMention(
			$rawMessage,
			is_array($payload['message']['parameters'] ?? null) ? $payload['message']['parameters'] : [],
			$fakeUserName,
			$fakeUserId,
		);

		if ($requiresMention && !$hasMention) {
			$twoParticipantRoom = $this->participantInspector->isTwoParticipantRoomWithFakeUser($roomToken, $fakeUserId);
			if ($twoParticipantRoom === true) {
				$requiresMention = false;
				$matchedBy = 'two_participant_room_with_fake_user';
				$this->logger->debug('Participant inspector overrode mention requirement for two-participant room', [
					'app' => 'ironclaw_talk_bridge',
					'eventId' => $payload['eventId'] ?? null,
					'roomToken' => $roomToken,
					'roomType' => $roomType,
				]);
			} else {
			$this->counters->increment(BridgeCounters::KEY_EVENTS_DENIED);
			$this->counters->increment(BridgeCounters::KEY_MENTION_MISSES);
			$this->logDecision('deny', 'mention_required_missing', [
				'eventId' => $payload['eventId'] ?? null,
				'roomToken' => $roomToken,
				'roomType' => $roomType,
				'requiresMention' => true,
				'hasMention' => false,
				'messagePreview' => $messagePreview,
			]);
			return;
			}
		}

		$payload['room']['type'] = $roomType;
		$payload['room']['detectionMethod'] = 'event_room_type';
		$payload['room']['fakeUserInRoom'] = true;
		$payload['mention'] = [
			'displayName' => $fakeUserName,
			'userId' => $fakeUserId,
			'matchedBy' => $matchedBy,
		];
		$payload['message']['stripped'] = $this->mentionMatcher->stripMentionsForFakeUser(
			$rawMessage,
			$fakeUserName,
			$fakeUserId,
		);
		$payload['message']['mentionEntities'] = $this->extractBotMentionEntities(
			$rawMessage,
			is_array($payload['message']['parameters'] ?? null) ? $payload['message']['parameters'] : [],
			$fakeUserName,
			$fakeUserId,
		);

		$attachmentResult = $this->attachmentContextBuilder->enrichForActor(
			is_array($payload['message']['attachments'] ?? null) ? $payload['message']['attachments'] : [],
			$actorId,
		);
		$payload['message']['attachments'] = $attachmentResult['attachments'];
		if (($attachmentResult['errors'] ?? []) !== []) {
			$payload['message']['attachmentErrors'] = $attachmentResult['errors'];
		}

		$this->counters->increment(BridgeCounters::KEY_EVENTS_ALLOWED);
		$this->logDecision('allow', 'forward_to_ironclaw', [
			'eventId' => $payload['eventId'] ?? null,
			'roomToken' => $roomToken,
			'roomType' => $roomType,
			'requiresMention' => $requiresMention,
			'hasMention' => $hasMention,
			'matchedBy' => $matchedBy,
			'messagePreview' => $messagePreview,
		]);

		$wirePayload = $this->buildIronclawWebhookPayload($payload);
		$maxAttempts = 2;
		$lastError = '';
		for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
			try {
				$statusCode = $this->client->deliver($wirePayload);
				if ($statusCode >= 200 && $statusCode < 300) {
					$this->counters->increment(BridgeCounters::KEY_EVENTS_DELIVERED);
					$this->logger->info('Ironclaw event delivered (sync)', [
						'app' => 'ironclaw_talk_bridge',
						'eventId' => $payload['eventId'] ?? null,
						'status' => $statusCode,
						'attempt' => $attempt,
					]);
					$this->logDecision('allow', 'delivered', [
						'eventId' => $payload['eventId'] ?? null,
						'roomToken' => $roomToken,
						'status' => $statusCode,
						'attempt' => $attempt,
					]);
					return;
				}

				$lastError = 'Ironclaw returned HTTP ' . $statusCode;
			} catch (\Throwable $e) {
				$lastError = $e->getMessage();
			}

			if ($attempt < $maxAttempts) {
				usleep(150000);
			}
		}

		$this->counters->increment(BridgeCounters::KEY_DELIVERY_FAILURES);
		$this->logger->warning('Ironclaw event delivery failed (sync)', [
			'app' => 'ironclaw_talk_bridge',
			'eventId' => $payload['eventId'] ?? null,
			'error' => $lastError,
			'attempts' => $maxAttempts,
		]);
		$this->logDecision('deny', 'delivery_failed', [
			'eventId' => $payload['eventId'] ?? null,
			'roomToken' => $roomToken,
			'error' => $lastError,
			'attempts' => $maxAttempts,
		]);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function buildIronclawWebhookPayload(array $payload): array {
		$roomToken = trim((string)($payload['roomToken'] ?? ''));
		$messageId = trim((string)($payload['messageId'] ?? ''));
		$eventId = trim((string)($payload['eventId'] ?? ''));

		$actor = is_array($payload['actor'] ?? null) ? $payload['actor'] : [];
		$actorType = trim((string)($actor['type'] ?? 'users'));
		$actorId = trim((string)($actor['id'] ?? 'unknown-actor'));
		$actorName = trim((string)($actor['displayName'] ?? $actor['name'] ?? ''));

		$message = is_array($payload['message'] ?? null) ? $payload['message'] : [];
		$rawMessage = trim((string)($message['raw'] ?? ''));
		$strippedMessage = trim((string)($message['stripped'] ?? ''));
		$contentText = $rawMessage !== '' ? $rawMessage : $strippedMessage;
		$room = is_array($payload['room'] ?? null) ? $payload['room'] : [];
		$roomName = trim((string)($room['displayName'] ?? $room['name'] ?? $room['roomName'] ?? ''));
		$attachmentErrors = $this->canonicalAttachmentErrors($payload);
		$attachments = $this->canonicalAttachments($payload);

		if ($contentText === '') {
			if ($attachments !== []) {
				$contentText = 'Please analyze the provided attachments.';
			} elseif ($attachmentErrors !== []) {
				$contentText = 'Attachment processing failed. Please check attachmentErrors for details.';
			} else {
				$contentText = 'ping';
			}
		}

		$objectPayload = [
			'id' => $messageId,
			'content' => $contentText,
		];

		$replyTo = (int)($payload['replyTo'] ?? 0);
		if ($replyTo > 0) {
			$objectPayload['replyTo'] = $replyTo;
		}

		if ($attachments !== []) {
			$objectPayload['attachments'] = $attachments;
		}
		if ($attachmentErrors !== []) {
			$objectPayload['attachmentErrors'] = $attachmentErrors;
		}

		$wirePayload = [
			'type' => 'Create',
			'actor' => [
				'type' => $actorType !== '' ? $actorType : 'users',
				'id' => $actorId,
				'name' => $actorName,
			],
			'object' => $objectPayload,
			'target' => [
				'id' => $roomToken,
				'name' => $roomName,
			],
		];

		if ($eventId !== '') {
			$wirePayload['eventId'] = $eventId;
		}
		$mention = $this->canonicalMentionSection($payload);
		if ($mention !== null) {
			$wirePayload['mention'] = $mention;
		}

		$bridgeMessage = $this->canonicalBridgeMessageSection($payload);
		if ($bridgeMessage !== null) {
			$wirePayload['bridgeMessage'] = $bridgeMessage;
		}

		$replyContext = $this->canonicalReplyContext($payload['replyContext'] ?? null);
		if ($replyContext !== null) {
			$wirePayload['replyContext'] = $replyContext;
		}

		if (isset($payload['occurredAt'])) {
			$wirePayload['occurredAt'] = $payload['occurredAt'];
		}

		return $wirePayload;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>|null
	 */
	private function canonicalMentionSection(array $payload): ?array {
		if (!isset($payload['mention']) || !is_array($payload['mention'])) {
			return null;
		}

		$mention = $payload['mention'];
		$userId = trim((string)($mention['userId'] ?? ''));
		if ($userId === '') {
			return null;
		}

		$normalized = ['userId' => $userId];
		$displayName = trim((string)($mention['displayName'] ?? ''));
		if ($displayName !== '') {
			$normalized['displayName'] = $displayName;
		}

		return $normalized;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>|null
	 */
	private function canonicalBridgeMessageSection(array $payload): ?array {
		if (!isset($payload['message']) || !is_array($payload['message'])) {
			return null;
		}

		$message = $payload['message'];
		$raw = trim((string)($message['raw'] ?? ''));
		$entities = $this->canonicalMentionEntities($message['mentionEntities'] ?? null);
		$attachments = $this->canonicalAttachmentList($message['attachments'] ?? null);
		$attachmentErrors = $this->canonicalAttachmentErrorList($message['attachmentErrors'] ?? null);

		if ($raw === '' && $entities === [] && $attachments === [] && $attachmentErrors === []) {
			return null;
		}

		$normalized = [];
		if ($raw !== '') {
			$normalized['raw'] = $raw;
		}
		if ($entities !== []) {
			$normalized['mentionEntities'] = $entities;
		}
		if ($attachments !== []) {
			$normalized['attachments'] = $attachments;
		}
		if ($attachmentErrors !== []) {
			$normalized['attachmentErrors'] = $attachmentErrors;
		}

		return $normalized;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<int,array<string,mixed>>
	 */
	private function canonicalAttachments(array $payload): array {
		if (!isset($payload['message']) || !is_array($payload['message'])) {
			return [];
		}

		return $this->canonicalAttachmentList($payload['message']['attachments'] ?? null);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<int,array<string,mixed>>
	 */
	private function canonicalAttachmentErrors(array $payload): array {
		if (!isset($payload['message']) || !is_array($payload['message'])) {
			return [];
		}

		return $this->canonicalAttachmentErrorList($payload['message']['attachmentErrors'] ?? null);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function canonicalAttachmentList(mixed $value): array {
		if (!is_array($value)) {
			return [];
		}

		$normalized = [];
		foreach ($value as $attachment) {
			if (!is_array($attachment)) {
				continue;
			}

			$entry = [];
			foreach (['slot', 'sourceType', 'name', 'id', 'fileId', 'mimeType', 'path', 'link', 'sizeBytes', 'downloadSource', 'extract', 'raw'] as $key) {
				if (!array_key_exists($key, $attachment)) {
					continue;
				}
				$entry[$key] = $attachment[$key];
			}

			if ($entry !== []) {
				$normalized[] = $entry;
			}
		}

		return $normalized;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function canonicalAttachmentErrorList(mixed $value): array {
		if (!is_array($value)) {
			return [];
		}

		$normalized = [];
		foreach ($value as $error) {
			if (!is_array($error)) {
				continue;
			}

			$entry = [];
			foreach (['attachmentName', 'attachmentId', 'code', 'message'] as $key) {
				if (!array_key_exists($key, $error)) {
					continue;
				}
				$entry[$key] = (string)$error[$key];
			}

			if (($entry['code'] ?? '') !== '') {
				$normalized[] = $entry;
			}
		}

		return $normalized;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function canonicalReplyContext(mixed $replyContext): ?array {
		if (!is_array($replyContext)) {
			return null;
		}

		$parentMessageId = (int)($replyContext['parentMessageId'] ?? 0);
		if ($parentMessageId <= 0) {
			return null;
		}

		$normalized = ['parentMessageId' => $parentMessageId];
		if (isset($replyContext['parentMessage']) && is_array($replyContext['parentMessage'])) {
			$parentMessage = $replyContext['parentMessage'];
			$normalizedParent = [];
			$raw = trim((string)($parentMessage['raw'] ?? ''));
			if ($raw !== '') {
				$normalizedParent['raw'] = $raw;
			}
			if (isset($parentMessage['parameters']) && is_array($parentMessage['parameters'])) {
				$normalizedParent['parameters'] = $parentMessage['parameters'];
			}
			if (isset($parentMessage['actor']) && is_array($parentMessage['actor'])) {
				$normalizedParent['actor'] = [
					'type' => trim((string)($parentMessage['actor']['type'] ?? '')),
					'id' => trim((string)($parentMessage['actor']['id'] ?? '')),
					'displayName' => trim((string)($parentMessage['actor']['displayName'] ?? '')),
				];
			}
			if ($normalizedParent !== []) {
				$normalized['parentMessage'] = $normalizedParent;
			}
		}

		return $normalized;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function canonicalMentionEntities(mixed $rawEntities): array {
		if (!is_array($rawEntities)) {
			return [];
		}

		$normalized = [];
		foreach ($rawEntities as $entity) {
			if (!is_array($entity)) {
				continue;
			}

			$item = [];
			$token = trim((string)($entity['token'] ?? ''));
			$id = trim((string)($entity['id'] ?? ''));
			$name = trim((string)($entity['name'] ?? ''));
			$isBot = (bool)($entity['isBot'] ?? false);

			if ($token !== '') {
				$item['token'] = $token;
			}
			if ($id !== '') {
				$item['id'] = $id;
			}
			if ($name !== '') {
				$item['name'] = $name;
			}
			if ($isBot) {
				$item['isBot'] = true;
			}

			if ($item !== []) {
				$normalized[] = $item;
			}
		}

		return array_values($normalized);
	}

	/**
	 * @param array<mixed> $messageParameters
	 * @return array<int,array<string,mixed>>
	 */
	private function extractBotMentionEntities(
		string $rawMessage,
		array $messageParameters,
		string $mentionDisplayName,
		string $fakeUserId
	): array {
		$mentionDisplayName = ltrim(trim($mentionDisplayName), '@');
		$fakeUserId = ltrim(trim($fakeUserId), '@');

		$candidateTokens = [];
		if ($mentionDisplayName !== '') {
			$candidateTokens[] = '@' . $mentionDisplayName;
		}
		if ($fakeUserId !== '') {
			$candidateTokens[] = '@' . $fakeUserId;
		}

		foreach ($messageParameters as $value) {
			if (!is_array($value)) {
				continue;
			}
			$type = strtolower(trim((string)($value['type'] ?? '')));
			if ($type !== '' && !in_array($type, ['user', 'users', 'mention'], true)) {
				continue;
			}

			$candidateId = ltrim(trim((string)($value['id'] ?? $value['actorId'] ?? '')), '@');
			$candidateName = ltrim(trim((string)($value['name'] ?? $value['label'] ?? $value['displayName'] ?? '')), '@');

			$isBotById = $fakeUserId !== '' && $candidateId !== '' && strcasecmp($candidateId, $fakeUserId) === 0;
			$isBotByName = $mentionDisplayName !== '' && $candidateName !== '' && strcasecmp($candidateName, $mentionDisplayName) === 0;
			if (!$isBotById && !$isBotByName) {
				continue;
			}

			if ($candidateName !== '') {
				$candidateTokens[] = '@' . $candidateName;
			}
			if ($candidateId !== '') {
				$candidateTokens[] = '@' . $candidateId;
			}
		}

		$entities = [];
		$seen = [];
		foreach ($candidateTokens as $token) {
			$token = trim((string)$token);
			if ($token === '' || isset($seen[$token])) {
				continue;
			}
			if (!$this->containsMentionToken($rawMessage, $token)) {
				continue;
			}

			$entities[] = [
				'token' => $token,
				'isBot' => true,
			];
			$seen[$token] = true;
		}

		return $entities;
	}

	private function containsMentionToken(string $message, string $token): bool {
		$escaped = preg_quote($token, '/');
		$pattern = '/(^|[\s])' . $escaped . '(?=$|[\s\.,:;!?])/u';
		return preg_match($pattern, $message) === 1;
	}

	private function resolveRoomType(object $room): string {
		$rawType = null;
		if (method_exists($room, 'getType')) {
			try {
				$rawType = $room->getType();
			} catch (\Throwable) {
				$rawType = null;
			}
		}

		$mappedFromRaw = $this->mapRoomTypeValue($rawType, $room);
		if ($mappedFromRaw !== RoomForwardingPolicy::ROOM_TYPE_UNKNOWN) {
			return $mappedFromRaw;
		}

		if (method_exists($room, 'isPublic')) {
			try {
				if ((bool)$room->isPublic()) {
					return RoomForwardingPolicy::ROOM_TYPE_PUBLIC;
				}
			} catch (\Throwable) {
			}
		}

		if (method_exists($room, 'isSingleUserRoom')) {
			try {
				if ((bool)$room->isSingleUserRoom()) {
					return RoomForwardingPolicy::ROOM_TYPE_ONE_TO_ONE;
				}
			} catch (\Throwable) {
			}
		}

		return RoomForwardingPolicy::ROOM_TYPE_UNKNOWN;
	}

	private function mapRoomTypeValue(mixed $rawType, object $room): string {
		if (is_string($rawType)) {
			$normalized = strtolower(trim($rawType));
			if (in_array($normalized, ['one_to_one', 'one-to-one', 'direct', 'direct_message', 'dm'], true)) {
				return RoomForwardingPolicy::ROOM_TYPE_ONE_TO_ONE;
			}
			if (in_array($normalized, ['group', 'private'], true)) {
				return RoomForwardingPolicy::ROOM_TYPE_GROUP;
			}
			if ($normalized === 'public') {
				return RoomForwardingPolicy::ROOM_TYPE_PUBLIC;
			}
		}

		if (!is_int($rawType) && !is_float($rawType) && !is_numeric($rawType)) {
			return RoomForwardingPolicy::ROOM_TYPE_UNKNOWN;
		}

		$rawInt = (int)$rawType;
		$className = get_class($room);
		$constantMap = [
			'TYPE_ONE_TO_ONE' => RoomForwardingPolicy::ROOM_TYPE_ONE_TO_ONE,
			'TYPE_GROUP' => RoomForwardingPolicy::ROOM_TYPE_GROUP,
			'TYPE_PUBLIC' => RoomForwardingPolicy::ROOM_TYPE_PUBLIC,
			'ROOM_TYPE_ONE_TO_ONE' => RoomForwardingPolicy::ROOM_TYPE_ONE_TO_ONE,
			'ROOM_TYPE_GROUP' => RoomForwardingPolicy::ROOM_TYPE_GROUP,
			'ROOM_TYPE_PUBLIC' => RoomForwardingPolicy::ROOM_TYPE_PUBLIC,
		];

		foreach ($constantMap as $constantName => $mappedType) {
			$fullName = $className . '::' . $constantName;
			if (!defined($fullName)) {
				continue;
			}

			$constantValue = constant($fullName);
			if (is_int($constantValue) && $rawInt === $constantValue) {
				return $mappedType;
			}
		}

		return RoomForwardingPolicy::ROOM_TYPE_UNKNOWN;
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function logDecision(string $decision, string $reason, array $context = []): void {
		$this->logger->debug('SYNC routing decision=' . $decision . ' reason=' . $reason, array_merge([
			'app' => 'ironclaw_talk_bridge',
		], $context));
	}
}
