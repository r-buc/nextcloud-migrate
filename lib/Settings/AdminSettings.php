<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	public function getForm(): TemplateResponse {
		Util::addScript('nextcloud_migrate', 'admin');
		Util::addStyle('nextcloud_migrate', 'admin');

		return new TemplateResponse('nextcloud_migrate', 'settings/admin', []);
	}

	public function getSection(): string {
		return 'nextcloud_migrate';
	}

	public function getPriority(): int {
		return 50;
	}
}
