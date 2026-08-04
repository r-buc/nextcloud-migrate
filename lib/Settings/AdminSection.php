<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Settings;

use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function getID(): string {
		return 'nextcloud_migrate';
	}

	public function getName(): string {
		return 'Migrate to another instance';
	}

	public function getPriority(): int {
		return 75;
	}

	public function getIcon(): string {
		return \OCP\Util::imagePath('core', 'actions/download.svg');
	}
}
