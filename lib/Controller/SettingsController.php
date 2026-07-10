<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Controller;

use OCA\IronclawTalkBridge\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IURLGenerator;

class SettingsController extends Controller {
	private const SETTINGS_ANCHOR = '#ironclaw-talk-bridge-admin-settings';
	private const SETTINGS_ROUTE = 'ironclaw_talk_bridge.Settings.index';

	public function __construct(
		IRequest $request,
		private IConfig $config,
		private IUserManager $userManager,
		private IClientService $clientService,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function index(string $ictb_status = '', string $ictb_msg = ''): TemplateResponse {
		$secret = $this->config->getAppValue(Application::APP_ID, 'ironclaw_shared_secret', '');
		$fakeUserId = $this->config->getAppValue(Application::APP_ID, 'fake_user_id', '');
		$fakeUserName = $this->config->getAppValue(Application::APP_ID, 'mention_display_name', '');

		$users = [];
		try {
			$foundUsers = [];
			if (method_exists($this->userManager, 'searchDisplayName')) {
				$foundUsers = $this->userManager->searchDisplayName('');
			} elseif (method_exists($this->userManager, 'search')) {
				$foundUsers = $this->userManager->search('');
			}

			foreach ($foundUsers as $user) {
				$users[] = [
					'uid' => $user->getUID(),
					'displayName' => $user->getDisplayName(),
				];
			}
		} catch (\Throwable $e) {
			$users = [];
		}

		if ($fakeUserId !== '') {
			$hasSelectedUser = false;
			foreach ($users as $user) {
				if ((string)$user['uid'] === (string)$fakeUserId) {
					$hasSelectedUser = true;
					break;
				}
			}

			if (!$hasSelectedUser) {
				$users[] = [
					'uid' => $fakeUserId,
					'displayName' => $fakeUserName !== '' ? $fakeUserName : $fakeUserId,
				];
			}
		}

		usort($users, static function (array $a, array $b): int {
			return strcmp((string)$a['displayName'], (string)$b['displayName']);
		});

		return new TemplateResponse(Application::APP_ID, 'settings-admin', [
			'values' => [
				'enabled' => $this->config->getAppValue(Application::APP_ID, 'enabled', '0') === '1',
				'strict_membership_resolver' => $this->config->getAppValue(Application::APP_ID, 'strict_membership_resolver', '1') === '1',
				'ironclaw_inbound_url' => $this->config->getAppValue(Application::APP_ID, 'ironclaw_inbound_url', ''),
				'fake_user_name' => $fakeUserName,
				'fake_user_id' => $fakeUserId,
				'room_allowlist_tokens' => $this->config->getAppValue(Application::APP_ID, 'room_allowlist_tokens', ''),
				'dispatch_batch_size' => $this->config->getAppValue(Application::APP_ID, 'dispatch_batch_size', '50'),
				'signature_tolerance_seconds' => $this->config->getAppValue(Application::APP_ID, 'signature_tolerance_seconds', '300'),
			],
			'users' => $users,
			'uiStatus' => (string)$ictb_status,
			'uiMessage' => (string)$ictb_msg,
			'secretConfigured' => $secret !== '',
		], '');
	}

	public function save(
		string $ironclaw_inbound_url,
		string $fake_user_id = '',
		string $ironclaw_shared_secret = '',
		string $room_allowlist_tokens = '',
		string $dispatch_batch_size = '50',
		string $signature_tolerance_seconds = '300',
		?string $enabled = null,
		?string $strict_membership_resolver = null,
	): RedirectResponse {
		$trimmedUrl = trim($ironclaw_inbound_url);
		$redirectNonce = (string)time();
		if ($trimmedUrl === '' || filter_var($trimmedUrl, FILTER_VALIDATE_URL) === false) {
			return new RedirectResponse($this->urlGenerator->linkToRoute(self::SETTINGS_ROUTE, [
				'ictb_status' => 'error',
				'ictb_msg' => 'Bitte eine gueltige Ironclaw URL eintragen.',
				'ictb_ts' => $redirectNonce,
			]) . self::SETTINGS_ANCHOR);
		}

		$this->config->setAppValue(Application::APP_ID, 'enabled', $enabled !== null ? '1' : '0');
		$this->config->setAppValue(Application::APP_ID, 'strict_membership_resolver', $strict_membership_resolver !== null ? '1' : '0');
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
			return new RedirectResponse($this->urlGenerator->linkToRoute(self::SETTINGS_ROUTE, [
				'ictb_status' => 'error',
				'ictb_msg' => 'Bitte einen gueltigen Fake User aus der Liste waehlen.',
				'ictb_ts' => $redirectNonce,
			]) . self::SETTINGS_ANCHOR);
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

		return new RedirectResponse($this->urlGenerator->linkToRoute(self::SETTINGS_ROUTE, [
			'ictb_status' => 'success',
			'ictb_msg' => 'Einstellungen gespeichert.',
			'ictb_ts' => $redirectNonce,
		]) . self::SETTINGS_ANCHOR);
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
				'message' => 'Verbindungstest fehlgeschlagen: ' . $e->getMessage(),
			], 502);
		}
	}
}
