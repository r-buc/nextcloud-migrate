<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\BackgroundJob;

use OCA\NextcloudMigrate\Db\MigrationRunMapper;
use OCA\NextcloudMigrate\Service\RunOrchestrator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Periodic sweep for continuous sync (MigrationRun::STATE_SYNCING, see
 * RunOrchestrator::startSyncing()/runSyncPass()): on every run of this job
 * (i.e. the standard cron tick - no custom scheduling beyond the usual
 * periodic-job interval, same as CleanupLocksJob), re-scans every currently
 * syncing run's mapped users for new or changed source files and re-enters
 * them into the normal transfer/verification pipeline. A run stays in
 * this sweep indefinitely until an admin explicitly stops syncing via
 * RunOrchestrator::stopSyncing().
 */
class SyncDiscoveryJob extends TimedJob {
	// Mirrors CleanupLocksJob's interval - this is the "standard cron tick"
	// periodic jobs in this app use, not a dedicated faster/slower schedule
	// for sync specifically.
	private const INTERVAL_SECONDS = 300;

	public function __construct(
		ITimeFactory $time,
		private MigrationRunMapper $runMapper,
		private RunOrchestrator $runOrchestrator,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	protected function run($argument): void {
		try {
			$runs = $this->runMapper->findSyncing();
		} catch (\Throwable $e) {
			// e.g. the app's tables don't exist (yet, or anymore) - skip
			// quietly rather than spamming the log every tick.
			$this->logger->debug('SyncDiscoveryJob could not list syncing runs: ' . $e->getMessage(), [
				'app' => 'nextcloud_migrate',
			]);
			return;
		}

		foreach ($runs as $run) {
			try {
				$this->runOrchestrator->runSyncPass($run->getId());
			} catch (\Throwable $e) {
				$this->logger->warning('SyncDiscoveryJob could not complete a sync pass for run', [
					'app' => 'nextcloud_migrate',
					'runId' => $run->getId(),
					'exception' => $e,
				]);
			}
		}
	}
}
