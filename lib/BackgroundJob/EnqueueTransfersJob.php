<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\BackgroundJob;

use OCA\NextcloudMigrate\Db\MigrationRun;
use OCA\NextcloudMigrate\Db\UserMap;
use OCA\NextcloudMigrate\Db\UserMapMapper;
use OCA\NextcloudMigrate\Service\RunOrchestrator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCA\NextcloudMigrate\Util\JobScheduling;
use OCA\NextcloudMigrate\Util\UuidGenerator;
use Psr\Log\LoggerInterface;

/**
 * Transitions an APPROVED run into TRANSFERRING and spawns one
 * TransferWorkerJob per mapped user (skipping any user whose discovery
 * failed). Each user's worker lineage re-enqueues itself under that same
 * user until that user's files are exhausted, so it only ever authenticates
 * as one target user for its whole lifetime - WebDavClient's connection
 * never has to switch users mid-job. The run only moves on to VERIFYING
 * once every user's lineage has drained (see
 * RunOrchestrator::onUserTransferComplete()).
 */
class EnqueueTransfersJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private RunOrchestrator $runOrchestrator,
		private UserMapMapper $userMapMapper,
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

		$spawned = 0;
		foreach ($this->userMapMapper->findByRun($runId) as $userMap) {
			if ($userMap->getState() === UserMap::STATE_FAILED) {
				continue;
			}
			// Backdated firstCheck so these get run within the SAME cron.php
			// pass that's executing this job, instead of possibly losing a
			// last_checked tie-break against an already-executed job this
			// pass (see JobScheduling::IMMEDIATE_FIRST_CHECK) and sitting
			// idle for a full cron interval before their first execution.
			$this->jobList->add(TransferWorkerJob::class, ['runId' => $runId, 'userMapId' => $userMap->getId(), 'workerToken' => UuidGenerator::v4()], JobScheduling::IMMEDIATE_FIRST_CHECK);
			$spawned++;
		}

		if ($spawned === 0) {
			// Every mapped user failed discovery (or there are none) - nothing
			// to transfer, so nothing will ever call back in to advance the
			// run. Move it along directly instead of leaving it stuck in
			// TRANSFERRING forever.
			$this->runOrchestrator->onTransferPoolIdle($runId);
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
