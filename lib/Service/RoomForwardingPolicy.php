<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Service;

class RoomForwardingPolicy {
	public const ROOM_TYPE_ONE_TO_ONE = 'one_to_one';
	public const ROOM_TYPE_GROUP = 'group';
	public const ROOM_TYPE_PUBLIC = 'public';
	public const ROOM_TYPE_UNKNOWN = 'unknown';

	/**
	 * @return array{requiresMention:bool,matchedBy:string}
	 */
	public function decide(string $roomType): array {
		return [
			'requiresMention' => true,
			'matchedBy' => 'mention',
		];
	}
}