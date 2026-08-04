<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\AppInfo;

use OCA\NextcloudMigrate\BackgroundJob\CleanupLocksJob;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;

class Application extends App implements IBootstrap {
	public const APP_ID = 'nextcloud_migrate';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// Services are resolved via autowiring; no explicit factory
		// registrations are required for the classes in lib/Service.
	}

	public function boot(IBootContext $context): void {
		// Ensure the periodic lock-cleanup job is always scheduled. Nextcloud
		// deduplicates by class name, so this is safe to call on every boot.
		$context->getServerContainer()
			->get(IJobList::class)
			->add(CleanupLocksJob::class);
	}
}
