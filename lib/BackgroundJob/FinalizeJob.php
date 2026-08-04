<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\BackgroundJob;

use OCA\NextcloudMigrate\Service\RunOrchestrator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Computes the final report and marks the run COMPLETED or
 * COMPLETED_WITH_ERRORS. Triggered once by RunOrchestrator::onVerificationPoolIdle().
 */
class FinalizeJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private RunOrchestrator $runOrchestrator,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$runId = (int)$argument['runId'];

		try {
			$this->runOrchestrator->finalizeRun($runId);
		} catch (DoesNotExistException) {
			// Run was deleted; nothing to finalize.
		}
	}
}
