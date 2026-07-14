<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Service;

class MentionMatcher {
	/**
	 * @param array<mixed> $messageParameters
	 */
	public function containsMention(
		string $message,
		array $messageParameters,
		string $displayName,
		string $fakeUserId = ''
	): bool {
		if ($this->containsMentionInParameters($messageParameters, $displayName, $fakeUserId)) {
			return true;
		}

		if ($this->containsExactMentionById($message, $fakeUserId)) {
			return true;
		}

		return $this->containsExactMention($message, $displayName);
	}

	public function containsExactMention(string $message, string $displayName): bool {
		$displayName = trim($displayName);
		if ($displayName === '') {
			return false;
		}

		$pattern = $this->buildPattern($displayName);
		return preg_match($pattern, $message) === 1;
	}

	public function stripExactMention(string $message, string $displayName): string {
		$displayName = trim($displayName);
		if ($displayName === '') {
			return trim($message);
		}

		$pattern = $this->buildPattern($displayName);
		$stripped = preg_replace($pattern, ' ', $message);
		if (!is_string($stripped)) {
			return trim($message);
		}

		$stripped = preg_replace('/\s+/u', ' ', $stripped);
		return trim((string)$stripped);
	}

	public function stripMentionsForFakeUser(
		string $message,
		string $displayName,
		string $fakeUserId = ''
	): string {
		$result = $this->stripExactMention($message, $displayName);
		$fakeUserId = trim($fakeUserId);
		if ($fakeUserId === '') {
			return $result;
		}

		$escaped = preg_quote($fakeUserId, '/');
		$pattern = '/(^|[\s])@' . $escaped . '(?=$|[\s\.,:;!?])/iu';
		$stripped = preg_replace($pattern, ' ', $result);
		if (!is_string($stripped)) {
			return $result;
		}
		$stripped = preg_replace('/\s+/u', ' ', $stripped);
		return trim((string)$stripped);
	}

	private function buildPattern(string $displayName): string {
		$escaped = preg_quote($displayName, '/');
		return '/(^|[\s])@' . $escaped . '(?=$|[\s\.,:;!?])/u';
	}

	private function containsExactMentionById(string $message, string $userId): bool {
		$userId = trim($userId);
		if ($userId === '') {
			return false;
		}

		$escaped = preg_quote($userId, '/');
		$pattern = '/(^|[\s])@' . $escaped . '(?=$|[\s\.,:;!?])/iu';
		return preg_match($pattern, $message) === 1;
	}

	/**
	 * @param array<mixed> $messageParameters
	 */
	private function containsMentionInParameters(
		array $messageParameters,
		string $displayName,
		string $fakeUserId
	): bool {
		$displayName = trim($displayName);
		$fakeUserId = trim($fakeUserId);

		foreach ($this->iterMentionCandidates($messageParameters) as $candidate) {
			$type = strtolower(trim((string)($candidate['type'] ?? '')));
			if ($type !== '' && !in_array($type, ['user', 'users', 'mention'], true)) {
				continue;
			}

			$candidateId = trim((string)($candidate['id'] ?? $candidate['actorId'] ?? ''));
			$candidateName = trim((string)($candidate['name'] ?? $candidate['label'] ?? $candidate['displayName'] ?? ''));

			if ($fakeUserId !== '' && $candidateId !== '' && strcasecmp($candidateId, $fakeUserId) === 0) {
				return true;
			}
			if ($displayName !== '' && $candidateName !== '' && strcasecmp($candidateName, $displayName) === 0) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<mixed> $messageParameters
	 * @return iterable<array<string,mixed>>
	 */
	private function iterMentionCandidates(array $messageParameters): iterable {
		foreach ($messageParameters as $value) {
			if (is_array($value)) {
				yield $value;
			}
		}
	}
}
