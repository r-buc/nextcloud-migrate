<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	public function getForm(): TemplateResponse {
		// Nextcloud's JSResourceLocator ultimately falls back to looking for
		// "<app path>/js/<file>.js" - since @nextcloud/webpack-vue-config
		// always bakes the app id into the built filename
		// (js/nextcloud_migrate-main.js, from the "main" entry), the second
		// addScript() argument must be that full built basename, not just
		// "main".
		Util::addScript('nextcloud_migrate', 'nextcloud_migrate-main');

		return new TemplateResponse('nextcloud_migrate', 'settings/admin', []);
	}

	public function getSection(): string {
		return 'nextcloud_migrate';
	}

	public function getPriority(): int {
		return 50;
	}
}
