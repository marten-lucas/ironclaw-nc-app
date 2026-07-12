<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Tests\unit\Service;

use OCA\IronclawTalkBridge\Service\MentionMatcher;
use PHPUnit\Framework\TestCase;

class MentionMatcherTest extends TestCase {
	public function testContainsExactMention(): void {
		$matcher = new MentionMatcher();
		self::assertTrue($matcher->containsExactMention('@Ironclaw summarize this', 'Ironclaw'));
		self::assertTrue($matcher->containsExactMention('please @Ironclaw, summarize this', 'Ironclaw'));
		self::assertFalse($matcher->containsExactMention('@IronclawX summarize this', 'Ironclaw'));
		self::assertFalse($matcher->containsExactMention('no mention here', 'Ironclaw'));
	}

	public function testStripExactMention(): void {
		$matcher = new MentionMatcher();
		self::assertSame('summarize this', $matcher->stripExactMention('@Ironclaw summarize this', 'Ironclaw'));
		self::assertSame('please summarize this', $matcher->stripExactMention('please @Ironclaw summarize this', 'Ironclaw'));
	}

	public function testContainsMentionByConfiguredUserId(): void {
		$matcher = new MentionMatcher();
		self::assertTrue($matcher->containsMention(
			'@ki_assistent bitte zusammenfassen',
			[],
			'Ironclaw',
			'ki_assistent'
		));
		self::assertFalse($matcher->containsMention(
			'bitte zusammenfassen',
			[],
			'Ironclaw',
			'ki_assistent'
		));
	}
}
