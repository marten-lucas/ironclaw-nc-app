<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Db;

use OCP\IDBConnection;

class RoomParticipantsSnapshotRepository {
	public function __construct(private IDBConnection $db) {
	}

	/**
	 * @return array{participantActors:array<int,string>,participantCount:int,refreshedAt:int}|null
	 */
	public function getByRoomToken(string $roomToken): ?array {
		$query = $this->db->getQueryBuilder();
		$query->select('participant_count', 'participant_actors', 'refreshed_at')
			->from('ic_talk_room_participants')
			->where($query->expr()->eq('room_token', $query->createNamedParameter($roomToken)))
			->setMaxResults(1);

		$result = $query->executeQuery();
		$row = method_exists($result, 'fetchAssociative') ? $result->fetchAssociative() : $result->fetch();
		if (method_exists($result, 'closeCursor')) {
			$result->closeCursor();
		}

		if (!is_array($row)) {
			return null;
		}

		$actorsRaw = (string)($row['participant_actors'] ?? '[]');
		$actors = json_decode($actorsRaw, true);
		if (!is_array($actors)) {
			$actors = [];
		}

		$participantActors = [];
		foreach ($actors as $actor) {
			if (is_string($actor) && $actor !== '') {
				$participantActors[] = $actor;
			}
		}

		return [
			'participantActors' => array_values(array_unique($participantActors)),
			'participantCount' => (int)($row['participant_count'] ?? 0),
			'refreshedAt' => (int)($row['refreshed_at'] ?? 0),
		];
	}

	/**
	 * @param array<int,string> $participantActors
	 */
	public function upsert(string $roomToken, array $participantActors, int $participantCount, int $refreshedAt): void {
		$payload = json_encode(array_values(array_unique($participantActors)), JSON_THROW_ON_ERROR);

		$update = $this->db->getQueryBuilder();
		$update->update('ic_talk_room_participants')
			->set('participant_count', $update->createNamedParameter($participantCount))
			->set('participant_actors', $update->createNamedParameter($payload))
			->set('refreshed_at', $update->createNamedParameter($refreshedAt))
			->where($update->expr()->eq('room_token', $update->createNamedParameter($roomToken)));

		$updated = $update->executeStatement();
		if ($updated > 0) {
			return;
		}

		$insert = $this->db->getQueryBuilder();
		$insert->insert('ic_talk_room_participants')
			->setValue('room_token', $insert->createNamedParameter($roomToken))
			->setValue('participant_count', $insert->createNamedParameter($participantCount))
			->setValue('participant_actors', $insert->createNamedParameter($payload))
			->setValue('refreshed_at', $insert->createNamedParameter($refreshedAt));
		$insert->executeStatement();
	}
}