<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Tests\unit\Service;

use OCA\IronclawTalkBridge\Service\RoomForwardingPolicy;
use PHPUnit\Framework\TestCase;

class RoomForwardingPolicyTest extends TestCase {
	public function testRequiresMentionForOneToOneRooms(): void {
		$policy = new RoomForwardingPolicy();

		$result = $policy->decide(RoomForwardingPolicy::ROOM_TYPE_ONE_TO_ONE);

		self::assertTrue($result['requiresMention']);
		self::assertSame('mention', $result['matchedBy']);
	}

	public function testRequiresMentionForGroupRooms(): void {
		$policy = new RoomForwardingPolicy();

		$result = $policy->decide(RoomForwardingPolicy::ROOM_TYPE_GROUP);

		self::assertTrue($result['requiresMention']);
		self::assertSame('mention', $result['matchedBy']);
	}

	public function testRequiresMentionForPublicRooms(): void {
		$policy = new RoomForwardingPolicy();

		$result = $policy->decide(RoomForwardingPolicy::ROOM_TYPE_PUBLIC);

		self::assertTrue($result['requiresMention']);
	}

	public function testRequiresMentionForUnknownRoomTypes(): void {
		$policy = new RoomForwardingPolicy();

		$result = $policy->decide('custom-room-type');

		self::assertTrue($result['requiresMention']);
	}
}