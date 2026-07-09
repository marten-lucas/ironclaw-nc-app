<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Service;

class RoomScopeService {
	public function __construct(private AppConfig $config) {
	}

	public function isAllowed(string $roomToken): bool {
		$allowlist = $this->config->getRoomAllowlistTokens();
		if ($allowlist === []) {
			return true;
		}

		return in_array($roomToken, $allowlist, true);
	}
}
