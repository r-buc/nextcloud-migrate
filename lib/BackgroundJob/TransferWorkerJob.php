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
use OCA\NextcloudMigrate\Util\JobScheduling;
use OCA\NextcloudMigrate\Util\UuidGenerator;

/**
 * A self-perpetuating worker scoped to a single mapped user for its entire
 * lifetime (see EnqueueTransfersJob, which spawns one of these per mapped
 * user): claims the next transferable file for that user, maps it
 * (collision detection against the target happens here, inline, rather
 * than as a separate bulk pre-pass) and transfers it, then re-enqueues
 * itself to pick up the next one. Because a lineage never switches target
 * users, it fetches that user's credentials/target instance once and
 * WebDavClient never has to tear down/reopen its connection mid-job. When
 * this user's pool runs dry it hands off to
 * RunOrchestrator::onUserTransferComplete().
 */
class TransferWorkerJob extends QueuedJob {
	private const LOCK_TTL_SECONDS = 600;
	private const IDLE_REQUEUE_DELAY_SECONDS = 5;
	// How long to sleep between internal retries when nothing is
	// immediately transferable but something is still marked in-flight
	// (see the comment where this is used, in run()).
	private const IDLE_POLL_SECONDS = 3;

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
		$userMapId = $this->extractUserMapId($argument);
		if ($userMapId === null) {
			return;
		}
		$workerToken = (string)($argument['workerToken'] ?? UuidGenerator::v4());

		try {
			$userMap = $this->userMapMapper->find($userMapId);
		} catch (DoesNotExistException) {
			return;
		} catch (\Throwable $e) {
			$this->logger->warning('TransferWorkerJob could not load user map; dropping stale/invalid job', [
				'app' => 'nextcloud_migrate',
				'runId' => $runId,
				'userMapId' => $userMapId,
				'exception' => $e,
			]);
			return;
		}

		// Process files in a loop for up to getBatchSeconds() rather than
		// handling exactly one file per job execution - re-enqueuing after
		// every single file wastes most of cron.php's per-invocation time
		// budget on job-queue churn instead of actual transfer work.
		$deadline = time() + $this->runOrchestrator->getBatchSeconds();
		$processed = 0;
		// Resolved once (not per file): this lineage only ever transfers
		// for $userMap, so the target instance/credentials never change.
		$instance = null;
		$appPassword = null;

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

			if (!in_array($run->getState(), [MigrationRun::STATE_APPROVED, MigrationRun::STATE_TRANSFERRING, MigrationRun::STATE_SYNCING], true)) {
				// Paused, cancelled, or already moved past transferring: this
				// worker lineage stops here (no re-enqueue). SYNCING is
				// included: continuous sync (see RunOrchestrator::runSyncPass())
				// spawns this same job to transfer newly-discovered/changed
				// files without ever moving the run out of SYNCING.
				return;
			}

			if ($instance === null) {
				try {
					$instance = $this->instanceMapper->find($run->getInstanceId());
					$appPassword = $this->credentialService->decrypt($userMap->getTargetAppPasswordEncrypted());
				} catch (\Throwable $e) {
					$this->logger->error('TransferWorkerJob could not resolve target instance/credentials; dropping job', [
						'app' => 'nextcloud_migrate',
						'runId' => $runId,
						'userMapId' => $userMapId,
						'exception' => $e,
					]);
					return;
				}
			}

			$now = time();
			$candidates = $this->fileMapper->findTransferable($runId, $now, 1, $userMapId);

			if ($candidates === []) {
				if ($this->hasInFlightTransfers($runId, $userMapId)) {
					// Something of this user's is still marked TRANSFERRING -
					// almost always a crashed worker's orphaned lock (each
					// user only ever has one active lineage), so it will
					// clear once the lock expires or CleanupLocksJob reclaims
					// it. Poll again shortly *within this same batch* rather
					// than yielding back to the job queue: cron.php does not
					// wait for a delayed re-enqueue to become due - it exits
					// the moment nothing is due right now - so bailing out
					// here would strand this user's transfer until the next
					// scheduled cron tick (commonly ~5 minutes) for what
					// might only be a few seconds' wait.
					if (time() < $deadline) {
						sleep(self::IDLE_POLL_SECONDS);
						continue;
					}
					// A fresh token per re-enqueue (not the current $workerToken)
					// is required - see the note above the final re-enqueue below.
					$this->jobList->add(self::class, ['runId' => $runId, 'userMapId' => $userMapId, 'workerToken' => UuidGenerator::v4()], time() + self::IDLE_REQUEUE_DELAY_SECONDS);
					return;
				}
				$this->runOrchestrator->onUserTransferComplete($runId, $userMapId);
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
		// already executed this pass, so reusing the same row per lineage
		// would cap throughput at one batch's worth of files per cron
		// invocation, however much of the 14-minute budget remained. The
		// backdated firstCheck similarly ensures this new row is picked up
		// within the SAME pass rather than losing a last_checked tie-break
		// (see JobScheduling::IMMEDIATE_FIRST_CHECK).
		$this->logger->debug("TransferWorkerJob batch processed {$processed} file(s) for user map {$userMapId} (run {$runId}) before yielding", [
			'app' => 'nextcloud_migrate',
		]);
		$this->jobList->add(self::class, ['runId' => $runId, 'userMapId' => $userMapId, 'workerToken' => UuidGenerator::v4()], JobScheduling::IMMEDIATE_FIRST_CHECK);
	}

	private function hasInFlightTransfers(int $runId, int $userMapId): bool {
		$counts = $this->fileMapper->countByState($runId, $userMapId);

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

	private function extractUserMapId($argument): ?int {
		if (!is_array($argument) || !isset($argument['userMapId']) || !is_numeric($argument['userMapId'])) {
			$this->logger->warning('TransferWorkerJob invoked with missing/invalid userMapId argument; skipping', [
				'app' => 'nextcloud_migrate',
			]);
			return null;
		}

		return (int)$argument['userMapId'];
	}
}
