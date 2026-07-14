<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Service;

use OCA\IronclawTalkBridge\AppInfo\Application;
use OCP\IConfig;

class AppConfig {
	public function __construct(private IConfig $config) {
	}

	public function isEnabled(): bool {
		return $this->config->getAppValue(Application::APP_ID, 'bridge_enabled', '0') === '1';
	}

	public function getIronclawInboundUrl(): string {
		return trim($this->config->getAppValue(Application::APP_ID, 'ironclaw_inbound_url', ''));
	}

	public function getSharedSecret(): string {
		return $this->config->getAppValue(Application::APP_ID, 'ironclaw_shared_secret', '');
	}

	public function getMentionDisplayName(): string {
		return trim($this->config->getAppValue(Application::APP_ID, 'mention_display_name', ''));
	}

	public function getFakeUserName(): string {
		return $this->getMentionDisplayName();
	}

	public function getFakeUserId(): string {
		return trim($this->config->getAppValue(Application::APP_ID, 'fake_user_id', ''));
	}

	/**
	 * @return string[]
	 */
	public function getRoomAllowlistTokens(): array {
		$raw = trim($this->config->getAppValue(Application::APP_ID, 'room_allowlist_tokens', ''));
		if ($raw === '') {
			return [];
		}

		$tokens = array_map(static fn (string $token): string => trim($token), explode(',', $raw));
		$tokens = array_filter($tokens, static fn (string $token): bool => $token !== '');
		return array_values(array_unique($tokens));
	}

	public function getSignatureToleranceSeconds(): int {
		$value = (int)$this->config->getAppValue(Application::APP_ID, 'signature_tolerance_seconds', '300');
		return max(60, min(3600, $value));
	}

	public function isReadyForDelivery(): bool {
		return $this->getIronclawInboundUrl() !== ''
			&& $this->getSharedSecret() !== ''
			&& $this->getFakeUserId() !== ''
			&& $this->getFakeUserName() !== '';
	}
}
