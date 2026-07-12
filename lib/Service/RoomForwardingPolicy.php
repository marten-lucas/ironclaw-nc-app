<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Service;

class RoomForwardingPolicy {
	/**
	 * @param array<int,string> $participantActors
	 * @return array{requiresMention:bool,otherParticipantCount:int,matchedBy:string}
	 */
	public function decide(
		string $fakeUserId,
		array $participantActors,
		int $participantCount,
		bool $fakeUserInRoom
	): array {
		$otherParticipantCount = 0;

		$fakeUserId = trim($fakeUserId);
		if ($fakeUserId !== '' && $fakeUserInRoom) {
			$fakeActorKey = 'users:' . $fakeUserId;
			if ($participantActors !== [] && in_array($fakeActorKey, $participantActors, true)) {
				$otherParticipantCount = count(array_filter(
					$participantActors,
					static fn (string $actor): bool => $actor !== $fakeActorKey
				));
			}

			if ($participantCount > 0) {
				$otherParticipantCount = max($otherParticipantCount, max(0, $participantCount - 1));
			}
		}

		$requiresMention = $otherParticipantCount !== 1;
		return [
			'requiresMention' => $requiresMention,
			'otherParticipantCount' => $otherParticipantCount,
			'matchedBy' => $requiresMention ? 'mention' : 'two_participant_room',
		];
	}
}