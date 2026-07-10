<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Settings;

use OCA\IronclawTalkBridge\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
	public function __construct(
		private IConfig $config,
		private IUserManager $userManager,
	) {
	}

	public function getForm(): TemplateResponse {
		$secret = $this->config->getAppValue(Application::APP_ID, 'ironclaw_shared_secret', '');
		$fakeUserId = $this->config->getAppValue(Application::APP_ID, 'fake_user_id', '');
		$users = [];
		foreach ($this->userManager->search('') as $user) {
			$users[] = [
				'uid' => $user->getUID(),
				'displayName' => $user->getDisplayName(),
			];
		}
		usort($users, static function (array $a, array $b): int {
			return strcmp((string)$a['displayName'], (string)$b['displayName']);
		});

		return new TemplateResponse(Application::APP_ID, 'settings-admin', [
			'values' => [
				'enabled' => $this->config->getAppValue(Application::APP_ID, 'enabled', '0') === '1',
				'strict_membership_resolver' => $this->config->getAppValue(Application::APP_ID, 'strict_membership_resolver', '1') === '1',
				'ironclaw_inbound_url' => $this->config->getAppValue(Application::APP_ID, 'ironclaw_inbound_url', ''),
				'fake_user_name' => $this->config->getAppValue(Application::APP_ID, 'mention_display_name', ''),
				'fake_user_id' => $fakeUserId,
				'room_allowlist_tokens' => $this->config->getAppValue(Application::APP_ID, 'room_allowlist_tokens', ''),
				'dispatch_batch_size' => $this->config->getAppValue(Application::APP_ID, 'dispatch_batch_size', '50'),
				'signature_tolerance_seconds' => $this->config->getAppValue(Application::APP_ID, 'signature_tolerance_seconds', '300'),
			],
			'users' => $users,
			'secretConfigured' => $secret !== '',
		], '');
	}

	public function getSection(): ?string {
		return Application::APP_ID . '-admin';
	}

	public function getPriority(): int {
		return 91;
	}

	public function getName(): ?string {
		return 'Ironclaw Talk Bridge';
	}
}
