<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Settings;

use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	public function __construct(
		private IAppManager $appManager,
	) {
	}

	public function getForm(): TemplateResponse {
		// Nextcloud's JSResourceLocator ultimately falls back to looking for
		// "<app path>/js/<file>.js" - since @nextcloud/webpack-vue-config
		// always bakes the app id into the built filename
		// (js/nextcloud_migrate-main.js, from the "main" entry), the second
		// addScript() argument must be that full built basename, not just
		// "main".
		Util::addScript('nextcloud_migrate', 'nextcloud_migrate-main');

		// The built js/ bundle is a required release artifact (not tracked
		// in git, like vendor/ - see README "Frontend build"), so it's
		// missing if this app was installed from GitHub's auto-generated
		// "Source code" archive for a tag/release instead of the actual
		// nextcloud_migrate.tar.gz asset our release workflow builds and
		// uploads. That mistake otherwise fails completely silently: the
		// PHP template still renders fine, but the Vue app that owns
		// everything below the intro paragraph never loads, leaving an
		// admin looking at what appears to be a blank/broken settings page
		// with no visible error anywhere. Detect it here and show an
		// explicit, actionable warning instead.
		$jsMissing = true;
		try {
			$appPath = $this->appManager->getAppPath('nextcloud_migrate');
			$jsMissing = !is_file($appPath . '/js/nextcloud_migrate-main.js');
		} catch (\Throwable) {
			// Leave $jsMissing true; something is unusually wrong with the
			// install either way.
		}

		return new TemplateResponse('nextcloud_migrate', 'settings/admin', [
			'jsMissing' => $jsMissing,
		]);
	}

	public function getSection(): string {
		return 'nextcloud_migrate';
	}

	public function getPriority(): int {
		return 50;
	}
}
