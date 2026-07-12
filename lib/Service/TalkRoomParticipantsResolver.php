<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class TalkRoomParticipantsResolver {
	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{participantActors:array<int,string>,participantCount:int,dataSource:string}
	 */
	public function resolveByRoomToken(string $roomToken): array {
		$roomToken = trim($roomToken);
		if ($roomToken === '') {
			return [
				'participantActors' => [],
				'participantCount' => 0,
				'dataSource' => 'db_missing_token',
			];
		}

		$query = $this->db->getQueryBuilder();
		$query->select('a.actor_type', 'a.actor_id')
			->from('talk_attendees', 'a')
			->innerJoin('a', 'talk_rooms', 'r', $query->expr()->eq('a.room_id', 'r.id'))
			->where($query->expr()->eq('r.token', $query->createNamedParameter($roomToken)));

		try {
			$result = $query->executeQuery();
			$actors = [];
			foreach ($this->fetchRows($result) as $row) {
				$actorType = isset($row['actor_type']) ? trim((string)$row['actor_type']) : '';
				$actorId = isset($row['actor_id']) ? trim((string)$row['actor_id']) : '';
				if ($actorType === '' || $actorId === '') {
					continue;
				}
				$actors[$actorType . ':' . $actorId] = true;
			}
			if (method_exists($result, 'closeCursor')) {
				$result->closeCursor();
			}

			$participantActors = array_values(array_keys($actors));
			return [
				'participantActors' => $participantActors,
				'participantCount' => count($participantActors),
				'dataSource' => 'db_talk_attendees',
			];
		} catch (\Throwable $e) {
			$this->logger->debug('Talk participants DB lookup failed', [
				'app' => 'ironclaw_talk_bridge',
				'roomToken' => $roomToken,
				'error' => $e->getMessage(),
			]);
			return [
				'participantActors' => [],
				'participantCount' => 0,
				'dataSource' => 'db_lookup_failed',
			];
		}
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function fetchRows(object $result): array {
		if (method_exists($result, 'fetchAllAssociative')) {
			$rows = $result->fetchAllAssociative();
			return is_array($rows) ? $rows : [];
		}

		if (method_exists($result, 'fetchAll')) {
			$rows = $result->fetchAll();
			return is_array($rows) ? $rows : [];
		}

		$rows = [];
		if (method_exists($result, 'fetchAssociative')) {
			while (($row = $result->fetchAssociative()) !== false) {
				if (is_array($row)) {
					$rows[] = $row;
				}
			}
			return $rows;
		}

		if (method_exists($result, 'fetch')) {
			while (($row = $result->fetch()) !== false) {
				if (is_array($row)) {
					$rows[] = $row;
				}
			}
		}

		return $rows;
	}
}