<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Tests\unit\Listener;

use OCA\IronclawTalkBridge\Listener\ReactionEventListener;
use OCA\IronclawTalkBridge\Service\AppConfig;
use OCA\IronclawTalkBridge\Service\BridgeCounters;
use OCA\IronclawTalkBridge\Service\IronclawClient;
use OCA\IronclawTalkBridge\Service\RoomScopeService;
use OCA\IronclawTalkBridge\Service\TalkEventMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReactionEventListenerTest extends TestCase {
	public function testBuildReactionSignalMapsSemantics(): void {
		$config = $this->createMock(AppConfig::class);
		$config->method('isReactionApprovalActor')->willReturnMap([
			['users', 'alice', true],
			['users', 'bob', false],
		]);

		$listener = new ReactionEventListener(
			$config,
			$this->createMock(TalkEventMapper::class),
			$this->createMock(RoomScopeService::class),
			$this->createMock(IronclawClient::class),
			$this->createMock(BridgeCounters::class),
			$this->createMock(LoggerInterface::class),
		);

		$method = new \ReflectionMethod($listener, 'buildReactionSignal');

		$thumbDown = $method->invoke($listener, '👎', 'ReactionAdded', 'users', 'alice');
		self::assertSame('needs_rephrase', $thumbDown['semantic']);
		self::assertSame('offer_rephrase', $thumbDown['uiAction']);

		$approved = $method->invoke($listener, '✅', 'ReactionAdded', 'users', 'alice');
		self::assertTrue($approved['requiresAuthorization']);
		self::assertTrue($approved['isAuthorized']);
		self::assertSame('human_approved', $approved['semantic']);

		$rejectedNotAuthorized = $method->invoke($listener, '❌', 'ReactionAdded', 'users', 'bob');
		self::assertTrue($rejectedNotAuthorized['requiresAuthorization']);
		self::assertFalse($rejectedNotAuthorized['isAuthorized']);
		self::assertSame('human_not_approved', $rejectedNotAuthorized['semantic']);

		$removed = $method->invoke($listener, '👍', 'ReactionRemoved', 'users', 'alice');
		self::assertSame('reaction_removed', $removed['semantic']);
	}
}
