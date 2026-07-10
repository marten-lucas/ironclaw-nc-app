<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Service;

use OCP\Http\Client\IClientService;

class IronclawClient {
	public function __construct(
		private IClientService $clientService,
		private OutboundSigner $signer,
		private AppConfig $config,
	) {
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function deliver(array $payload): int {
		$url = $this->config->getIronclawInboundUrl();
		$body = (string)json_encode($payload, JSON_THROW_ON_ERROR);
		$headers = $this->signer->buildHeaders($body, $this->config->getSharedSecret());

		$client = $this->clientService->newClient();
		$response = $client->post($url, [
			'headers' => $headers,
			'body' => $body,
			'timeout' => 10,
			'connect_timeout' => 5,
			'nextcloud' => [
				'allow_local_address' => true,
			],
		]);

		return $response->getStatusCode();
	}
}
