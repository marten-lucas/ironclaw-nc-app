<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Tests\Unit\Service;

use OCA\IronclawTalkBridge\Service\AppConfig;
use OCA\IronclawTalkBridge\Service\TalkMembershipResolver;
use OCP\ILogger;
use PHPUnit\Framework\TestCase;

class TalkMembershipResolverTest extends TestCase {
	public function testAcceptsMatchingParticipantFromEvent(): void {
		$resolver = new TalkMembershipResolver($this->buildConfig(true), $this->buildLogger());
		$event = new FakeChatMessageSentEvent(new FakeParticipant('users', 'alice'));

		self::assertTrue($resolver->isEventActorRoomMember($event, 'users', 'alice'));
	}

	public function testRejectsMismatchingParticipantFromEvent(): void {
		$resolver = new TalkMembershipResolver($this->buildConfig(true), $this->buildLogger());
		$event = new FakeChatMessageSentEvent(new FakeParticipant('users', 'alice'));

		self::assertFalse($resolver->isEventActorRoomMember($event, 'users', 'bob'));
	}

	public function testBypassesResolverWhenStrictModeDisabled(): void {
		$resolver = new TalkMembershipResolver($this->buildConfig(false), $this->buildLogger());
		$event = new FakeChatMessageSentEvent(null);

		self::assertTrue($resolver->isEventActorRoomMember($event, 'users', 'alice'));
	}

	private function buildConfig(bool $strict): AppConfig {
		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default) use ($strict): string {
				if ($key === 'strict_membership_resolver') {
					return $strict ? '1' : '0';
				}
				return $default;
			}
		);
		return new AppConfig($config);
	}

	private function buildLogger(): ILogger {
		return $this->createMock(ILogger::class);
	}
}

class FakeChatMessageSentEvent {
	public function __construct(private ?FakeParticipant $participant) {
	}

	public function getParticipant(): ?FakeParticipant {
		return $this->participant;
	}

	public function getRoom(): object {
		return new class {
		};
	}
}

class FakeParticipant {
	public function __construct(private string $actorType, private string $actorId) {
	}

	public function getAttendee(): object {
		$actorType = $this->actorType;
		$actorId = $this->actorId;
		return new class ($actorType, $actorId) {
			public function __construct(private string $actorType, private string $actorId) {
			}

			public function getActorType(): string {
				return $this->actorType;
			}

			public function getActorId(): string {
				return $this->actorId;
			}
		};
	}
}
