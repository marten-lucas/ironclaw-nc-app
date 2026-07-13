<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Service;

use OCA\IronclawTalkBridge\AppInfo\Application;
use OCP\IConfig;

class BridgeCounters {
	public const KEY_EVENTS_RECEIVED = 'metric_events_received';
	public const KEY_EVENTS_ALLOWED = 'metric_events_allowed';
	public const KEY_EVENTS_DENIED = 'metric_events_denied';
	public const KEY_EVENTS_DELIVERED = 'metric_events_delivered';
	public const KEY_DELIVERY_FAILURES = 'metric_delivery_failures';
	public const KEY_MENTION_MISSES = 'metric_mention_misses';

	public function __construct(private IConfig $config) {
	}

	public function increment(string $key, int $delta = 1): void {
		$current = $this->get($key);
		$next = $current + max(0, $delta);
		$this->config->setAppValue(Application::APP_ID, $key, (string)$next);
	}

	/**
	 * @return array<string, int>
	 */
	public function snapshot(): array {
		return [
			self::KEY_EVENTS_RECEIVED => $this->get(self::KEY_EVENTS_RECEIVED),
			self::KEY_EVENTS_ALLOWED => $this->get(self::KEY_EVENTS_ALLOWED),
			self::KEY_EVENTS_DENIED => $this->get(self::KEY_EVENTS_DENIED),
			self::KEY_EVENTS_DELIVERED => $this->get(self::KEY_EVENTS_DELIVERED),
			self::KEY_DELIVERY_FAILURES => $this->get(self::KEY_DELIVERY_FAILURES),
			self::KEY_MENTION_MISSES => $this->get(self::KEY_MENTION_MISSES),
		];
	}

	private function get(string $key): int {
		return max(0, (int)$this->config->getAppValue(Application::APP_ID, $key, '0'));
	}
}
