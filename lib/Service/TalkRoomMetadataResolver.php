<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Service;

use OCP\DB\Exception as DbException;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class TalkRoomMetadataResolver {
	private const CACHE_TTL_SECONDS = 3600;
	private const MAX_ATTEMPTS = 3;
	private const RETRY_DELAY_MICROSECONDS = 200000;

	private ICache $cache;

	public function __construct(
		ICacheFactory $cacheFactory,
		private IDBConnection $db,
		private AppConfig $config,
		private LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createLocal('ironclaw_talk_bridge_room_metadata');
	}

	/**
	 * @return array{roomType:string,botPresent:bool,participantCount:int|null,source:string,attempts:int}
	 */
	public function resolve(object $room, string $botUserId): array {
		$roomToken = $this->roomToken($room);
		$cacheKey = $this->cacheKey($roomToken);
		$attempt = 0;
		$lastException = null;

		while ($attempt < self::MAX_ATTEMPTS) {
			$attempt++;
			try {
				$dbData = $this->fetchRoomDataFromDb($room, $botUserId);
				$roomType = $dbData['roomType'];
				$botPresent = $dbData['botPresent'];
				$participantCount = $dbData['participantCount'];

				$data = [
					'roomType' => $roomType,
					'botPresent' => $botPresent,
					'participantCount' => $participantCount,
					'source' => $attempt === 1 ? 'live' : 'live_retry',
					'attempts' => $attempt,
				];
				$this->cache->set($cacheKey, [
					'roomType' => $roomType,
					'botPresent' => $botPresent,
					'participantCount' => $participantCount,
					'cachedAt' => time(),
				], self::CACHE_TTL_SECONDS);
				$this->logger->debug('Room metadata cache updated roomToken=' . $roomToken
					. ' source=' . $data['source']
					. ' roomType=' . $roomType
					. ' fakeUserInRoom=' . ($botPresent ? 'true' : 'false'), [
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
						. ' fakeUserInRoom=' . ((bool)($cached['botPresent'] ?? false) ? 'true' : 'false'), [
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
			'participantCount' => null,
			'source' => 'unknown',
			'attempts' => $attempt,
		];
	}

	/**
	 * @return array{roomType:string,botPresent:bool,participantCount:int}
	 */
	private function fetchRoomDataFromDb(object $room, string $botUserId): array {
		$roomToken = $this->roomToken($room);
		if ($roomToken === '') {
			throw new \RuntimeException('Room token is missing');
		}

		$botUserId = trim($botUserId);
		$mentionDisplayName = trim($this->config->getMentionDisplayName());

		$query = $this->db->getQueryBuilder();
		$query->select('a.actor_type', 'a.actor_id')
			->from('talk_attendees', 'a')
			->innerJoin('a', 'talk_rooms', 'r', $query->expr()->eq('a.room_id', 'r.id'))
			->where($query->expr()->eq('r.token', $query->createNamedParameter($roomToken)));

		$result = $query->executeQuery();
		$participantCount = 0;
		$botPresent = false;
		$sample = [];

		while (($row = method_exists($result, 'fetchAssociative') ? $result->fetchAssociative() : $result->fetch()) !== false) {
			if (!is_array($row)) {
				continue;
			}

			$actorType = isset($row['actor_type']) ? trim((string)$row['actor_type']) : '';
			$actorId = isset($row['actor_id']) ? trim((string)$row['actor_id']) : '';
			if ($actorType === '' || $actorId === '') {
				continue;
			}

			$participantCount++;
			if (count($sample) < 10) {
				$sample[] = ['actorType' => $actorType, 'actorId' => $actorId];
			}

			if ($botUserId !== '' && in_array(strtolower($actorType), ['users', 'user'], true)
				&& strcasecmp($actorId, $botUserId) === 0) {
				$botPresent = true;
			}

			if (!$botPresent && $mentionDisplayName !== '' && strcasecmp($actorId, $mentionDisplayName) === 0) {
				$botPresent = true;
			}
		}

		if (method_exists($result, 'closeCursor')) {
			$result->closeCursor();
		}

		$roomType = $participantCount <= 2
			? RoomForwardingPolicy::ROOM_TYPE_ONE_TO_ONE
			: RoomForwardingPolicy::ROOM_TYPE_GROUP;

		if (!$botPresent) {
			$this->logger->debug('Configured user presence unresolved from DB attendees', [
				'app' => 'ironclaw_talk_bridge',
				'roomToken' => $roomToken,
				'fakeUserId' => $botUserId,
				'mentionDisplayName' => $mentionDisplayName,
				'participantCount' => $participantCount,
				'participantSample' => $sample,
			]);
		}

		return [
			'roomType' => $roomType,
			'botPresent' => $botPresent,
			'participantCount' => $participantCount,
		];
	}

	/**
	 * @return array{roomType:string,botPresent:bool,participantCount:int|null}|null
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
			'participantCount' => isset($cached['participantCount']) ? (int)$cached['participantCount'] : null,
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

}
