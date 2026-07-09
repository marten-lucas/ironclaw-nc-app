<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Service;

use OCA\IronclawTalkBridge\Db\OutboxRepository;
use OCP\ILogger;

class OutboxDispatcher {
	private const MAX_ATTEMPTS = 20;

	public function __construct(
		private OutboxRepository $outbox,
		private IronclawClient $client,
		private AppConfig $config,
		private BridgeCounters $counters,
		private ILogger $logger,
	) {
	}

	public function dispatchDue(?int $limit = null): int {
		if (!$this->config->isEnabled() || !$this->config->isReadyForDelivery()) {
			return 0;
		}

		$rows = $this->outbox->fetchDue($limit ?? $this->config->getDispatchBatchSize());
		$processed = 0;

		foreach ($rows as $row) {
			$processed++;
			$this->dispatchRow($row);
		}

		return $processed;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function dispatchRow(array $row): void {
		$id = (int)$row['id'];
		$attempts = (int)$row['attempts'];
		$payload = json_decode((string)$row['payload'], true);

		if (!is_array($payload)) {
			$this->counters->increment(BridgeCounters::KEY_DELIVERY_FAILURES);
			$this->outbox->markRetry($id, $attempts + 1, time() + 3600, 'Invalid payload', true);
			return;
		}

		try {
			$statusCode = $this->client->deliver($payload);
			if ($statusCode >= 200 && $statusCode < 300) {
				$this->outbox->markDelivered($id);
				$this->counters->increment(BridgeCounters::KEY_EVENTS_DELIVERED);
				$this->logger->info('Ironclaw event delivered', [
					'app' => 'ironclaw_talk_bridge',
					'eventId' => $payload['eventId'] ?? null,
					'status' => $statusCode,
				]);
				return;
			}

			$this->scheduleRetry($id, $attempts, 'Ironclaw returned HTTP ' . $statusCode);
		} catch (\Throwable $e) {
			$this->scheduleRetry($id, $attempts, $e->getMessage());
		}
	}

	private function scheduleRetry(int $id, int $attempts, string $error): void {
		$this->counters->increment(BridgeCounters::KEY_DELIVERY_FAILURES);
		$newAttempts = $attempts + 1;
		$terminal = $newAttempts >= self::MAX_ATTEMPTS;
		$delay = min(1800, 5 * (2 ** min(10, $newAttempts)));
		$nextAttemptAt = time() + $delay;
		$this->outbox->markRetry($id, $newAttempts, $nextAttemptAt, $error, $terminal);

		$this->logger->warning('Ironclaw event delivery failed', [
			'app' => 'ironclaw_talk_bridge',
			'attempts' => $newAttempts,
			'terminal' => $terminal,
			'error' => $error,
		]);
	}
}
