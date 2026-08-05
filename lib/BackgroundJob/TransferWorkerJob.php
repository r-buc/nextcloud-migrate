<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\BackgroundJob;

use OCA\NextcloudMigrate\Db\MigrationFile;
use OCA\NextcloudMigrate\Db\MigrationFileMapper;
use OCA\NextcloudMigrate\Db\MigrationRun;
use OCA\NextcloudMigrate\Db\MigrationRunMapper;
use OCA\NextcloudMigrate\Db\RemoteInstanceMapper;
use OCA\NextcloudMigrate\Db\UserMapMapper;
use OCA\NextcloudMigrate\Service\CredentialService;
use OCA\NextcloudMigrate\Service\MappingService;
use OCA\NextcloudMigrate\Service\RunOrchestrator;
use OCA\NextcloudMigrate\Service\TransferService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use OCA\NextcloudMigrate\Util\UuidGenerator;

/**
 * A single self-perpetuating worker: claims the next transferable file for a
 * run, maps it (collision detection against the target happens here, inline,
 * rather than as a separate bulk pre-pass) and transfers it, then
 * re-enqueues itself to pick up the next one. When the pool runs dry it
 * hands off to RunOrchestrator::onTransferPoolIdle().
 */
class TransferWorkerJob extends QueuedJob {
	private const LOCK_TTL_SECONDS = 600;
	private const IDLE_REQUEUE_DELAY_SECONDS = 5;

	public function __construct(
		ITimeFactory $time,
		private MigrationRunMapper $runMapper,
		private MigrationFileMapper $fileMapper,
		private UserMapMapper $userMapMapper,
		private RemoteInstanceMapper $instanceMapper,
		private CredentialService $credentialService,
		private MappingService $mappingService,
		private TransferService $transferService,
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
		$workerToken = (string)($argument['workerToken'] ?? UuidGenerator::v4());

		// Process files in a loop for up to getBatchSeconds() rather than
		// handling exactly one file per job execution - re-enqueuing after
		// every single file wastes most of cron.php's per-invocation time
		// budget on job-queue churn instead of actual transfer work.
		$deadline = time() + $this->runOrchestrator->getBatchSeconds();
		$processed = 0;

		do {
			try {
				$run = $this->runMapper->find($runId);
			} catch (DoesNotExistException) {
				return;
			} catch (\Throwable $e) {
				$this->logger->warning('TransferWorkerJob could not load run; dropping stale/invalid job', [
					'app' => 'nextcloud_migrate',
					'runId' => $runId,
					'exception' => $e,
				]);
				return;
			}

			if (!in_array($run->getState(), [MigrationRun::STATE_APPROVED, MigrationRun::STATE_TRANSFERRING], true)) {
				// Paused, cancelled, or already moved past transferring: this
				// worker lineage stops here (no re-enqueue).
				return;
			}

			$now = time();
			$candidates = $this->fileMapper->findTransferable($runId, $now, 1);

			if ($candidates === []) {
				if ($this->hasInFlightTransfers($runId)) {
					// A fresh token per re-enqueue (not the current $workerToken)
					// is required - see the note above the final re-enqueue below.
					$this->jobList->add(self::class, ['runId' => $runId, 'workerToken' => UuidGenerator::v4()], $now + self::IDLE_REQUEUE_DELAY_SECONDS);
					return;
				}
				$this->runOrchestrator->onTransferPoolIdle($runId);
				return;
			}

			$candidate = $candidates[0];
			if (!$this->fileMapper->tryAcquireLock($candidate->getId(), $workerToken, self::LOCK_TTL_SECONDS, MigrationFile::STATE_TRANSFERRING)) {
				// Lost the race to another worker for this row; just try
				// the next candidate within this same batch immediately.
				continue;
			}

			$file = $this->fileMapper->find($candidate->getId());

			try {
				$instance = $this->instanceMapper->find($run->getInstanceId());
				$userMap = $this->userMapMapper->find($file->getUserMapId());
				$appPassword = $this->credentialService->decrypt($userMap->getTargetAppPasswordEncrypted());

				$this->mappingService->mapFile($file, $instance, $userMap->getTargetUserId(), $appPassword, $run->getCollisionStrategy());

				if ($file->getState() === MigrationFile::STATE_MAPPED) {
					if ($file->getIsDirectory()) {
						$this->transferService->transferDirectory($file, $instance, $userMap->getTargetUserId(), $appPassword);
					} else {
						$this->transferService->transferFile($file, $instance, $userMap->getTargetUserId(), $appPassword, $userMap->getSourceUserId());
					}
				} else {
					// SKIPPED or MAPPING_FAILED: terminal, just release the lock.
					$file->setLockOwner(null);
					$file->setLockExpiresAt(null);
					$file->setUpdatedAt(time());
					$this->fileMapper->update($file);
				}
			} catch (\Throwable $e) {
				$this->logger->error('Transfer worker failed unexpectedly', [
					'app' => 'nextcloud_migrate',
					'runId' => $runId,
					'fileId' => $file->getId(),
					'exception' => $e,
				]);
				$file->setLockOwner(null);
				$file->setLockExpiresAt(null);
				$file->setUpdatedAt(time());
				$this->fileMapper->update($file);
			}

			$processed++;
		} while (time() < $deadline);

		// Batch time budget exhausted with more work potentially remaining.
		// A fresh token per re-enqueue (rather than reusing $workerToken) is
		// required: IJobList::add() dedupes by class+argument, so a stable
		// argument would just update the *same* jobs-table row in place
		// instead of inserting a new one. cron.php aborts its entire run
		// (not just this job) the moment getNext() returns a row id it
		// already executed this pass, so a fixed-size worker pool reusing
		// the same row per lineage would cap throughput at exactly
		// concurrent_workers files per cron invocation, however much of the
		// 14-minute budget remained.
		$this->logger->debug("TransferWorkerJob batch processed {$processed} file(s) for run {$runId} before yielding", [
			'app' => 'nextcloud_migrate',
		]);
		$this->jobList->add(self::class, ['runId' => $runId, 'workerToken' => UuidGenerator::v4()]);
	}

	private function hasInFlightTransfers(int $runId): bool {
		$counts = $this->fileMapper->countByState($runId);

		return ($counts[MigrationFile::STATE_TRANSFERRING] ?? 0) > 0;
	}

	private function extractRunId($argument): ?int {
		if (!is_array($argument) || !isset($argument['runId']) || !is_numeric($argument['runId'])) {
			$this->logger->warning('TransferWorkerJob invoked with missing/invalid runId argument; skipping', [
				'app' => 'nextcloud_migrate',
			]);
			return null;
		}

		return (int)$argument['runId'];
	}
}
