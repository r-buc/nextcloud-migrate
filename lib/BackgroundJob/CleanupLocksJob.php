<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\BackgroundJob;

use OCA\NextcloudMigrate\Db\MigrationFileMapper;
use OCA\NextcloudMigrate\Service\RunOrchestrator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Periodic sweep that reclaims migrate_files rows left in an "in progress"
 * state (TRANSFERRING/VERIFYING) by a worker that crashed or was killed
 * before it could persist a terminal state. Without this, a crashed worker
 * would leave its claimed row permanently invisible to
 * findTransferable()/findVerifiable(), stalling the run.
 *
 * Also runs RunOrchestrator::reconcileStalledRuns() (see its docblock): a
 * self-healing check for runs left wedged in TRANSFERRING/VERIFYING with no
 * active worker lineage left and nothing actually remaining to do.
 */
class CleanupLocksJob extends TimedJob {
	// How often this sweep runs. Reclaimed rows become eligible again
	// immediately (next_retry_at = now), so this interval mainly bounds the
	// worst-case delay after a worker crash, not overall throughput.
	private const INTERVAL_SECONDS = 300;

	public function __construct(
		ITimeFactory $time,
		private MigrationFileMapper $fileMapper,
		private RunOrchestrator $runOrchestrator,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	protected function run($argument): void {
		try {
			$reclaimed = $this->fileMapper->reclaimStaleLocks(time());
		} catch (\Throwable $e) {
			// e.g. the app's tables don't exist (yet, or anymore, e.g. right
			// after a manual DB reset) - skip quietly rather than spamming
			// the log with a raw DB exception every 5 minutes.
			$this->logger->debug('CleanupLocksJob could not reclaim stale locks: ' . $e->getMessage(), [
				'app' => 'nextcloud_migrate',
			]);
			return;
		}

		if ($reclaimed > 0) {
			$this->logger->info("Reclaimed {$reclaimed} stale migration file lock(s) from crashed worker(s)", [
				'app' => 'nextcloud_migrate',
			]);
		}

		try {
			$this->runOrchestrator->reconcileStalledRuns();
		} catch (\Throwable $e) {
			$this->logger->debug('CleanupLocksJob could not reconcile stalled runs: ' . $e->getMessage(), [
				'app' => 'nextcloud_migrate',
			]);
		}
	}
}
