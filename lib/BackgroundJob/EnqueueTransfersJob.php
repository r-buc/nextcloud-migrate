<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\BackgroundJob;

use OCA\NextcloudMigrate\Db\MigrationRun;
use OCA\NextcloudMigrate\Service\RunOrchestrator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCA\NextcloudMigrate\Util\UuidGenerator;
use Psr\Log\LoggerInterface;

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
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$runId = $this->extractRunId($argument);
		if ($runId === null) {
			return;
		}

		try {
			$run = $this->runOrchestrator->getRun($runId);
		} catch (DoesNotExistException) {
			return;
		} catch (\Throwable $e) {
			$this->logger->warning('EnqueueTransfersJob could not load run; dropping stale/invalid job', [
				'app' => 'nextcloud_migrate',
				'runId' => $runId,
				'exception' => $e,
			]);
			return;
		}

		if (!in_array($run->getState(), [MigrationRun::STATE_APPROVED, MigrationRun::STATE_TRANSFERRING], true)) {
			return;
		}

		$this->runOrchestrator->beginTransferring($runId);

		$workers = $this->runOrchestrator->getConcurrentWorkers();
		for ($i = 0; $i < $workers; $i++) {
			$this->jobList->add(TransferWorkerJob::class, ['runId' => $runId, 'workerToken' => UuidGenerator::v4()]);
		}
	}

	private function extractRunId($argument): ?int {
		if (!is_array($argument) || !isset($argument['runId']) || !is_numeric($argument['runId'])) {
			$this->logger->warning('EnqueueTransfersJob invoked with missing/invalid runId argument; skipping', [
				'app' => 'nextcloud_migrate',
			]);
			return null;
		}

		return (int)$argument['runId'];
	}
}
