<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Settings;

use OCA\IronclawTalkBridge\AppInfo\Application;
use OCP\IL10N;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function __construct(private IL10N $l) {
	}

	public function getID(): string {
		return Application::APP_ID . '-admin';
	}

	public function getName(): string {
		return $this->l->t('Ironclaw Talk Bridge');
	}

	public function getPriority(): int {
		return 57;
	}

	public function getIcon(): string {
		return '';
	}
}
