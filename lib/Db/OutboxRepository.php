<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Db;

use OCP\IDBConnection;

class OutboxRepository {
	public function __construct(private IDBConnection $db) {
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function enqueue(array $payload): bool {
		$eventId = (string)($payload['eventId'] ?? '');
		if ($eventId === '' || $this->existsEvent($eventId)) {
			return false;
		}

		$now = time();
		$query = $this->db->getQueryBuilder();
		$query->insert('ic_talk_outbox')
			->setValue('event_id', $query->createNamedParameter($eventId))
			->setValue('room_token', $query->createNamedParameter((string)($payload['roomToken'] ?? '')))
			->setValue('message_id', $query->createNamedParameter((int)($payload['messageId'] ?? 0)))
			->setValue('payload', $query->createNamedParameter((string)json_encode($payload, JSON_THROW_ON_ERROR)))
			->setValue('status', $query->createNamedParameter('queued'))
			->setValue('attempts', $query->createNamedParameter(0))
			->setValue('next_attempt_at', $query->createNamedParameter($now))
			->setValue('last_error', $query->createNamedParameter(''))
			->setValue('created_at', $query->createNamedParameter($now))
			->setValue('updated_at', $query->createNamedParameter($now));
		$query->executeStatement();

		return true;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function fetchDue(int $limit): array {
		$query = $this->db->getQueryBuilder();
		$query->select('*')
			->from('ic_talk_outbox')
			->where($query->expr()->eq('status', $query->createNamedParameter('queued')))
			->andWhere($query->expr()->lte('next_attempt_at', $query->createNamedParameter(time())))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);

		$result = $query->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = $row;
		}
		$result->closeCursor();
		return $rows;
	}

	public function markDelivered(int $id): void {
		$now = time();
		$query = $this->db->getQueryBuilder();
		$query->update('ic_talk_outbox')
			->set('status', $query->createNamedParameter('delivered'))
			->set('delivered_at', $query->createNamedParameter($now))
			->set('updated_at', $query->createNamedParameter($now))
			->where($query->expr()->eq('id', $query->createNamedParameter($id)));
		$query->executeStatement();
	}

	public function markRetry(int $id, int $attempts, int $nextAttemptAt, string $error, bool $terminal): void {
		$status = $terminal ? 'failed' : 'queued';
		$query = $this->db->getQueryBuilder();
		$query->update('ic_talk_outbox')
			->set('status', $query->createNamedParameter($status))
			->set('attempts', $query->createNamedParameter($attempts))
			->set('next_attempt_at', $query->createNamedParameter($nextAttemptAt))
			->set('last_error', $query->createNamedParameter(mb_substr($error, 0, 4000)))
			->set('updated_at', $query->createNamedParameter(time()))
			->where($query->expr()->eq('id', $query->createNamedParameter($id)));
		$query->executeStatement();
	}

	/**
	 * @return array{queued:int, delivered:int, failed:int}
	 */
	public function statusCounts(): array {
		$query = $this->db->getQueryBuilder();
		$query->select('status')
			->addSelectAlias($query->createFunction('COUNT(*)'), 'count')
			->from('ic_talk_outbox')
			->groupBy('status');

		$result = $query->executeQuery();
		$counts = [
			'queued' => 0,
			'delivered' => 0,
			'failed' => 0,
		];
		while ($row = $result->fetch()) {
			$status = (string)($row['status'] ?? '');
			if (!array_key_exists($status, $counts)) {
				continue;
			}
			$counts[$status] = (int)($row['count'] ?? 0);
		}
		$result->closeCursor();

		return $counts;
	}

	private function existsEvent(string $eventId): bool {
		$query = $this->db->getQueryBuilder();
		$query->select('id')
			->from('ic_talk_outbox')
			->where($query->expr()->eq('event_id', $query->createNamedParameter($eventId)))
			->setMaxResults(1);
		$result = $query->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}
}
