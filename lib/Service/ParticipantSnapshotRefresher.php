<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Service;

use OCA\IronclawTalkBridge\Db\RoomParticipantsSnapshotRepository;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class ParticipantSnapshotRefresher {
	public function __construct(
		private IDBConnection $db,
		private TalkRoomParticipantsResolver $resolver,
		private RoomParticipantsSnapshotRepository $snapshots,
		private LoggerInterface $logger,
	) {
	}

	public function refresh(int $limit = 200): int {
		$tokens = $this->fetchRoomTokens(max(1, $limit));
		$updated = 0;
		$now = time();

		foreach ($tokens as $roomToken) {
			try {
				$data = $this->resolver->resolveByRoomToken($roomToken);
				$this->snapshots->upsert(
					$roomToken,
					is_array($data['participantActors'] ?? null) ? $data['participantActors'] : [],
					(int)($data['participantCount'] ?? 0),
					$now
				);
				$updated++;
			} catch (\Throwable $e) {
				$this->logger->debug('Participant snapshot refresh failed for room', [
					'app' => 'ironclaw_talk_bridge',
					'roomToken' => $roomToken,
					'error' => $e->getMessage(),
				]);
			}
		}

		return $updated;
	}

	/**
	 * @return array<int,string>
	 */
	private function fetchRoomTokens(int $limit): array {
		$query = $this->db->getQueryBuilder();
		$query->select('token')
			->from('talk_rooms')
			->orderBy('id', 'DESC')
			->setMaxResults($limit);

		$result = $query->executeQuery();
		$tokens = [];
		if (method_exists($result, 'fetchAllAssociative')) {
			$rows = $result->fetchAllAssociative();
			foreach ($rows as $row) {
				$token = isset($row['token']) ? trim((string)$row['token']) : '';
				if ($token !== '') {
					$tokens[] = $token;
				}
			}
		} else {
			while (($row = method_exists($result, 'fetchAssociative') ? $result->fetchAssociative() : $result->fetch()) !== false) {
				if (!is_array($row)) {
					continue;
				}
				$token = isset($row['token']) ? trim((string)$row['token']) : '';
				if ($token !== '') {
					$tokens[] = $token;
				}
			}
		}
		if (method_exists($result, 'closeCursor')) {
			$result->closeCursor();
		}

		return array_values(array_unique($tokens));
	}
}