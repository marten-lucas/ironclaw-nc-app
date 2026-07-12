<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Command;

use OCA\IronclawTalkBridge\Service\ParticipantSnapshotRefresher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RefreshParticipantSnapshots extends Command {
	public function __construct(private ParticipantSnapshotRefresher $refresher) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('ironclaw-talk-bridge:refresh-participants')
			->setDescription('Refresh cached Talk room participants snapshots asynchronously')
			->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Max rooms to refresh', '200');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$limit = max(1, (int)$input->getOption('limit'));
		$updated = $this->refresher->refresh($limit);
		$output->writeln('Updated snapshots: ' . $updated);
		return self::SUCCESS;
	}
}