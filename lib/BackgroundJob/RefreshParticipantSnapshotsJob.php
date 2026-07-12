<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\BackgroundJob;

use OCA\IronclawTalkBridge\Service\ParticipantSnapshotRefresher;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class RefreshParticipantSnapshotsJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ParticipantSnapshotRefresher $refresher,
	) {
		parent::__construct($time);
		$this->setInterval(60);
	}

	/**
	 * @param array<string,mixed> $argument
	 */
	protected function run($argument): void {
		$this->refresher->refresh(200);
	}
}