<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Settings;

use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function __construct(
		private IURLGenerator $urlGenerator,
	) {
	}

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
		return $this->urlGenerator->imagePath('core', 'actions/download.svg');
	}
}
