<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\BackgroundJob;

use OCA\IronclawTalkBridge\Service\OutboxDispatcher;
use OCP\BackgroundJob\TimedJob;

class RetryQueuedEventsJob extends TimedJob {
	public function __construct(private OutboxDispatcher $dispatcher) {
		parent::__construct();
		$this->setInterval(30);
	}

	/**
	 * @param array<string, mixed> $argument
	 */
	protected function run($argument): void {
		$this->dispatcher->dispatchDue();
	}
}
