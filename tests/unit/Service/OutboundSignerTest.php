<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Tests\unit\Service;

use OCA\IronclawTalkBridge\Service\OutboundSigner;
use PHPUnit\Framework\TestCase;

class OutboundSignerTest extends TestCase {
	public function testBuildHeadersIsDeterministicWithFixedNonceAndTimestamp(): void {
		$signer = new OutboundSigner();
		$body = '{"eventId":"nc-talk:room:1"}';
		$headers = $signer->buildHeaders($body, 'secret', 1700000000, 'abc123');

		self::assertSame('1700000000', $headers['X-Ironclaw-Timestamp']);
		self::assertSame('abc123', $headers['X-Ironclaw-Nonce']);

		$expected = hash_hmac('sha256', "1700000000\nabc123\n" . $body, 'secret');
		self::assertSame($expected, $headers['X-Ironclaw-Signature']);
	}
}
