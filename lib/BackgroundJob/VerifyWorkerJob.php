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
use OCA\NextcloudMigrate\Service\RunOrchestrator;
use OCA\NextcloudMigrate\Service\VerificationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use OCA\NextcloudMigrate\Util\UuidGenerator;

/**
 * Self-perpetuating verification worker, mirroring TransferWorkerJob: claims
 * the next transferred file, confirms its checksum against the target, and
 * re-enqueues itself. Hands off to
 * RunOrchestrator::onVerificationPoolIdle() once the pool drains.
 */
class VerifyWorkerJob extends QueuedJob {
	private const LOCK_TTL_SECONDS = 300;
	private const IDLE_REQUEUE_DELAY_SECONDS = 5;
	// How long to sleep between internal retries when nothing is
	// immediately verifiable but something is still marked in-flight (see
	// the comment where this is used, in run()).
	private const IDLE_POLL_SECONDS = 3;

	public function __construct(
		ITimeFactory $time,
		private MigrationRunMapper $runMapper,
		private MigrationFileMapper $fileMapper,
		private RemoteInstanceMapper $instanceMapper,
		private UserMapMapper $userMapMapper,
		private CredentialService $credentialService,
		private VerificationService $verificationService,
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
		// handling exactly one file per job execution - see the matching
		// comment in TransferWorkerJob::run() for why.
		$deadline = time() + $this->runOrchestrator->getBatchSeconds();
		$processed = 0;

		do {
			try {
				$run = $this->runMapper->find($runId);
			} catch (DoesNotExistException) {
				return;
			} catch (\Throwable $e) {
				$this->logger->warning('VerifyWorkerJob could not load run; dropping stale/invalid job', [
					'app' => 'nextcloud_migrate',
					'runId' => $runId,
					'exception' => $e,
				]);
				return;
			}

			if ($run->getState() !== MigrationRun::STATE_VERIFYING) {
				return;
			}

			$now = time();
			$candidates = $this->fileMapper->findVerifiable($runId, $now, 1);

			if ($candidates === []) {
				if ($this->hasInFlightVerifications($runId)) {
					// Something is still marked VERIFYING - almost always a
					// crashed worker's orphaned lock rather than genuine
					// concurrency (this app runs a single sequential worker by
					// default), so it will clear once the lock expires or
					// CleanupLocksJob reclaims it. Poll again shortly *within
					// this same batch* rather than yielding back to the job
					// queue - see the matching comment in
					// TransferWorkerJob::run() for why.
					if (time() < $deadline) {
						sleep(self::IDLE_POLL_SECONDS);
						continue;
					}
					// A fresh token per re-enqueue (not the current $workerToken)
					// is required - see the note above the final re-enqueue below.
					$this->jobList->add(self::class, ['runId' => $runId, 'workerToken' => UuidGenerator::v4()], time() + self::IDLE_REQUEUE_DELAY_SECONDS);
					return;
				}
				$this->runOrchestrator->onVerificationPoolIdle($runId);
				return;
			}

			$candidate = $candidates[0];
			if (!$this->fileMapper->tryAcquireLock($candidate->getId(), $workerToken, self::LOCK_TTL_SECONDS, MigrationFile::STATE_VERIFYING)) {
				// Lost the race to another worker for this row; just try
				// the next candidate within this same batch immediately.
				continue;
			}

			$file = $this->fileMapper->find($candidate->getId());

			try {
				$instance = $this->instanceMapper->find($run->getInstanceId());
				$userMap = $this->userMapMapper->find($file->getUserMapId());
				$appPassword = $this->credentialService->decrypt($userMap->getTargetAppPasswordEncrypted());
				$this->verificationService->verifyFile($file, $instance, $userMap->getTargetUserId(), $appPassword);
			} catch (\Throwable $e) {
				$this->logger->error('Verify worker failed unexpectedly', [
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
		// required - see the detailed comment in TransferWorkerJob's final
		// re-enqueue for why a stable argument here would silently cap
		// throughput and can abort cron.php's whole run early.
		$this->logger->debug("VerifyWorkerJob batch processed {$processed} file(s) for run {$runId} before yielding", [
			'app' => 'nextcloud_migrate',
		]);
		$this->jobList->add(self::class, ['runId' => $runId, 'workerToken' => UuidGenerator::v4()]);
	}

	private function hasInFlightVerifications(int $runId): bool {
		$counts = $this->fileMapper->countByState($runId);

		return ($counts[MigrationFile::STATE_VERIFYING] ?? 0) > 0;
	}

	private function extractRunId($argument): ?int {
		if (!is_array($argument) || !isset($argument['runId']) || !is_numeric($argument['runId'])) {
			$this->logger->warning('VerifyWorkerJob invoked with missing/invalid runId argument; skipping', [
				'app' => 'nextcloud_migrate',
			]);
			return null;
		}

		return (int)$argument['runId'];
	}
}
