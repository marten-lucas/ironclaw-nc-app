<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Service;

class OutboundSigner {
	/**
	 * @return array<string, string>
	 */
	public function buildHeaders(string $jsonBody, string $sharedSecret, ?int $timestamp = null, ?string $nonce = null): array {
		$timestamp = $timestamp ?? time();
		$nonce = $nonce ?? bin2hex(random_bytes(16));
		$nextcloudRandom = bin2hex(random_bytes(16));

		$base = $timestamp . "\n" . $nonce . "\n" . $jsonBody;
		$signature = hash_hmac('sha256', $base, $sharedSecret);

		$nextcloudBase = $nextcloudRandom . $jsonBody;
		$nextcloudSignature = hash_hmac('sha256', $nextcloudBase, $sharedSecret);

		return [
			'Content-Type' => 'application/json',
			'X-Ironclaw-Key-Id' => 'nextcloud-talk-bridge-v1',
			'X-Ironclaw-Timestamp' => (string)$timestamp,
			'X-Ironclaw-Nonce' => $nonce,
			'X-Ironclaw-Signature' => $signature,
			'X-Nextcloud-Talk-Random' => $nextcloudRandom,
			'X-Nextcloud-Talk-Signature' => $nextcloudSignature,
		];
	}
}
