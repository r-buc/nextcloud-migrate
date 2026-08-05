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
		$runId = (int)$argument['runId'];
		$workerToken = (string)($argument['workerToken'] ?? UuidGenerator::v4());

		try {
			$run = $this->runMapper->find($runId);
		} catch (DoesNotExistException) {
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
				$this->jobList->add(self::class, ['runId' => $runId, 'workerToken' => $workerToken], $now + self::IDLE_REQUEUE_DELAY_SECONDS);
				return;
			}
			$this->runOrchestrator->onTransferPoolIdle($runId);
			return;
		}

		$candidate = $candidates[0];
		if (!$this->fileMapper->tryAcquireLock($candidate->getId(), $workerToken, self::LOCK_TTL_SECONDS, MigrationFile::STATE_TRANSFERRING)) {
			// Lost the race to another worker for this row; try again now.
			$this->jobList->add(self::class, ['runId' => $runId, 'workerToken' => $workerToken]);
			return;
		}

		$file = $this->fileMapper->find($candidate->getId());

		try {
			$instance = $this->instanceMapper->find($run->getInstanceId());
			$appPassword = $this->credentialService->decrypt($instance->getAppPasswordEncrypted());

			$this->mappingService->mapFile($file, $instance, $appPassword, $run->getCollisionStrategy());

			if ($file->getState() === MigrationFile::STATE_MAPPED) {
				$userMap = $this->userMapMapper->find($file->getUserMapId());
				if ($file->getIsDirectory()) {
					$this->transferService->transferDirectory($file, $instance, $appPassword);
				} else {
					$this->transferService->transferFile($file, $instance, $appPassword, $userMap->getSourceUserId());
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

		$this->jobList->add(self::class, ['runId' => $runId, 'workerToken' => $workerToken]);
	}

	private function hasInFlightTransfers(int $runId): bool {
		$counts = $this->fileMapper->countByState($runId);

		return ($counts[MigrationFile::STATE_TRANSFERRING] ?? 0) > 0;
	}
}
