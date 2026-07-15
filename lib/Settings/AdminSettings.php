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
			$reactionApprovalRaw = $this->config->getAppValue(Application::APP_ID, 'reaction_approval_user_ids', '');
			$reactionApprovalIds = array_values(array_filter(array_map(
				static fn (string $id): string => trim($id),
				explode(',', $reactionApprovalRaw)
			), static fn (string $id): bool => $id !== ''));
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

			foreach ($reactionApprovalIds as $approvalId) {
				$hasSelectedUser = false;
				foreach ($users as $user) {
					if ((string)$user['uid'] === (string)$approvalId) {
						$hasSelectedUser = true;
						break;
					}
				}

				if (!$hasSelectedUser) {
					$users[] = [
						'uid' => $approvalId,
						'displayName' => $approvalId,
					];
				}
			}

			usort($users, static function (array $a, array $b): int {
				return strcmp((string)$a['displayName'], (string)$b['displayName']);
			});

			return new TemplateResponse(Application::APP_ID, 'settings-admin', [
				'values' => [
					'enabled' => $this->config->getAppValue(Application::APP_ID, 'bridge_enabled', '0') === '1',
					'ironclaw_inbound_url' => $this->config->getAppValue(Application::APP_ID, 'ironclaw_inbound_url', ''),
					'fake_user_name' => $fakeUserName,
					'fake_user_id' => $fakeUserId,
					'room_allowlist_tokens' => $this->config->getAppValue(Application::APP_ID, 'room_allowlist_tokens', ''),
					'signature_tolerance_seconds' => $this->config->getAppValue(Application::APP_ID, 'signature_tolerance_seconds', '300'),
					'reaction_approval_user_ids' => $reactionApprovalRaw,
					'reaction_approval_user_id_list' => $reactionApprovalIds,
					'attachment_allowed_mime_patterns' => $this->config->getAppValue(Application::APP_ID, 'attachment_allowed_mime_patterns', 'text/*,application/pdf,image/*,application/json,application/xml,text/markdown,text/csv'),
					'attachment_max_file_size_bytes' => $this->config->getAppValue(Application::APP_ID, 'attachment_max_file_size_bytes', (string)(5 * 1024 * 1024)),
					'attachment_max_total_size_bytes' => $this->config->getAppValue(Application::APP_ID, 'attachment_max_total_size_bytes', (string)(20 * 1024 * 1024)),
					'attachment_max_extract_chars' => $this->config->getAppValue(Application::APP_ID, 'attachment_max_extract_chars', '12000'),
					'attachment_enable_ocr' => $this->config->getAppValue(Application::APP_ID, 'attachment_enable_ocr', '0') === '1',
					'attachment_ocr_languages' => $this->config->getAppValue(Application::APP_ID, 'attachment_ocr_languages', 'deu+eng'),
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
					'ironclaw_inbound_url' => '',
					'fake_user_name' => '',
					'fake_user_id' => '',
					'room_allowlist_tokens' => '',
					'signature_tolerance_seconds' => '300',
					'reaction_approval_user_ids' => '',
					'reaction_approval_user_id_list' => [],
					'attachment_allowed_mime_patterns' => 'text/*,application/pdf,image/*,application/json,application/xml,text/markdown,text/csv',
					'attachment_max_file_size_bytes' => (string)(5 * 1024 * 1024),
					'attachment_max_total_size_bytes' => (string)(20 * 1024 * 1024),
					'attachment_max_extract_chars' => '12000',
					'attachment_enable_ocr' => false,
					'attachment_ocr_languages' => 'deu+eng',
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
