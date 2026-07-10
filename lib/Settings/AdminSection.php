<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Settings;

use OCA\IronclawTalkBridge\AppInfo\Application;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function getID(): string {
		return Application::APP_ID . '-admin';
	}

	public function getName(): string {
		return 'Ironclaw Talk Bridge';
	}

	public function getPriority(): int {
		return 57;
	}

	public function getIcon(): string {
		return '';
	}
}
