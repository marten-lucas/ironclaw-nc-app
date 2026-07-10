<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Settings;

use OCA\IronclawTalkBridge\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
	public function __construct(private IConfig $config) {
	}

	public function getForm(): TemplateResponse {
		try {
			$request = \OC::$server->getRequest();
			$uiStatus = (string)$request->getParam('ictb_status', '');
			$uiMessage = (string)$request->getParam('ictb_msg', '');

			$secret = $this->config->getAppValue(Application::APP_ID, 'ironclaw_shared_secret', '');
			$fakeUserId = $this->config->getAppValue(Application::APP_ID, 'fake_user_id', '');
			$fakeUserName = $this->config->getAppValue(Application::APP_ID, 'mention_display_name', '');
			$users = [];

			// Keep settings page available even if user directory lookup fails.
			try {
				$foundUsers = [];
				$userManager = \OC::$server->getUserManager();
				if ($userManager !== null) {
					if (method_exists($userManager, 'searchDisplayName')) {
						$foundUsers = $userManager->searchDisplayName('');
					} elseif (method_exists($userManager, 'search')) {
						$foundUsers = $userManager->search('');
					}
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
					'enabled' => $this->config->getAppValue(Application::APP_ID, 'bridge_enabled', '0') === '1',
					'strict_membership_resolver' => $this->config->getAppValue(Application::APP_ID, 'strict_membership_resolver', '1') === '1',
					'ironclaw_inbound_url' => $this->config->getAppValue(Application::APP_ID, 'ironclaw_inbound_url', ''),
					'fake_user_name' => $fakeUserName,
					'fake_user_id' => $fakeUserId,
					'room_allowlist_tokens' => $this->config->getAppValue(Application::APP_ID, 'room_allowlist_tokens', ''),
					'dispatch_batch_size' => $this->config->getAppValue(Application::APP_ID, 'dispatch_batch_size', '50'),
					'signature_tolerance_seconds' => $this->config->getAppValue(Application::APP_ID, 'signature_tolerance_seconds', '300'),
				],
				'users' => $users,
				'uiStatus' => $uiStatus,
				'uiMessage' => $uiMessage,
				'secretConfigured' => $secret !== '',
			], '');
		} catch (\Throwable $e) {
			\OC::$server->getLogger()->error('Ironclaw Talk Bridge admin settings rendering failed', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);

			return new TemplateResponse(Application::APP_ID, 'settings-admin', [
				'values' => [
					'enabled' => false,
					'strict_membership_resolver' => true,
					'ironclaw_inbound_url' => '',
					'fake_user_name' => '',
					'fake_user_id' => '',
					'room_allowlist_tokens' => '',
					'dispatch_batch_size' => '50',
					'signature_tolerance_seconds' => '300',
				],
				'users' => [],
				'uiStatus' => 'error',
				'uiMessage' => 'Interner Fehler beim Laden der Einstellungen. Details im Nextcloud-Log.',
				'secretConfigured' => false,
			], '');
		}
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 91;
	}

	public function getName(): string {
		return 'Ironclaw Talk Bridge';
	}
}
