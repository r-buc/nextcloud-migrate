<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\BackgroundJob;

use OCA\NextcloudMigrate\Db\MigrationFile;
use OCA\NextcloudMigrate\Db\MigrationFileMapper;
use OCA\NextcloudMigrate\Db\MigrationRun;
use OCA\NextcloudMigrate\Db\MigrationRunMapper;
use OCA\NextcloudMigrate\Db\RemoteInstanceMapper;
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

	public function __construct(
		ITimeFactory $time,
		private MigrationRunMapper $runMapper,
		private MigrationFileMapper $fileMapper,
		private RemoteInstanceMapper $instanceMapper,
		private CredentialService $credentialService,
		private VerificationService $verificationService,
		private RunOrchestrator $runOrchestrator,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$runId = (int)$argument['runId'];
		$workerToken = (string)($argument['workerToken'] ?? UuidGenerator::v4());

		try {
			$run = $this->runMapper->find($runId);
		} catch (DoesNotExistException) {
			return;
		}

		if ($run->getState() !== MigrationRun::STATE_VERIFYING) {
			return;
		}

		$now = time();
		$candidates = $this->fileMapper->findVerifiable($runId, $now, 1);

		if ($candidates === []) {
			if ($this->hasInFlightVerifications($runId)) {
				$this->jobList->add(self::class, ['runId' => $runId, 'workerToken' => $workerToken], $now + self::IDLE_REQUEUE_DELAY_SECONDS);
				return;
			}
			$this->runOrchestrator->onVerificationPoolIdle($runId);
			return;
		}

		$candidate = $candidates[0];
		if (!$this->fileMapper->tryAcquireLock($candidate->getId(), $workerToken, self::LOCK_TTL_SECONDS, MigrationFile::STATE_VERIFYING)) {
			$this->jobList->add(self::class, ['runId' => $runId, 'workerToken' => $workerToken]);
			return;
		}

		$file = $this->fileMapper->find($candidate->getId());

		try {
			$instance = $this->instanceMapper->find($run->getInstanceId());
			$appPassword = $this->credentialService->decrypt($instance->getAppPasswordEncrypted());
			$this->verificationService->verifyFile($file, $instance, $appPassword);
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

		$this->jobList->add(self::class, ['runId' => $runId, 'workerToken' => $workerToken]);
	}

	private function hasInFlightVerifications(int $runId): bool {
		$counts = $this->fileMapper->countByState($runId);

		return ($counts[MigrationFile::STATE_VERIFYING] ?? 0) > 0;
	}
}
