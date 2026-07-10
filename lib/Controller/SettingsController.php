<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Controller;

use OCA\IronclawTalkBridge\AppInfo\Application;
use OCA\IronclawTalkBridge\Service\OutboundSigner;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IURLGenerator;

#[AdminRequired]
class SettingsController extends Controller {
	private const SETTINGS_SECTION = Application::APP_ID;
	private const SETTINGS_ANCHOR = '#ironclaw-talk-bridge-admin-settings';

	public function __construct(
		IRequest $request,
		private IConfig $config,
		private IUserManager $userManager,
		private IClientService $clientService,
		private OutboundSigner $outboundSigner,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function save(
		string $ironclaw_inbound_url,
		string $fake_user_id = '',
		string $ironclaw_shared_secret = '',
		string $room_allowlist_tokens = '',
		string $dispatch_batch_size = '50',
		string $signature_tolerance_seconds = '300',
		?string $enabled_present = null,
		?string $enabled = null,
		?string $strict_membership_present = null,
		?string $strict_membership_resolver = null,
	): Response {
		$trimmedUrl = trim($ironclaw_inbound_url);
		if ($trimmedUrl === '' || filter_var($trimmedUrl, FILTER_VALIDATE_URL) === false) {
			return $this->saveError('Ironclaw URL ist ungueltig.', 400);
		}

		if ($enabled_present !== null) {
			$this->config->setAppValue(Application::APP_ID, 'bridge_enabled', $enabled !== null ? '1' : '0');
		}
		if ($strict_membership_present !== null) {
			$this->config->setAppValue(Application::APP_ID, 'strict_membership_resolver', $strict_membership_resolver !== null ? '1' : '0');
		}
		$this->config->setAppValue(Application::APP_ID, 'ironclaw_inbound_url', $trimmedUrl);

		$uid = trim($fake_user_id);
		$displayName = '';
		if ($uid !== '') {
			$user = $this->userManager->get($uid);
			if ($user !== null) {
				$displayName = (string)$user->getDisplayName();
			}
		}

		if ($uid === '' || $displayName === '') {
			return $this->saveError('Fake User ist ungueltig oder nicht vorhanden.', 400);
		}

		$this->config->setAppValue(Application::APP_ID, 'mention_display_name', $displayName);
		$this->config->setAppValue(Application::APP_ID, 'fake_user_id', $uid);
		$this->config->setAppValue(Application::APP_ID, 'room_allowlist_tokens', trim($room_allowlist_tokens));

		$batchSize = max(1, min(500, (int)$dispatch_batch_size));
		$this->config->setAppValue(Application::APP_ID, 'dispatch_batch_size', (string)$batchSize);

		$tolerance = max(60, min(3600, (int)$signature_tolerance_seconds));
		$this->config->setAppValue(Application::APP_ID, 'signature_tolerance_seconds', (string)$tolerance);

		if (trim($ironclaw_shared_secret) !== '') {
			$this->config->setAppValue(Application::APP_ID, 'ironclaw_shared_secret', $ironclaw_shared_secret);
		}

		return $this->saveSuccess();
	}

	private function getSettingsUrl(): string {
		return $this->urlGenerator->linkToRoute('settings.AdminSettings.index', [
			'section' => self::SETTINGS_SECTION,
		]) . self::SETTINGS_ANCHOR;
	}

	private function isAjaxRequest(): bool {
		$requestedWith = strtolower((string)$this->request->getHeader('X-Requested-With'));
		$accept = strtolower((string)$this->request->getHeader('Accept'));

		return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
	}

	private function saveError(string $message, int $statusCode): Response {
		if ($this->isAjaxRequest()) {
			return new JSONResponse([
				'ok' => false,
				'message' => $message,
			], $statusCode);
		}

		return new RedirectResponse($this->getSettingsUrl());
	}

	private function saveSuccess(): Response {
		if ($this->isAjaxRequest()) {
			return new JSONResponse([
				'ok' => true,
				'message' => 'Einstellungen gespeichert.',
			]);
		}

		return new RedirectResponse($this->getSettingsUrl());
	}


	public function testConnection(string $ironclaw_inbound_url = ''): JSONResponse {
		$url = trim($ironclaw_inbound_url);
		if ($url === '') {
			$url = trim($this->config->getAppValue(Application::APP_ID, 'ironclaw_inbound_url', ''));
		}

		if ($url === '') {
			return new JSONResponse([
				'ok' => false,
				'message' => 'Ironclaw URL fehlt.',
			], 400);
		}

		if (filter_var($url, FILTER_VALIDATE_URL) === false) {
			return new JSONResponse([
				'ok' => false,
				'level' => 'red',
				'message' => 'Ironclaw URL ist ungueltig.',
			], 400);
		}

		$parts = parse_url($url);
		$scheme = strtolower((string)($parts['scheme'] ?? 'https'));
		$host = (string)($parts['host'] ?? '');
		$path = (string)($parts['path'] ?? '');
		$port = (int)($parts['port'] ?? ($scheme === 'http' ? 80 : 443));

		if ($host === '') {
			return new JSONResponse([
				'ok' => false,
				'level' => 'red',
				'message' => 'Ironclaw URL ist ungueltig (Host fehlt).',
			], 400);
		}

		if ($scheme !== 'http' && $scheme !== 'https') {
			return new JSONResponse([
				'ok' => false,
				'level' => 'red',
				'message' => 'Nur http/https URLs sind erlaubt.',
			], 400);
		}

		$transport = $scheme === 'https' ? 'ssl://' : 'tcp://';
		$endpoint = $transport . $host . ':' . $port;
		$errno = 0;
		$errstr = '';

		$socket = @stream_socket_client($endpoint, $errno, $errstr, 3.0, STREAM_CLIENT_CONNECT);
		if ($socket === false) {
			return new JSONResponse([
				'ok' => false,
				'level' => 'red',
				'message' => 'Verbindung fehlgeschlagen (' . ($errstr !== '' ? $errstr : 'Netzwerkfehler') . ').',
				'errno' => $errno,
			], 502);
		}

		fclose($socket);

		$payload = [
			// Non-Create keeps this a no-op for the channel while still exercising auth checks.
			'type' => 'Probe',
			'actor' => ['id' => 'nextcloud-bridge-test'],
			'object' => ['id' => '0', 'content' => 'connection test'],
			'target' => ['id' => 'probe-room'],
		];
		$body = (string)json_encode($payload, JSON_THROW_ON_ERROR);

		$sharedSecret = trim($this->config->getAppValue(Application::APP_ID, 'ironclaw_shared_secret', ''));
		if ($sharedSecret === '') {
			return new JSONResponse([
				'ok' => false,
				'level' => 'yellow',
				'message' => 'Verbindung vorhanden, aber keine Signatur konfiguriert (Shared Secret fehlt).',
			]);
		}

		$signedHeaders = $this->outboundSigner->buildHeaders($body, $sharedSecret);
		$signed = $this->performWebhookProbe($url, $signedHeaders, $body);

		if ($signed['transport_error']) {
			return new JSONResponse([
				'ok' => false,
				'level' => 'yellow',
				'message' => 'Host erreichbar (TCP/TLS), aber signierter HTTP-Request fehlgeschlagen.',
			]);
		}

		if ($signed['status'] >= 200 && $signed['status'] < 300) {
			return new JSONResponse([
				'ok' => true,
				'level' => 'green',
				'status' => $signed['status'],
				'message' => 'Verbindung mit Signatur erfolgreich (HTTP ' . $signed['status'] . ').',
			]);
		}

		if ($signed['status'] === 401) {
			return new JSONResponse([
				'ok' => false,
				'level' => 'yellow',
				'status' => $signed['status'],
				'message' => 'Verbindung vorhanden, aber Signatur abgelehnt (Shared Secret stimmt vermutlich nicht).',
			]);
		}

		return new JSONResponse([
			'ok' => false,
			'level' => 'yellow',
			'status' => $signed['status'],
			'message' => 'Verbindung vorhanden, aber Signaturtest nicht erfolgreich (HTTP ' . $signed['status'] . ').',
		]);
	}

	/**
	 * @param array<string, string> $headers
	 * @return array{status:int, transport_error:bool}
	 */
	private function performWebhookProbe(string $url, array $headers, string $body): array {
		try {
			$client = $this->clientService->newClient();
			$response = $client->post($url, [
				'headers' => $headers,
				'body' => $body,
				'timeout' => 10,
				'connect_timeout' => 5,
			]);

			return [
				'status' => $response->getStatusCode(),
				'transport_error' => false,
			];
		} catch (\Throwable $e) {
			return [
				'status' => 0,
				'transport_error' => true,
			];
		}
	}
}
