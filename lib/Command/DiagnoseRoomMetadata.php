<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Command;

use OCA\IronclawTalkBridge\Service\AppConfig;
use OCA\IronclawTalkBridge\Service\TalkRoomMetadataResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DiagnoseRoomMetadata extends Command {
	public function __construct(
		private TalkRoomMetadataResolver $resolver,
		private AppConfig $config,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('ironclaw-talk-bridge:diagnose-room')
			->setDescription('Resolve room metadata for a room token (type, fake-user presence, participants).')
			->addOption('room-token', null, InputOption::VALUE_REQUIRED, 'Talk room token to inspect')
			->addOption('fake-user-id', null, InputOption::VALUE_OPTIONAL, 'Override configured fake user id');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$roomToken = trim((string)$input->getOption('room-token'));
		if ($roomToken === '') {
			$output->writeln('<error>Missing required option --room-token</error>');
			return self::INVALID;
		}

		$fakeUserId = trim((string)$input->getOption('fake-user-id'));
		if ($fakeUserId === '') {
			$fakeUserId = $this->config->getFakeUserId();
		}

		$room = new class ($roomToken) {
			public function __construct(private string $roomToken) {
			}

			public function getToken(): string {
				return $this->roomToken;
			}
		};

		$metadata = $this->resolver->resolve($room, $fakeUserId);

		$output->writeln('<info>Ironclaw Talk Bridge Room Diagnosis</info>');
		$output->writeln('roomToken=' . $roomToken);
		$output->writeln('fakeUserId=' . ($fakeUserId !== '' ? $fakeUserId : '(empty)'));
		$output->writeln('roomType=' . (string)($metadata['roomType'] ?? 'unknown'));
		$output->writeln('fakeUserInRoom=' . ((bool)($metadata['botPresent'] ?? false) ? 'true' : 'false'));
		$output->writeln('participantCount=' . (($metadata['participantCount'] ?? null) === null ? 'null' : (string)$metadata['participantCount']));
		$output->writeln('source=' . (string)($metadata['source'] ?? 'unknown'));
		$output->writeln('attempts=' . (string)($metadata['attempts'] ?? 0));

		return self::SUCCESS;
	}
}
