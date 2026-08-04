<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\BackgroundJob;

use OCA\NextcloudMigrate\Db\MigrationRun;
use OCA\NextcloudMigrate\Service\RunOrchestrator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Symfony\Component\Uid\Uuid;

/**
 * Transitions an APPROVED run into TRANSFERRING and spins up the initial
 * pool of TransferWorkerJob instances. Each worker re-enqueues exactly one
 * replacement job after finishing a file, so the pool size stays constant
 * at the configured concurrency without needing an external queue.
 */
class EnqueueTransfersJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private RunOrchestrator $runOrchestrator,
		private IJobList $jobList,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$runId = (int)$argument['runId'];

		try {
			$run = $this->runOrchestrator->getRun($runId);
		} catch (DoesNotExistException) {
			return;
		}

		if (!in_array($run->getState(), [MigrationRun::STATE_APPROVED, MigrationRun::STATE_TRANSFERRING], true)) {
			return;
		}

		$this->runOrchestrator->beginTransferring($runId);

		$workers = $this->runOrchestrator->getConcurrentWorkers();
		for ($i = 0; $i < $workers; $i++) {
			$this->jobList->add(TransferWorkerJob::class, ['runId' => $runId, 'workerToken' => Uuid::v4()->toRfc4122()]);
		}
	}
}
