<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\BackgroundJob;

use OCA\IronclawTalkBridge\Service\OutboxDispatcher;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class RetryQueuedEventsJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private OutboxDispatcher $dispatcher,
	) {
		parent::__construct($time);
		$this->setInterval(30);
	}

	/**
	 * @param array<string, mixed> $argument
	 */
	protected function run($argument): void {
		$this->dispatcher->dispatchDue();
	}
}
