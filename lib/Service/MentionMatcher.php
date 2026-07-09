<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Service;

class MentionMatcher {
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

	private function buildPattern(string $displayName): string {
		$escaped = preg_quote($displayName, '/');
		return '/(^|[\s])@' . $escaped . '(?=$|[\s\.,:;!?])/u';
	}
}
