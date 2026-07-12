<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Tests\unit\Service;

use OCA\IronclawTalkBridge\Service\RoomForwardingPolicy;
use PHPUnit\Framework\TestCase;

class RoomForwardingPolicyTest extends TestCase {
	public function testAllowsWithoutMentionForTwoParticipantRoom(): void {
		$policy = new RoomForwardingPolicy();

		$result = $policy->decide(
			'ki_assistent',
			['users:ki_assistent', 'users:alice'],
			2,
			true
		);

		self::assertFalse($result['requiresMention']);
		self::assertSame(1, $result['otherParticipantCount']);
		self::assertSame('two_participant_room', $result['matchedBy']);
	}

	public function testRequiresMentionForRoomsWithTwoOrMoreOtherParticipants(): void {
		$policy = new RoomForwardingPolicy();

		$result = $policy->decide(
			'ki_assistent',
			['users:ki_assistent', 'users:alice', 'users:bob'],
			3,
			true
		);

		self::assertTrue($result['requiresMention']);
		self::assertSame(2, $result['otherParticipantCount']);
		self::assertSame('mention', $result['matchedBy']);
	}

	public function testUsesParticipantCountFallbackWhenActorSnapshotUnavailable(): void {
		$policy = new RoomForwardingPolicy();

		$result = $policy->decide('ki_assistent', [], 2, true);

		self::assertFalse($result['requiresMention']);
		self::assertSame(1, $result['otherParticipantCount']);
	}
}