<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Service;

use OCP\DB\Exception as DbException;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class TalkRoomMetadataResolver {
	private const CACHE_TTL_SECONDS = 3600;
	private const MAX_ATTEMPTS = 3;
	private const RETRY_DELAY_MICROSECONDS = 200000;

	private ICache $cache;

	public function __construct(
		ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createLocal('ironclaw_talk_bridge_room_metadata');
	}

	/**
	 * @return array{roomType:string,botPresent:bool,source:string,attempts:int}
	 */
	public function resolve(object $room, string $botUserId): array {
		$roomToken = $this->roomToken($room);
		$cacheKey = $this->cacheKey($roomToken);
		$attempt = 0;
		$lastException = null;

		while ($attempt < self::MAX_ATTEMPTS) {
			$attempt++;
			try {
				$roomType = $this->normalizeRoomType($this->fetchRawRoomType($room), $room);
				$botPresent = $this->fetchBotPresence($room, $botUserId);

				$data = [
					'roomType' => $roomType,
					'botPresent' => $botPresent,
					'source' => $attempt === 1 ? 'live' : 'live_retry',
					'attempts' => $attempt,
				];
				$this->cache->set($cacheKey, [
					'roomType' => $roomType,
					'botPresent' => $botPresent,
					'cachedAt' => time(),
				], self::CACHE_TTL_SECONDS);
				$this->logger->debug('Room metadata cache updated roomToken=' . $roomToken
					. ' source=' . $data['source']
					. ' roomType=' . $roomType
					. ' botPresent=' . ($botPresent ? 'true' : 'false'), [
					'app' => 'ironclaw_talk_bridge',
					'roomToken' => $roomToken,
					'source' => $data['source'],
					'roomType' => $roomType,
					'botPresent' => $botPresent,
				]);

				return $data;
			} catch (\Exception $e) {
				$lastException = $e;
				if (!$this->isDirtyReadException($e)) {
					throw $e;
				}

				$cached = $this->readCache($cacheKey);
				if ($cached !== null) {
					$cached['source'] = 'cache_fallback';
					$cached['attempts'] = $attempt;
					$this->logger->debug('Room metadata cache fallback hit roomToken=' . $roomToken
						. ' roomType=' . (string)($cached['roomType'] ?? RoomForwardingPolicy::ROOM_TYPE_UNKNOWN)
						. ' botPresent=' . ((bool)($cached['botPresent'] ?? false) ? 'true' : 'false'), [
						'app' => 'ironclaw_talk_bridge',
						'roomToken' => $roomToken,
						'roomType' => (string)($cached['roomType'] ?? RoomForwardingPolicy::ROOM_TYPE_UNKNOWN),
						'botPresent' => (bool)($cached['botPresent'] ?? false),
						'attempt' => $attempt,
					]);
					return $cached;
				}

				if ($attempt >= self::MAX_ATTEMPTS) {
					break;
				}

				usleep(self::RETRY_DELAY_MICROSECONDS);
			}
		}

		if ($lastException instanceof \Exception) {
			$this->logger->warning('Room metadata resolution failed after retries', [
				'app' => 'ironclaw_talk_bridge',
				'roomToken' => $roomToken,
				'error' => $lastException->getMessage(),
			]);
			throw $lastException;
		}

		return [
			'roomType' => RoomForwardingPolicy::ROOM_TYPE_UNKNOWN,
			'botPresent' => false,
			'source' => 'unknown',
			'attempts' => $attempt,
		];
	}

	private function fetchRawRoomType(object $room): mixed {
		if (method_exists($room, 'isOneToOne')) {
			try {
				if ((bool)$room->isOneToOne()) {
					return RoomForwardingPolicy::ROOM_TYPE_ONE_TO_ONE;
				}
			} catch (\Throwable) {
			}
		}

		foreach (['getType', 'getConversationType', 'getRoomType'] as $method) {
			if (!method_exists($room, $method)) {
				continue;
			}

			return $room->{$method}();
		}

		return null;
	}

	private function fetchBotPresence(object $room, string $botUserId): bool {
		$botUserId = trim($botUserId);
		if ($botUserId === '') {
			return false;
		}

		if (method_exists($room, 'hasParticipant')) {
			foreach ([
				['users', $botUserId],
				['user', $botUserId],
				[$botUserId],
			] as $args) {
				try {
					if ((bool)$room->hasParticipant(...$args)) {
						return true;
					}
				} catch (\ArgumentCountError | \TypeError) {
					continue;
				}
			}
		}

		if (method_exists($room, 'getParticipantByActor')) {
			foreach (['users', 'user'] as $actorType) {
				try {
					$value = $room->getParticipantByActor($actorType, $botUserId);
					if ($value !== null) {
						return true;
					}
				} catch (\ArgumentCountError | \TypeError) {
					continue;
				}
			}
		}

		$roomActorKeys = $this->collectRoomActorKeys($room);
		if ($roomActorKeys !== []) {
			foreach (['users', 'user'] as $actorType) {
				if (in_array($actorType . ':' . $botUserId, $roomActorKeys, true)) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @return array{roomType:string,botPresent:bool}|null
	 */
	private function readCache(string $cacheKey): ?array {
		$cached = $this->cache->get($cacheKey);
		if (!is_array($cached)) {
			return null;
		}

		$roomType = isset($cached['roomType']) ? trim((string)$cached['roomType']) : '';
		if ($roomType === '') {
			return null;
		}

		return [
			'roomType' => $roomType,
			'botPresent' => (bool)($cached['botPresent'] ?? false),
		];
	}

	private function cacheKey(string $roomToken): string {
		if ($roomToken === '') {
			return 'ironclaw_room_unknown';
		}

		return 'ironclaw_room_' . $roomToken;
	}

	private function roomToken(object $room): string {
		if (!method_exists($room, 'getToken')) {
			return '';
		}

		return trim((string)$room->getToken());
	}

	private function isDirtyReadException(\Throwable $e): bool {
		if ($e instanceof DbException) {
			return true;
		}

		$message = strtolower($e->getMessage());
		return str_contains($message, 'dirty')
			|| str_contains($message, 'database is locked')
			|| str_contains($message, 'serialization failure')
			|| str_contains($message, 'deadlock');
	}

	private function normalizeRoomType(mixed $rawType, object $room): string {
		if (is_string($rawType)) {
			$normalized = strtolower(trim($rawType));
			if (in_array($normalized, ['direct', 'one_to_one', 'one-to-one', 'one2one', 'single'], true)) {
				return RoomForwardingPolicy::ROOM_TYPE_ONE_TO_ONE;
			}
			if (in_array($normalized, ['group'], true)) {
				return RoomForwardingPolicy::ROOM_TYPE_GROUP;
			}
			if (in_array($normalized, ['public'], true)) {
				return RoomForwardingPolicy::ROOM_TYPE_PUBLIC;
			}
		}

		if (is_int($rawType)) {
			$constantMap = $this->roomTypeConstantMap($room);
			if (isset($constantMap[$rawType])) {
				return $constantMap[$rawType];
			}

			if ($rawType === 1) {
				return RoomForwardingPolicy::ROOM_TYPE_ONE_TO_ONE;
			}
			if ($rawType === 2) {
				return RoomForwardingPolicy::ROOM_TYPE_GROUP;
			}
			if ($rawType === 3) {
				return RoomForwardingPolicy::ROOM_TYPE_PUBLIC;
			}
		}

		return RoomForwardingPolicy::ROOM_TYPE_UNKNOWN;
	}

	/**
	 * @return array<int,string>
	 */
	private function roomTypeConstantMap(object $room): array {
		try {
			$reflection = new \ReflectionClass($room);
			$constants = $reflection->getConstants();
		} catch (\ReflectionException) {
			return [];
		}

		$map = [];
		$aliases = [
			'TYPE_ONE_TO_ONE' => RoomForwardingPolicy::ROOM_TYPE_ONE_TO_ONE,
			'TYPE_GROUP' => RoomForwardingPolicy::ROOM_TYPE_GROUP,
			'TYPE_PUBLIC' => RoomForwardingPolicy::ROOM_TYPE_PUBLIC,
		];

		foreach ($aliases as $constantName => $normalizedType) {
			$value = $constants[$constantName] ?? null;
			if (is_int($value)) {
				$map[$value] = $normalizedType;
			}
		}

		return $map;
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
			} catch (\Throwable) {
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
