<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\AppInfo;

use OCA\IronclawTalkBridge\Listener\ChatMessageSentListener;
use OCA\Talk\Events\ChatMessageSentEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'ironclaw_talk_bridge';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(ChatMessageSentEvent::class, ChatMessageSentListener::class);
	}

	public function boot(\OCP\AppFramework\Bootstrap\IBootContext $context): void {
	}
}
