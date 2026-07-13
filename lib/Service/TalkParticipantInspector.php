<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Service;

use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class TalkParticipantInspector {
	public function __construct(
		private IClientService $clientService,
		private IRequest $request,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Returns true when the room contains exactly two participants and one of them
	 * is the configured fake user. Returns false when the room is known not to match
	 * that shape. Returns null when inspection fails.
	 */
	public function isTwoParticipantRoomWithFakeUser(string $roomToken, string $fakeUserId): ?bool {
		$roomToken = trim($roomToken);
		$fakeUserId = trim($fakeUserId);
		if ($roomToken === '' || $fakeUserId === '') {
			return null;
		}

		$url = $this->urlGenerator->getAbsoluteURL(
			'/ocs/v2.php/apps/spreed/api/v4/room/' . rawurlencode($roomToken) . '/participants?format=json'
		);

		$headers = [
			'Accept' => 'application/json',
			'OCS-APIRequest' => 'true',
			'X-Requested-With' => 'XMLHttpRequest',
		];

		$authorization = trim((string)$this->request->getHeader('Authorization'));
		if ($authorization !== '') {
			$headers['Authorization'] = $authorization;
		}

		$cookie = trim((string)$this->request->getHeader('Cookie'));
		if ($cookie !== '') {
			$headers['Cookie'] = $cookie;
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->get($url, [
				'headers' => $headers,
				'timeout' => 5,
				'connect_timeout' => 2,
				'http_errors' => false,
				'nextcloud' => [
					'allow_local_address' => true,
				],
			]);

			$status = $response->getStatusCode();
			if ($status < 200 || $status >= 300) {
				$this->logger->debug('Participant inspector received non-success response', [
					'app' => 'ironclaw_talk_bridge',
					'roomToken' => $roomToken,
					'status' => $status,
				]);
				return null;
			}

			$rawBody = method_exists($response, 'getBody') ? (string)$response->getBody() : '';
			$decoded = json_decode($rawBody, true);
			if (!is_array($decoded)) {
				return null;
			}

			$participants = $decoded['ocs']['data'] ?? null;
			if (!is_array($participants)) {
				return null;
			}

			$totalParticipants = count($participants);
			$fakeUserPresent = false;
			$loggedInUserCount = 0;
			foreach ($participants as $participant) {
				if (!is_array($participant)) {
					continue;
				}

				$actorType = trim((string)($participant['actorType'] ?? ''));
				$actorId = trim((string)($participant['actorId'] ?? ''));
				if ($actorType === 'users') {
					$loggedInUserCount++;
				}
				if ($actorType === 'users' && $actorId === $fakeUserId) {
					$fakeUserPresent = true;
				}
			}

			return $totalParticipants === 2 && $loggedInUserCount === 2 && $fakeUserPresent;
		} catch (\Throwable $e) {
			$this->logger->debug('Participant inspector failed', [
				'app' => 'ironclaw_talk_bridge',
				'roomToken' => $roomToken,
				'error' => $e->getMessage(),
			]);
			return null;
		}
	}
}