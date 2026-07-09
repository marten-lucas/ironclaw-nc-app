<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Command;

use OCA\IronclawTalkBridge\Service\OutboxDispatcher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DispatchQueuedEvents extends Command {
	public function __construct(private OutboxDispatcher $dispatcher) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('ironclaw-talk-bridge:dispatch')
			->setDescription('Dispatch due queued Talk events to Ironclaw')
			->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Max number of queued events', '50');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$limit = max(1, (int)$input->getOption('limit'));
		$count = $this->dispatcher->dispatchDue($limit);
		$output->writeln('Processed rows: ' . $count);
		return self::SUCCESS;
	}
}
