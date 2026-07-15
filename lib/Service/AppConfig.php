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

	/**
	 * @return string[]
	 */
	public function getReactionApprovalUserIds(): array {
		$raw = trim($this->config->getAppValue(Application::APP_ID, 'reaction_approval_user_ids', ''));
		if ($raw === '') {
			return [];
		}

		$ids = array_map(static fn (string $value): string => trim($value), explode(',', $raw));
		$ids = array_filter($ids, static fn (string $value): bool => $value !== '');
		return array_values(array_unique($ids));
	}

	public function isReactionApprovalActor(string $actorType, string $actorId): bool {
		if (trim($actorType) !== 'users') {
			return false;
		}

		return in_array(trim($actorId), $this->getReactionApprovalUserIds(), true);
	}

	/**
	 * @return string[]
	 */
	public function getAttachmentAllowedMimePatterns(): array {
		$raw = trim($this->config->getAppValue(Application::APP_ID, 'attachment_allowed_mime_patterns', 'text/*,application/pdf,image/*,application/json,application/xml,text/markdown,text/csv'));
		$patterns = array_map(static fn (string $value): string => trim(strtolower($value)), explode(',', $raw));
		$patterns = array_filter($patterns, static fn (string $value): bool => $value !== '');
		return array_values(array_unique($patterns));
	}

	public function getAttachmentMaxFileSizeBytes(): int {
		$value = (int)$this->config->getAppValue(Application::APP_ID, 'attachment_max_file_size_bytes', (string)(5 * 1024 * 1024));
		return max(64 * 1024, min(100 * 1024 * 1024, $value));
	}

	public function getAttachmentMaxTotalSizeBytes(): int {
		$value = (int)$this->config->getAppValue(Application::APP_ID, 'attachment_max_total_size_bytes', (string)(20 * 1024 * 1024));
		return max($this->getAttachmentMaxFileSizeBytes(), min(500 * 1024 * 1024, $value));
	}

	public function getAttachmentMaxExtractChars(): int {
		$value = (int)$this->config->getAppValue(Application::APP_ID, 'attachment_max_extract_chars', '12000');
		return max(1000, min(200000, $value));
	}

	public function isAttachmentOcrEnabled(): bool {
		return $this->config->getAppValue(Application::APP_ID, 'attachment_enable_ocr', '0') === '1';
	}

	public function getAttachmentOcrLanguages(): string {
		$languages = trim($this->config->getAppValue(Application::APP_ID, 'attachment_ocr_languages', 'deu+eng'));
		if ($languages === '') {
			return 'deu+eng';
		}

		return preg_replace('/[^a-zA-Z+]/', '', $languages) ?: 'deu+eng';
	}

	public function isReadyForDelivery(): bool {
		return $this->getIronclawInboundUrl() !== ''
			&& $this->getSharedSecret() !== ''
			&& $this->getFakeUserId() !== ''
			&& $this->getFakeUserName() !== '';
	}
}
