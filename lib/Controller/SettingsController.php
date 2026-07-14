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
		string $fake_user_name = '',
		string $ironclaw_shared_secret = '',
		string $room_allowlist_tokens = '',
		string $signature_tolerance_seconds = '300',
		?string $enabled_present = null,
		?string $enabled = null,
	): Response {
		$trimmedUrl = trim($ironclaw_inbound_url);
		if ($trimmedUrl === '' || filter_var($trimmedUrl, FILTER_VALIDATE_URL) === false) {
			return $this->saveError('Ironclaw URL ist ungueltig.', 400);
		}

		if ($enabled_present !== null) {
			$this->config->setAppValue(Application::APP_ID, 'bridge_enabled', $enabled !== null ? '1' : '0');
		}
		$this->config->setAppValue(Application::APP_ID, 'ironclaw_inbound_url', $trimmedUrl);

		$uid = trim($fake_user_id);
		$resolvedDisplayName = trim($fake_user_name);
		if ($uid === '' || $resolvedDisplayName === '') {
			return $this->saveError('Fake User ID und Fake User Name muessen gesetzt sein.', 400);
		}

		$this->config->setAppValue(Application::APP_ID, 'mention_display_name', $resolvedDisplayName);
		$this->config->setAppValue(Application::APP_ID, 'fake_user_id', $uid);
		$this->config->setAppValue(Application::APP_ID, 'room_allowlist_tokens', trim($room_allowlist_tokens));

		$tolerance = max(60, min(3600, (int)$signature_tolerance_seconds));
		$this->config->setAppValue(Application::APP_ID, 'signature_tolerance_seconds', (string)$tolerance);

		$normalizedSharedSecret = trim($ironclaw_shared_secret);
		if ($normalizedSharedSecret !== '') {
			$this->config->setAppValue(Application::APP_ID, 'ironclaw_shared_secret', $normalizedSharedSecret);
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

		$sharedSecret = $this->config->getAppValue(Application::APP_ID, 'ironclaw_shared_secret', '');
		$trimmedSharedSecret = trim($sharedSecret);
		if ($sharedSecret === '') {
			return new JSONResponse([
				'ok' => false,
				'level' => 'yellow',
				'message' => 'Verbindung vorhanden, aber keine Signatur konfiguriert (Shared Secret fehlt).',
			]);
		}

		if ($sharedSecret !== $trimmedSharedSecret) {
			return new JSONResponse([
				'ok' => false,
				'level' => 'yellow',
				'reason' => 'secret_whitespace_mismatch',
				'message' => 'Shared Secret enthaelt fuehrende/trailende Leerzeichen. Bitte in Nextcloud neu speichern.',
			]);
		}

		$signedHeaders = $this->outboundSigner->buildHeaders($body, $trimmedSharedSecret);
		$signed = $this->performWebhookProbe($url, $signedHeaders, $body);

		if ($signed['transport_error']) {
			return new JSONResponse([
				'ok' => false,
				'level' => 'yellow',
				'reason' => 'transport_exception',
				'error_detail' => $signed['error'],
				'message' => 'Host erreichbar (TCP/TLS), aber signierter HTTP-Request fehlgeschlagen.' . ($signed['error'] !== '' ? ' Detail: ' . $signed['error'] : ''),
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
			$reason = $signed['error'];
			if ($reason === 'stale_timestamp') {
				return new JSONResponse([
					'ok' => false,
					'level' => 'yellow',
					'status' => $signed['status'],
					'reason' => $reason,
					'message' => 'Verbindung vorhanden, aber Signatur wegen Zeitabweichung abgelehnt (stale_timestamp). Uhren von Nextcloud und Ironclaw pruefen.',
				]);
			}

			if ($reason === 'missing_signature') {
				return new JSONResponse([
					'ok' => false,
					'level' => 'yellow',
					'status' => $signed['status'],
					'reason' => $reason,
					'message' => 'Verbindung vorhanden, aber Signatur-Header wurden nicht erkannt (missing_signature).',
				]);
			}

			if ($reason === 'missing_timestamp' || $reason === 'invalid_timestamp') {
				return new JSONResponse([
					'ok' => false,
					'level' => 'yellow',
					'status' => $signed['status'],
					'reason' => $reason,
					'message' => 'Verbindung vorhanden, aber Timestamp-Header ungueltig (' . $reason . ').',
				]);
			}

			if ($reason === 'missing_nonce' || $reason === 'invalid_nonce' || $reason === 'replay_nonce') {
				return new JSONResponse([
					'ok' => false,
					'level' => 'yellow',
					'status' => $signed['status'],
					'reason' => $reason,
					'message' => 'Verbindung vorhanden, aber Nonce-Pruefung fehlgeschlagen (' . $reason . ').',
				]);
			}

			return new JSONResponse([
				'ok' => false,
				'level' => 'yellow',
				'status' => $signed['status'],
				'reason' => $reason,
				'message' => 'Verbindung vorhanden, aber Signatur abgelehnt (Shared Secret stimmt vermutlich nicht).',
			]);
		}

		return new JSONResponse([
			'ok' => false,
			'level' => 'yellow',
			'status' => $signed['status'],
			'reason' => $signed['error'],
			'message' => 'Verbindung vorhanden, aber Signaturtest nicht erfolgreich (HTTP ' . $signed['status'] . ').',
		]);
	}

	/**
	 * @param array<string, string> $headers
	 * @return array{status:int, transport_error:bool, error:string}
	 */
	private function performWebhookProbe(string $url, array $headers, string $body): array {
		try {
			$client = $this->clientService->newClient();
			$response = $client->post($url, [
				'headers' => $headers,
				'body' => $body,
				'timeout' => 10,
				'connect_timeout' => 5,
				'http_errors' => false,
				'nextcloud' => [
					'allow_local_address' => true,
				],
			]);

			$error = '';
			if (method_exists($response, 'getBody')) {
				$rawBody = (string)$response->getBody();
				if ($rawBody !== '') {
					$decoded = json_decode($rawBody, true);
					if (is_array($decoded) && isset($decoded['error']) && is_string($decoded['error'])) {
						$error = $decoded['error'];
					}
				}
			}

			return [
				'status' => $response->getStatusCode(),
				'transport_error' => false,
				'error' => $error,
			];
		} catch (\Throwable $e) {
			$detail = trim($e->getMessage());
			if ($detail === '') {
				$detail = $e::class;
			}

			return [
				'status' => 0,
				'transport_error' => true,
				'error' => $detail,
			];
		}
	}
}
