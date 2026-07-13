<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Command;

use OCA\IronclawTalkBridge\Service\BridgeCounters;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShowBridgeMetrics extends Command {
	public function __construct(private BridgeCounters $counters) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('ironclaw-talk-bridge:metrics')
			->setDescription('Show structured counters for Ironclaw Talk Bridge (synchronous routing mode)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$counters = $this->counters->snapshot();

		$output->writeln('<info>Ironclaw Talk Bridge Metrics</info>');
		$output->writeln('mode=synchronous');
		foreach ($counters as $key => $value) {
			$output->writeln(sprintf('counter.%s=%d', $key, $value));
		}

		return 0;
	}
}
