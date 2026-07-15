<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Tests\unit\Listener;

use OCA\IronclawTalkBridge\Listener\ChatMessageSentListener;
use OCA\IronclawTalkBridge\Service\AppConfig;
use OCA\IronclawTalkBridge\Service\AttachmentContextBuilder;
use OCA\IronclawTalkBridge\Service\BridgeCounters;
use OCA\IronclawTalkBridge\Service\IronclawClient;
use OCA\IronclawTalkBridge\Service\MentionMatcher;
use OCA\IronclawTalkBridge\Service\RoomForwardingPolicy;
use OCA\IronclawTalkBridge\Service\RoomScopeService;
use OCA\IronclawTalkBridge\Service\TalkEventMapper;
use OCA\IronclawTalkBridge\Service\TalkParticipantInspector;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ChatMessageSentListenerTest extends TestCase {
	public function testBuildWebhookPayloadUsesCanonicalShape(): void {
		$listener = new ChatMessageSentListener(
			$this->createMock(AppConfig::class),
			$this->createMock(TalkEventMapper::class),
			$this->createMock(RoomScopeService::class),
			$this->createMock(MentionMatcher::class),
			$this->createMock(AttachmentContextBuilder::class),
			$this->createMock(TalkParticipantInspector::class),
			$this->createMock(RoomForwardingPolicy::class),
			$this->createMock(IronclawClient::class),
			$this->createMock(BridgeCounters::class),
			$this->createMock(LoggerInterface::class),
		);

		$payload = [
			'roomToken' => 'room-42',
			'messageId' => '19',
			'replyTo' => 11,
			'eventId' => 'ev-123',
			'actor' => [
				'type' => 'users',
				'id' => 'alice',
				'displayName' => 'Alice',
			],
			'room' => [
				'displayName' => 'Ops',
			],
			'mention' => [
				'userId' => 'ki_assistant',
				'displayName' => 'KI Assistant',
				'matchedBy' => 'name',
			],
			'message' => [
				'raw' => '@KI Assistant bitte pruefen',
				'stripped' => 'bitte pruefen',
				'attachments' => [
					[
						'slot' => 'file1',
						'sourceType' => 'file',
						'name' => 'report.pdf',
						'id' => '123',
						'mimeType' => 'application/pdf',
						'sizeBytes' => 42,
						'raw' => ['id' => '123'],
					],
				],
				'mentionEntities' => [
					[
						'token' => '@KI Assistant',
						'isBot' => true,
						'extra' => 'drop-me',
					],
					'bad-shape',
				],
			],
			'replyContext' => [
				'parentMessageId' => 11,
				'parentMessage' => [
					'raw' => 'vorherige bot antwort',
				],
			],
		];

		$method = new \ReflectionMethod($listener, 'buildIronclawWebhookPayload');
		$wirePayload = $method->invoke($listener, $payload);

		self::assertSame('Create', $wirePayload['type']);
		self::assertSame(['id' => 'room-42', 'name' => 'Ops'], $wirePayload['target']);
		self::assertSame(11, $wirePayload['object']['replyTo']);
		self::assertSame('report.pdf', $wirePayload['object']['attachments'][0]['name']);
		self::assertSame(11, $wirePayload['replyContext']['parentMessageId']);
		self::assertSame(['userId' => 'ki_assistant', 'displayName' => 'KI Assistant'], $wirePayload['mention']);
		self::assertSame(
			[
				'raw' => '@KI Assistant bitte pruefen',
				'attachments' => [
					[
						'slot' => 'file1',
						'sourceType' => 'file',
						'name' => 'report.pdf',
						'id' => '123',
						'mimeType' => 'application/pdf',
						'sizeBytes' => 42,
						'raw' => ['id' => '123'],
					],
				],
				'mentionEntities' => [
					[
						'token' => '@KI Assistant',
						'isBot' => true,
					],
				],
			],
			$wirePayload['bridgeMessage']
		);

		self::assertArrayNotHasKey('room', $wirePayload);
	}
}
