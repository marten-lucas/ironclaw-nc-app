<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Command;

use OCA\IronclawTalkBridge\Db\OutboxRepository;
use OCA\IronclawTalkBridge\Service\BridgeCounters;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShowBridgeMetrics extends Command {
	public function __construct(
		private OutboxRepository $outbox,
		private BridgeCounters $counters,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('ironclaw-talk-bridge:metrics')
			->setDescription('Show structured counters and outbox state for Ironclaw Talk Bridge');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$counts = $this->outbox->statusCounts();
		$counters = $this->counters->snapshot();

		$output->writeln('<info>Ironclaw Talk Bridge Metrics</info>');
		$output->writeln(sprintf('outbox.queued=%d', $counts['queued']));
		$output->writeln(sprintf('outbox.filtered=%d', $counts['filtered']));
		$output->writeln(sprintf('outbox.delivered=%d', $counts['delivered']));
		$output->writeln(sprintf('outbox.failed=%d', $counts['failed']));
		foreach ($counters as $key => $value) {
			$output->writeln(sprintf('counter.%s=%d', $key, $value));
		}

		return 0;
	}
}
