<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Controller;

use OCA\IronclawTalkBridge\AppInfo\Application;
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
				'message' => 'Ironclaw URL ist ungueltig.',
			], 400);
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->post($url, [
				'headers' => ['Content-Type' => 'application/json'],
				'body' => '{"probe":true}',
				'timeout' => 10,
				'connect_timeout' => 5,
			]);

			$status = $response->getStatusCode();
			$ok = $status >= 200 && $status < 500;

			return new JSONResponse([
				'ok' => $ok,
				'status' => $status,
				'message' => $ok
					? 'Verbindung erreichbar (HTTP ' . $status . ').'
					: 'Verbindung fehlgeschlagen (HTTP ' . $status . ').',
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'ok' => false,
				'message' => 'Verbindungstest fehlgeschlagen. Bitte URL/Proxy/TLS pruefen.',
			], 502);
		}
	}
}
