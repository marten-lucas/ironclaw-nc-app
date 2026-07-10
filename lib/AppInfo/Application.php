<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\AppInfo;

use OCA\IronclawTalkBridge\BackgroundJob\RetryQueuedEventsJob;
use OCA\IronclawTalkBridge\Listener\ChatMessageSentListener;
use OCA\Talk\Events\ChatMessageSentEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\ILogger;

class Application extends App implements IBootstrap {
	public const APP_ID = 'ironclaw_talk_bridge';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(ChatMessageSentEvent::class, ChatMessageSentListener::class);
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(function (IJobList $jobList, ILogger $logger): void {
			try {
				$jobList->add(RetryQueuedEventsJob::class);
			} catch (\Throwable $e) {
				$logger->error('Failed to register RetryQueuedEventsJob', [
					'app' => self::APP_ID,
					'exception' => $e,
				]);
			}
		});
	}
}
