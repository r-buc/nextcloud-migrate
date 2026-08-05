<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service;

use OCA\NextcloudMigrate\BackgroundJob\DiscoveryJob;
use OCA\NextcloudMigrate\BackgroundJob\EnqueueTransfersJob;
use OCA\NextcloudMigrate\BackgroundJob\FinalizeJob;
use OCA\NextcloudMigrate\BackgroundJob\VerifyWorkerJob;
use OCA\NextcloudMigrate\Db\MigrationFile;
use OCA\NextcloudMigrate\Db\MigrationFileMapper;
use OCA\NextcloudMigrate\Db\MigrationRun;
use OCA\NextcloudMigrate\Db\MigrationRunMapper;
use OCA\NextcloudMigrate\Db\RemoteInstance;
use OCA\NextcloudMigrate\Db\RemoteInstanceMapper;
use OCA\NextcloudMigrate\Db\UserMap;
use OCA\NextcloudMigrate\Db\UserMapMapper;
use OCA\NextcloudMigrate\Exception\RemoteConnectionException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCA\NextcloudMigrate\Util\UuidGenerator;

/**
 * Owns the migration_runs state machine and coordinates the transitions
 * between discovery, transfer, verification and finalization. Individual
 * background jobs call back into this class rather than mutating run state
 * directly, so the transition graph lives in exactly one place.
 *
 * State graph:
 *   CREATED -> VALIDATING -> DISCOVERING -> DRY_RUN_READY -> APPROVED
 *     -> TRANSFERRING -> VERIFYING -> FINALIZING -> COMPLETED | COMPLETED_WITH_ERRORS
 *   Any of VALIDATING/APPROVED/TRANSFERRING/VERIFYING -> VALIDATION_FAILED | FAILED
 *   Any active state -> PAUSED -> (resume) back to its prior active state
 *   Any non-terminal state -> CANCELLED
 */
class RunOrchestrator {
	private const DEFAULT_CONCURRENT_WORKERS = 5;
	// How long a single TransferWorkerJob/VerifyWorkerJob execution keeps
	// claiming and processing files in a loop before re-enqueueing itself,
	// rather than handling exactly one file per execution. Bounded well
	// under cron.php's 14-minute per-invocation budget, and kept modest
	// enough to also be safe under ajax/webcron mode's per-request PHP
	// execution time limits.
	private const DEFAULT_BATCH_SECONDS = 20;

	public function __construct(
		private MigrationRunMapper $runMapper,
		private RemoteInstanceMapper $instanceMapper,
		private UserMapMapper $userMapMapper,
		private MigrationFileMapper $fileMapper,
		private WebDavClient $webDavClient,
		private ProvisioningClient $provisioningClient,
		private CredentialService $credentialService,
		private ReportService $reportService,
		private EventLogger $eventLogger,
		private IJobList $jobList,
		private IConfig $config,
	) {
	}

	/**
	 * @param array<array{sourceUserId: string, targetUserId: string, mode?: string, appPassword?: string}> $mappings
	 *        mode defaults to 'auto': the target user is created (if it
	 *        doesn't exist on the remote instance yet) or has its password
	 *        reset (if it does) via the OCS Provisioning API, using the
	 *        instance's admin credential - no manual per-user app password
	 *        needed. mode 'manual' ("expert mode") uses an app password the
	 *        admin already obtained from that specific target user instead,
	 *        without touching their account via the admin API at all.
	 * @throws \InvalidArgumentException
	 * @throws RemoteConnectionException if an 'auto' mapping's create/reset call fails
	 */
	public function createRun(
		string $createdBy,
		int $instanceId,
		string $collisionStrategy,
		array $mappings,
	): MigrationRun {
		if ($mappings === []) {
			throw new \InvalidArgumentException('At least one user mapping is required');
		}

		$instance = $this->instanceMapper->find($instanceId);
		$now = time();

		// Resolve every mapping's target app password up front - including
		// any admin-API create/reset calls - before creating any DB rows,
		// so a failure here doesn't leave a half-created run behind.
		$remoteUsers = null;
		$resolved = [];
		foreach ($mappings as $mapping) {
			$sourceUserId = (string)($mapping['sourceUserId'] ?? '');
			$targetUserId = (string)($mapping['targetUserId'] ?? '');
			$mode = (string)($mapping['mode'] ?? 'auto');
			if ($sourceUserId === '' || $targetUserId === '') {
				throw new \InvalidArgumentException('Each mapping requires sourceUserId and targetUserId');
			}

			if ($mode === 'manual') {
				$appPassword = (string)($mapping['appPassword'] ?? '');
				if ($appPassword === '') {
					throw new \InvalidArgumentException("An app password is required for manually-mapped user '{$targetUserId}'");
				}
			} elseif ($mode === 'auto') {
				if ($remoteUsers === null) {
					$adminPassword = $this->credentialService->decrypt($instance->getAdminAppPasswordEncrypted());
					$remoteUsers = array_flip($this->provisioningClient->listUsers($instance, $instance->getAdminUserId(), $adminPassword));
				}
				$adminPassword ??= $this->credentialService->decrypt($instance->getAdminAppPasswordEncrypted());
				$appPassword = bin2hex(random_bytes(24));
				if (isset($remoteUsers[$targetUserId])) {
					$this->provisioningClient->resetUserPassword($instance, $instance->getAdminUserId(), $adminPassword, $targetUserId, $appPassword);
				} else {
					$this->provisioningClient->createUser($instance, $instance->getAdminUserId(), $adminPassword, $targetUserId, $appPassword);
				}
			} else {
				throw new \InvalidArgumentException("Unknown mapping mode '{$mode}'");
			}

			$resolved[] = ['sourceUserId' => $sourceUserId, 'targetUserId' => $targetUserId, 'appPassword' => $appPassword];
		}

		$run = new MigrationRun();
		$run->setUuid(UuidGenerator::v4());
		$run->setInstanceId($instanceId);
		$run->setState(MigrationRun::STATE_CREATED);
		$run->setCollisionStrategy($collisionStrategy);
		$run->setTotalUsers(count($resolved));
		$run->setTotalFiles(0);
		$run->setTransferredFiles(0);
		$run->setVerifiedFiles(0);
		$run->setFailedFiles(0);
		$run->setTotalBytes(0);
		$run->setTransferredBytes(0);
		$run->setCreatedBy($createdBy);
		$run->setCreatedAt($now);
		$run->setUpdatedAt($now);
		$run = $this->runMapper->insert($run);

		foreach ($resolved as $entry) {
			$userMap = new UserMap();
			$userMap->setRunId($run->getId());
			$userMap->setSourceUserId($entry['sourceUserId']);
			$userMap->setTargetUserId($entry['targetUserId']);
			$userMap->setTargetAppPasswordEncrypted($this->credentialService->encrypt($entry['appPassword']));
			$userMap->setState(UserMap::STATE_PENDING);
			$userMap->setTotalFiles(0);
			$userMap->setTransferredFiles(0);
			$userMap->setFailedFiles(0);
			$userMap->setCreatedAt($now);
			$this->userMapMapper->insert($userMap);
		}

		$this->eventLogger->log($run->getId(), 'run_created', "Migration run created with {$run->getTotalUsers()} user(s)");

		return $run;
	}

	/**
	 * Validates connectivity to the target instance and, on success, kicks
	 * off discovery. This is synchronous (a single connectivity check is
	 * cheap) but discovery itself runs in DiscoveryJob.
	 */
	/**
	 * Validates connectivity to the target instance and every mapped
	 * user's own WebDAV credential, then kicks off discovery on success.
	 * This is synchronous (each check is cheap) but discovery itself runs
	 * in DiscoveryJob.
	 */
	public function startValidationAndDiscovery(int $runId): MigrationRun {
		$run = $this->runMapper->find($runId);
		$instance = $this->instanceMapper->find($run->getInstanceId());

		$run->setState(MigrationRun::STATE_VALIDATING);
		$run->setUpdatedAt(time());
		$this->runMapper->update($run);

		try {
			$this->webDavClient->testConnection($instance);

			foreach ($this->userMapMapper->findByRun($runId) as $userMap) {
				$appPassword = $this->credentialService->decrypt($userMap->getTargetAppPasswordEncrypted());
				try {
					$this->webDavClient->testUserCredentials($instance, $userMap->getTargetUserId(), $appPassword);
				} catch (RemoteConnectionException $e) {
					throw new RemoteConnectionException("Credential check failed for target user '{$userMap->getTargetUserId()}': " . $e->getMessage(), $e->getCode(), $e);
				}
			}
		} catch (RemoteConnectionException $e) {
			$run->setState(MigrationRun::STATE_VALIDATION_FAILED);
			$run->setErrorMessage('Could not validate target instance: ' . $e->getMessage());
			$run->setUpdatedAt(time());
			$this->runMapper->update($run);
			$this->eventLogger->log($runId, 'validation_failed', $run->getErrorMessage(), 'error');

			return $run;
		}

		$this->eventLogger->log($runId, 'validation_succeeded', 'Target instance reachable and all mapped users\' credentials valid');

		$run->setState(MigrationRun::STATE_DISCOVERING);
		$run->setUpdatedAt(time());
		$this->runMapper->update($run);

		$this->jobList->add(DiscoveryJob::class, ['runId' => $runId]);

		return $run;
	}

	/**
	 * Called by DiscoveryJob once every user mapping has been discovered.
	 */
	public function onDiscoveryComplete(int $runId): void {
		$run = $this->runMapper->find($runId);

		$counts = $this->fileMapper->countByState($runId);
		$run->setTotalFiles((int)($counts[MigrationFile::STATE_DISCOVERED] ?? 0));
		$run->setTotalBytes($this->fileMapper->sumDiscoveredBytes($runId));

		$run->setState(MigrationRun::STATE_DRY_RUN_READY);
		$run->setDryRunReport(json_encode($this->reportService->buildDryRunReport($run)));
		$run->setUpdatedAt(time());
		$this->runMapper->update($run);

		$this->eventLogger->log($runId, 'discovery_completed', "Discovery finished: {$run->getTotalFiles()} item(s) found");
	}

	public function approveRun(int $runId, string $approvedBy): MigrationRun {
		$run = $this->runMapper->find($runId);
		if ($run->getState() !== MigrationRun::STATE_DRY_RUN_READY) {
			throw new \RuntimeException("Run must be in '" . MigrationRun::STATE_DRY_RUN_READY . "' state to approve (currently '{$run->getState()}')");
		}

		$now = time();
		$run->setState(MigrationRun::STATE_APPROVED);
		$run->setApprovedBy($approvedBy);
		$run->setApprovedAt($now);
		$run->setStartedAt($now);
		$run->setUpdatedAt($now);
		$this->runMapper->update($run);

		$this->eventLogger->log($runId, 'run_approved', "Run approved by {$approvedBy}");

		$this->jobList->add(EnqueueTransfersJob::class, ['runId' => $runId]);

		return $run;
	}

	public function beginTransferring(int $runId): void {
		$run = $this->runMapper->find($runId);
		if ($run->getState() === MigrationRun::STATE_APPROVED) {
			$run->setState(MigrationRun::STATE_TRANSFERRING);
			$run->setUpdatedAt(time());
			$this->runMapper->update($run);
		}
	}

	public function getConcurrentWorkers(): int {
		return (int)$this->config->getAppValue('nextcloud_migrate', 'concurrent_workers', (string)self::DEFAULT_CONCURRENT_WORKERS);
	}

	/**
	 * How many seconds a single TransferWorkerJob/VerifyWorkerJob execution
	 * should keep claiming and processing files before yielding back (by
	 * re-enqueueing itself with a fresh token) instead of doing exactly one
	 * file per execution. Configurable via `occ config:app:set
	 * nextcloud_migrate batch_seconds --value=N` for deployments that only
	 * use system cron (where longer batches reduce per-file job-queue
	 * overhead further).
	 */
	public function getBatchSeconds(): int {
		return (int)$this->config->getAppValue('nextcloud_migrate', 'batch_seconds', (string)self::DEFAULT_BATCH_SECONDS);
	}

	/**
	 * Called by a TransferWorkerJob when it finds no more transferable files
	 * and no other files still in-flight for the run.
	 */
	public function onTransferPoolIdle(int $runId): void {
		$run = $this->runMapper->find($runId);
		if (!in_array($run->getState(), [MigrationRun::STATE_TRANSFERRING, MigrationRun::STATE_APPROVED], true)) {
			return;
		}

		$counts = $this->fileMapper->countByState($runId);
		$this->refreshRunCounters($run, $counts);

		$run->setState(MigrationRun::STATE_VERIFYING);
		$run->setUpdatedAt(time());
		$this->runMapper->update($run);

		$this->eventLogger->log($runId, 'transfer_completed', 'All transferable files processed; starting verification');

		for ($i = 0; $i < $this->getConcurrentWorkers(); $i++) {
			$this->jobList->add(VerifyWorkerJob::class, ['runId' => $runId, 'workerToken' => UuidGenerator::v4()]);
		}
	}

	/**
	 * Called by a VerifyWorkerJob when no more files need verification.
	 */
	public function onVerificationPoolIdle(int $runId): void {
		$run = $this->runMapper->find($runId);
		if ($run->getState() !== MigrationRun::STATE_VERIFYING) {
			return;
		}

		$run->setState(MigrationRun::STATE_FINALIZING);
		$run->setUpdatedAt(time());
		$this->runMapper->update($run);

		$this->eventLogger->log($runId, 'verification_completed', 'Verification pool drained; finalizing run');

		$this->jobList->add(FinalizeJob::class, ['runId' => $runId]);
	}

	public function finalizeRun(int $runId): void {
		$run = $this->runMapper->find($runId);
		$counts = $this->fileMapper->countByState($runId);
		$this->refreshRunCounters($run, $counts);

		$failedStates = [MigrationFile::STATE_TRANSFER_FAILED, MigrationFile::STATE_VERIFICATION_FAILED, MigrationFile::STATE_MAPPING_FAILED];
		$hasFailures = false;
		foreach ($failedStates as $s) {
			if (($counts[$s] ?? 0) > 0) {
				$hasFailures = true;
				break;
			}
		}

		$run->setState($hasFailures ? MigrationRun::STATE_COMPLETED_WITH_ERRORS : MigrationRun::STATE_COMPLETED);
		$run->setFinalReport(json_encode($this->reportService->buildFinalReport($run)));
		$run->setFinishedAt(time());
		$run->setUpdatedAt(time());
		$this->runMapper->update($run);

		$this->eventLogger->log($runId, 'run_finished', "Run finished with state {$run->getState()}");
	}

	public function pauseRun(int $runId): MigrationRun {
		$run = $this->runMapper->find($runId);
		$activeStates = [
			MigrationRun::STATE_VALIDATING,
			MigrationRun::STATE_DISCOVERING,
			MigrationRun::STATE_APPROVED,
			MigrationRun::STATE_TRANSFERRING,
			MigrationRun::STATE_VERIFYING,
		];
		if (!in_array($run->getState(), $activeStates, true)) {
			throw new \RuntimeException("Run cannot be paused from state '{$run->getState()}'");
		}

		$run->setState(MigrationRun::STATE_PAUSED);
		$run->setUpdatedAt(time());
		$this->runMapper->update($run);
		$this->eventLogger->log($runId, 'run_paused', 'Run paused by admin');

		return $run;
	}

	public function resumeRun(int $runId): MigrationRun {
		$run = $this->runMapper->find($runId);
		if ($run->getState() !== MigrationRun::STATE_PAUSED) {
			throw new \RuntimeException('Run is not paused');
		}

		$counts = $this->fileMapper->countByState($runId);
		$transferableRemaining = ($counts[MigrationFile::STATE_DISCOVERED] ?? 0) + ($counts[MigrationFile::STATE_TRANSFER_FAILED] ?? 0);
		$verifiableRemaining = ($counts[MigrationFile::STATE_TRANSFERRED] ?? 0) + ($counts[MigrationFile::STATE_VERIFICATION_FAILED] ?? 0);

		if ($transferableRemaining > 0) {
			$run->setState(MigrationRun::STATE_TRANSFERRING);
			$this->runMapper->update($run);
			$this->jobList->add(EnqueueTransfersJob::class, ['runId' => $runId]);
		} elseif ($verifiableRemaining > 0) {
			$run->setState(MigrationRun::STATE_VERIFYING);
			$this->runMapper->update($run);
			for ($i = 0; $i < $this->getConcurrentWorkers(); $i++) {
				$this->jobList->add(VerifyWorkerJob::class, ['runId' => $runId, 'workerToken' => UuidGenerator::v4()]);
			}
		} else {
			$run->setState(MigrationRun::STATE_FINALIZING);
			$this->runMapper->update($run);
			$this->jobList->add(FinalizeJob::class, ['runId' => $runId]);
		}

		$this->eventLogger->log($runId, 'run_resumed', "Run resumed into state {$run->getState()}");

		return $run;
	}

	public function cancelRun(int $runId): MigrationRun {
		$run = $this->runMapper->find($runId);
		$terminal = [MigrationRun::STATE_COMPLETED, MigrationRun::STATE_COMPLETED_WITH_ERRORS, MigrationRun::STATE_CANCELLED, MigrationRun::STATE_FAILED];
		if (in_array($run->getState(), $terminal, true)) {
			throw new \RuntimeException("Run is already in a terminal state '{$run->getState()}'");
		}

		$run->setState(MigrationRun::STATE_CANCELLED);
		$run->setFinishedAt(time());
		$run->setUpdatedAt(time());
		$this->runMapper->update($run);
		$this->eventLogger->log($runId, 'run_cancelled', 'Run cancelled by admin', 'warning');

		return $run;
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function getRun(int $runId): MigrationRun {
		return $this->runMapper->find($runId);
	}

	public function getInstance(int $instanceId): RemoteInstance {
		return $this->instanceMapper->find($instanceId);
	}

	/**
	 * @param array<string,int> $counts
	 */
	private function refreshRunCounters(MigrationRun $run, array $counts): void {
		$run->setTransferredFiles(($counts[MigrationFile::STATE_TRANSFERRED] ?? 0) + ($counts[MigrationFile::STATE_VERIFIED] ?? 0));
		$run->setVerifiedFiles($counts[MigrationFile::STATE_VERIFIED] ?? 0);
		$run->setFailedFiles(
			($counts[MigrationFile::STATE_TRANSFER_FAILED] ?? 0)
			+ ($counts[MigrationFile::STATE_VERIFICATION_FAILED] ?? 0)
			+ ($counts[MigrationFile::STATE_MAPPING_FAILED] ?? 0)
		);
	}
}
