<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\BackgroundJob;

use OCA\NextcloudMigrate\Service\RunOrchestrator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Computes the final report and marks the run COMPLETED or
 * COMPLETED_WITH_ERRORS. Triggered once by RunOrchestrator::onVerificationPoolIdle().
 */
class FinalizeJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private RunOrchestrator $runOrchestrator,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		if (!is_array($argument) || !isset($argument['runId']) || !is_numeric($argument['runId'])) {
			$this->logger->warning('FinalizeJob invoked with missing/invalid runId argument; skipping', [
				'app' => 'nextcloud_migrate',
			]);
			return;
		}
		$runId = (int)$argument['runId'];

		try {
			$this->runOrchestrator->finalizeRun($runId);
		} catch (DoesNotExistException) {
			// Run was deleted; nothing to finalize.
		} catch (\Throwable $e) {
			$this->logger->warning('FinalizeJob could not finalize run; dropping stale/invalid job', [
				'app' => 'nextcloud_migrate',
				'runId' => $runId,
				'exception' => $e,
			]);
		}
	}
}
