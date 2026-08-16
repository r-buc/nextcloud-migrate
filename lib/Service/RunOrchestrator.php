<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service;

use OCA\NextcloudMigrate\BackgroundJob\DiscoveryJob;
use OCA\NextcloudMigrate\BackgroundJob\CalendarsWorkerJob;
use OCA\NextcloudMigrate\BackgroundJob\ContactsWorkerJob;
use OCA\NextcloudMigrate\BackgroundJob\EnqueueTransfersJob;
use OCA\NextcloudMigrate\BackgroundJob\FinalizeJob;
use OCA\NextcloudMigrate\BackgroundJob\TransferWorkerJob;
use OCA\NextcloudMigrate\BackgroundJob\SharesWorkerJob;
use OCA\NextcloudMigrate\BackgroundJob\UserInfoSyncJob;
use OCA\NextcloudMigrate\BackgroundJob\VerifyWorkerJob;
use OCA\NextcloudMigrate\Db\MigrationFile;
use OCA\NextcloudMigrate\Db\MigrationFileMapper;
use OCA\NextcloudMigrate\Db\MigrationResourceItemMapper;
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
use OCA\NextcloudMigrate\Util\JobScheduling;
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
 *   COMPLETED | COMPLETED_WITH_ERRORS -> SYNCING (startSyncing(), 'overwrite_newer'
 *     collision strategy only) -> (stopSyncing()) -> COMPLETED | COMPLETED_WITH_ERRORS
 */
class RunOrchestrator {
	// How long a single TransferWorkerJob/VerifyWorkerJob execution keeps
	// claiming and processing files in a loop before re-enqueueing itself,
	// rather than handling exactly one file per execution. cron.php (CLI
	// mode) gives each invocation a 14-minute budget and typically runs
	// every 5 minutes via system cron, so a multi-minute batch still
	// leaves headroom for other apps' jobs in the same invocation. This
	// app targets system cron for its 100k-file/1TB use case (not
	// ajax/webcron, which is unsuitable for that scale regardless), so
	// there's no need to keep this short enough for a typical web
	// request's execution-time limit. Lower it via `occ config:app:set
	// nextcloud_migrate batch_seconds` if running under ajax/webcron mode.
	private const DEFAULT_BATCH_SECONDS = 240;

	public function __construct(
		private MigrationRunMapper $runMapper,
		private RemoteInstanceMapper $instanceMapper,
		private UserMapMapper $userMapMapper,
		private MigrationFileMapper $fileMapper,
		private MigrationResourceItemMapper $resourceItemMapper,
		private WebDavClient $webDavClient,
		private ProvisioningClient $provisioningClient,
		private CredentialService $credentialService,
		private ReportService $reportService,
		private EventLogger $eventLogger,
		private IJobList $jobList,
		private IConfig $config,
		private DiscoveryService $discoveryService,
	) {
	}

	/**
	 * @param array<array{sourceUserId: string, targetUserId: string, mode?: string, appPassword?: string}> $mappings
	 *        mode defaults to 'auto': the target user is created (if it
	 *        doesn't exist on the remote instance yet) or has its password
	 *        reset (if it does) via the OCS Provisioning API, using the
	 *        instance's admin credential, then that (temporary) account
	 *        password is immediately exchanged for a dedicated app
	 *        password (see ProvisioningClient::generateAppPassword()) - no
	 *        manual per-user app password needed, and the target user's
	 *        account password can be changed again afterwards without
	 *        affecting an in-progress migration or continuous sync. mode
	 *        'manual' ("expert mode") uses an app password the admin
	 *        already obtained from that specific target user instead,
	 *        without touching their account via the admin API at all.
	 * @param bool $skipVerification if true, the post-transfer verification
	 *        phase (re-downloading every file from the target to compare
	 *        checksums) is skipped once transfer completes, relying solely
	 *        on the target's upload-time OC-Checksum validation. Defaults
	 *        to false (verification on) since that also catches narrower
	 *        cases upload-time validation cannot, e.g. post-write storage
	 *        corruption on the target.
	 * @throws \InvalidArgumentException
	 * @throws RemoteConnectionException if an 'auto' mapping's create/reset call fails
	 */
	public function createRun(
		string $createdBy,
		int $instanceId,
		string $collisionStrategy,
		array $mappings,
		bool $skipVerification = false,
		bool $migrateUserInfo = false,
		bool $migrateContacts = false,
		bool $migrateCalendars = false,
		bool $migrateShares = false,
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
				$temporaryPassword = bin2hex(random_bytes(24));
				if (isset($remoteUsers[$targetUserId])) {
					$this->provisioningClient->resetUserPassword($instance, $instance->getAdminUserId(), $adminPassword, $targetUserId, $temporaryPassword);
				} else {
					$this->provisioningClient->createUser($instance, $instance->getAdminUserId(), $adminPassword, $targetUserId, $temporaryPassword);
				}
				// Immediately exchange the temporary account password for
				// a dedicated app password: this, not the temporary
				// account password itself, is what gets stored/used for
				// every subsequent WebDAV/OCS call, so the target user's
				// account password can be changed later without
				// affecting an in-progress migration or continuous sync.
				$appPassword = $this->provisioningClient->generateAppPassword($instance, $targetUserId, $temporaryPassword);
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
		$run->setSkipVerification($skipVerification);
		$run->setMigrateUserInfo($migrateUserInfo);
		// Not yet offered via the API - see Controller/MigrationController::createRun();
		// these are set explicitly (never left null - see allow_self_signed
		// comment re: notnull+default booleans) so future phases have a
		// consistent, non-null value to build on.
		$run->setMigrateContacts($migrateContacts);
		$run->setMigrateCalendars($migrateCalendars);
		$run->setMigrateShares($migrateShares);
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

		// Backdated firstCheck so this is treated as already-overdue and gets
		// picked up on cron.php's very next pass ahead of routine periodic
		// jobs, rather than losing a last_checked tie-break to one of those
		// (see JobScheduling::IMMEDIATE_FIRST_CHECK).
		$this->jobList->add(DiscoveryJob::class, ['runId' => $runId], JobScheduling::IMMEDIATE_FIRST_CHECK);

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

		$this->jobList->add(EnqueueTransfersJob::class, ['runId' => $runId], JobScheduling::IMMEDIATE_FIRST_CHECK);

		if ($run->getMigrateUserInfo()) {
			// Independent track, not gated on file transfer/verification - see
			// UserInfoSyncJob's docblock for why.
			$this->jobList->add(UserInfoSyncJob::class, ['runId' => $runId], JobScheduling::IMMEDIATE_FIRST_CHECK);
		}

		if ($run->getMigrateContacts()) {
			$this->jobList->add(ContactsWorkerJob::class, ['runId' => $runId], JobScheduling::IMMEDIATE_FIRST_CHECK);
		}

		if ($run->getMigrateCalendars()) {
			$this->jobList->add(CalendarsWorkerJob::class, ['runId' => $runId], JobScheduling::IMMEDIATE_FIRST_CHECK);
		}

		if ($run->getMigrateShares()) {
			// Its own per-user gating (isUserFilesSettled()) happens inside
			// SharesMigrationService's discovery step, not here - shares can
			// only be recreated once the corresponding file exists on the
			// target, so this job is spawned immediately but simply finds
			// nothing to do yet for any user whose files haven't settled.
			$this->jobList->add(SharesWorkerJob::class, ['runId' => $runId], JobScheduling::IMMEDIATE_FIRST_CHECK);
		}

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

	/**
	 * How many seconds a single TransferWorkerJob/VerifyWorkerJob execution
	 * should keep claiming and processing files before yielding back (by
	 * re-enqueueing itself with a fresh token) instead of doing exactly one
	 * file per execution. Configurable via `occ config:app:set
	 * nextcloud_migrate batch_seconds --value=N` - lower it for ajax/webcron
	 * deployments (where a single HTTP request's execution-time limit
	 * applies), or raise it further for system-cron-only deployments that
	 * want to spend more of cron.php's 14-minute budget per batch.
	 */
	public function getBatchSeconds(): int {
		return (int)$this->config->getAppValue('nextcloud_migrate', 'batch_seconds', (string)self::DEFAULT_BATCH_SECONDS);
	}

	/**
	 * Called by a TransferWorkerJob when it finds no more transferable files
	 * and no other files still in-flight *for its own user*. Since transfer
	 * runs one worker lineage per mapped user (see EnqueueTransfersJob), the
	 * run as a whole is only ready to move on once every user's lineage has
	 * reached this point too.
	 */
	public function onUserTransferComplete(int $runId, int $userMapId): void {
		$run = $this->runMapper->find($runId);
		if ($run->getState() === MigrationRun::STATE_SYNCING) {
			// No run-level phase transition during continuous sync (the run
			// just stays SYNCING indefinitely) - but this user's newly
			// transferred files still need verifying, chained directly
			// per-user rather than waiting for every mapped user to drain
			// (there's no "whole run" phase to wait for here).
			if (!$run->getSkipVerification()) {
				$this->jobList->add(VerifyWorkerJob::class, ['runId' => $runId, 'userMapId' => $userMapId, 'workerToken' => UuidGenerator::v4()], JobScheduling::IMMEDIATE_FIRST_CHECK);
			}
			return;
		}
		if (!in_array($run->getState(), [MigrationRun::STATE_TRANSFERRING, MigrationRun::STATE_APPROVED], true)) {
			return;
		}

		if ($this->anyUserStillTransferring($runId)) {
			// Another mapped user's TransferWorkerJob lineage is still
			// working; whichever one finishes last will trigger the phase
			// transition below.
			return;
		}

		$this->onTransferPoolIdle($runId);
	}

	/**
	 * Advances the run out of TRANSFERRING once every mapped user's
	 * TransferWorkerJob lineage has drained. Also called directly by
	 * EnqueueTransfersJob when a run has nothing to transfer at all (e.g.
	 * every mapped user failed discovery).
	 */
	public function onTransferPoolIdle(int $runId): void {
		$run = $this->runMapper->find($runId);
		if (!in_array($run->getState(), [MigrationRun::STATE_TRANSFERRING, MigrationRun::STATE_APPROVED], true)) {
			return;
		}

		$counts = $this->fileMapper->countByState($runId);
		$this->refreshRunCounters($run, $counts);

		if ($run->getSkipVerification()) {
			$run->setState(MigrationRun::STATE_FINALIZING);
			$run->setUpdatedAt(time());
			$this->runMapper->update($run);

			$this->eventLogger->log($runId, 'transfer_completed', 'All transferable files processed; verification skipped for this run, finalizing');

			$this->jobList->add(FinalizeJob::class, ['runId' => $runId], JobScheduling::IMMEDIATE_FIRST_CHECK);

			return;
		}

		$run->setState(MigrationRun::STATE_VERIFYING);
		$run->setUpdatedAt(time());
		$this->runMapper->update($run);

		$this->eventLogger->log($runId, 'transfer_completed', 'All transferable files processed; starting verification');

		$this->spawnVerifyWorkers($runId);
	}

	/**
	 * Called by a VerifyWorkerJob when it finds no more verifiable files
	 * and no other files still in-flight *for its own user* - mirrors
	 * onUserTransferComplete()/onTransferPoolIdle() above (one worker
	 * lineage per mapped user).
	 */
	public function onUserVerificationComplete(int $runId, int $userMapId): void {
		$run = $this->runMapper->find($runId);
		if ($run->getState() !== MigrationRun::STATE_VERIFYING) {
			return;
		}

		if ($this->anyUserStillVerifying($runId)) {
			return;
		}

		$this->onVerificationPoolIdle($runId);
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

		$this->jobList->add(FinalizeJob::class, ['runId' => $runId], JobScheduling::IMMEDIATE_FIRST_CHECK);
	}

	/**
	 * Called by UserInfoSyncJob once every mapped user's account profile
	 * has reached a terminal state (synced or failed) for a run with
	 * migrate_user_info enabled. Purely informational (an audit log entry)
	 * - see UserInfoSyncJob's docblock for why this deliberately does NOT
	 * drive the file pipeline's own phase transitions.
	 */
	public function onUserInfoSyncComplete(int $runId): void {
		$this->eventLogger->log($runId, 'user_info_sync_completed', 'User info sync finished for all mapped users');
	}

	/**
	 * Called by ContactsWorkerJob once every mapped user's address books/
	 * contacts have reached a terminal state for a run with
	 * migrate_contacts enabled. Purely informational, same as
	 * onUserInfoSyncComplete().
	 */
	public function onContactsSyncComplete(int $runId): void {
		$this->eventLogger->log($runId, 'contacts_sync_completed', 'Contacts sync finished for all mapped users');
	}

	/**
	 * Called by CalendarsWorkerJob once every mapped user's calendars have
	 * reached a terminal state for a run with migrate_calendars enabled.
	 * Purely informational, same as the other onXSyncComplete() hooks.
	 */
	public function onCalendarsSyncComplete(int $runId): void {
		$this->eventLogger->log($runId, 'calendars_sync_completed', 'Calendars sync finished for all mapped users');
	}

	/**
	 * Called by SharesWorkerJob once every mapped user's shares have
	 * reached a terminal state for a run with migrate_shares enabled.
	 * Purely informational, same as the other onXSyncComplete() hooks.
	 */
	public function onSharesSyncComplete(int $runId): void {
		$this->eventLogger->log($runId, 'shares_sync_completed', 'Shares sync finished for all mapped users');
	}

	/**
	 * Whether a specific mapped user's file transfer (and, unless
	 * skip_verification, verification) pipeline has fully drained - i.e.
	 * no more transferable/verifiable files remain, counting only
	 * still-retryable failures as "remaining" (see
	 * MigrationFileMapper::countRetryableFailures()'s docblock for why
	 * exhausted-retry failures must count as settled). Used by
	 * SharesMigrationService to gate share recreation on a user's files
	 * already existing on the target - a share can't be recreated against
	 * a file that hasn't been transferred yet.
	 */
	public function isUserFilesSettled(int $runId, int $userMapId): bool {
		$run = $this->runMapper->find($runId);
		$counts = $this->fileMapper->countByState($runId, $userMapId);
		$retryable = $this->fileMapper->countRetryableFailures($runId, $userMapId);

		$transferRemaining = ($counts[MigrationFile::STATE_DISCOVERED] ?? 0)
			+ $retryable['transferRetryable']
			+ ($counts[MigrationFile::STATE_TRANSFERRING] ?? 0);
		if ($transferRemaining > 0) {
			return false;
		}

		if ($run->getSkipVerification()) {
			return true;
		}

		$verifyRemaining = ($counts[MigrationFile::STATE_TRANSFERRED] ?? 0)
			+ $retryable['verificationRetryable']
			+ ($counts[MigrationFile::STATE_VERIFYING] ?? 0);

		return $verifyRemaining === 0;
	}

	/**
	 * Periodic self-healing check (called by CleanupLocksJob, alongside its
	 * stale-lock sweep): a run's TRANSFERRING/VERIFYING phase is only ever
	 * supposed to end when the LAST remaining worker lineage calls
	 * onUserTransferComplete()/onUserVerificationComplete() - but nothing
	 * else ever re-evaluates a run afterwards. If a worker crashed hard
	 * enough to never make that call, or the run got stuck under
	 * since-fixed phase-advancement logic, it would otherwise stay wedged
	 * in TRANSFERRING/VERIFYING forever with no active jobs left and no way
	 * to notice on its own. This re-checks every currently
	 * TRANSFERRING/VERIFYING run and nudges it along if it turns out
	 * nothing is actually remaining, so such a run recovers within one
	 * sweep interval instead of needing a manual pause/resume.
	 */
	public function reconcileStalledRuns(): void {
		foreach ($this->runMapper->findActive() as $run) {
			if ($run->getState() === MigrationRun::STATE_TRANSFERRING && !$this->anyUserStillTransferring($run->getId())) {
				$this->onTransferPoolIdle($run->getId());
			} elseif ($run->getState() === MigrationRun::STATE_VERIFYING && !$this->anyUserStillVerifying($run->getId())) {
				$this->onVerificationPoolIdle($run->getId());
			}
		}
	}

	public function finalizeRun(int $runId): void {
		$run = $this->runMapper->find($runId);
		$this->transitionToTerminalState($run);

		$this->eventLogger->log($runId, 'run_finished', "Run finished with state {$run->getState()}");
	}

	/**
	 * Shared by finalizeRun() (end of the initial pipeline) and
	 * stopSyncing() (admin ends continuous sync): recomputes counters from
	 * current file state and settles the run into COMPLETED or
	 * COMPLETED_WITH_ERRORS depending on whether any failures remain.
	 */
	private function transitionToTerminalState(MigrationRun $run): void {
		$counts = $this->fileMapper->countByState($run->getId());
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
	}

	/**
	 * Starts continuous sync: moves a finished run (COMPLETED or
	 * COMPLETED_WITH_ERRORS) into MigrationRun::STATE_SYNCING, where
	 * SyncDiscoveryJob periodically re-scans each mapped user's source tree
	 * for new or changed files and re-runs them through the normal
	 * transfer/verification pipeline (see runSyncPass()). Only offered for
	 * the 'overwrite_newer' collision strategy - see
	 * MappingService::STRATEGY_OVERWRITE_IF_NEWER - since that's the only
	 * strategy where re-syncing an already-migrated, now-changed file does
	 * something sensible: 'skip' would never update it again, and 'rename'/
	 * plain 'overwrite' would either pile up a fresh duplicate or blindly
	 * overwrite every cycle regardless of which side actually changed.
	 *
	 * @throws \RuntimeException if the run hasn't finished, or doesn't use
	 *         the 'overwrite_newer' collision strategy
	 */
	public function startSyncing(int $runId): MigrationRun {
		$run = $this->runMapper->find($runId);
		$finishedStates = [MigrationRun::STATE_COMPLETED, MigrationRun::STATE_COMPLETED_WITH_ERRORS];
		if (!in_array($run->getState(), $finishedStates, true)) {
			throw new \RuntimeException("Run must have finished to start continuous sync (currently '{$run->getState()}')");
		}
		if ($run->getCollisionStrategy() !== MappingService::STRATEGY_OVERWRITE_IF_NEWER) {
			throw new \RuntimeException("Continuous sync is only available for runs using the '" . MappingService::STRATEGY_OVERWRITE_IF_NEWER . "' collision strategy");
		}

		$run->setState(MigrationRun::STATE_SYNCING);
		$run->setUpdatedAt(time());
		$this->runMapper->update($run);

		$this->eventLogger->log($runId, 'sync_started', 'Admin enabled continuous sync; the source will be periodically re-scanned for new/changed files');

		return $run;
	}

	/**
	 * Stops continuous sync: settles the run into COMPLETED or
	 * COMPLETED_WITH_ERRORS based on current failure counts, same decision
	 * finalizeRun() makes for the initial pass.
	 *
	 * @throws \RuntimeException if the run isn't currently syncing
	 */
	public function stopSyncing(int $runId): MigrationRun {
		$run = $this->runMapper->find($runId);
		if ($run->getState() !== MigrationRun::STATE_SYNCING) {
			throw new \RuntimeException("Run is not currently syncing (state '{$run->getState()}')");
		}

		$this->transitionToTerminalState($run);
		$this->eventLogger->log($runId, 'sync_stopped', "Admin stopped continuous sync; run finished with state {$run->getState()}");

		return $run;
	}

	/**
	 * Called by SyncDiscoveryJob on every tick for each currently-SYNCING
	 * run: re-discovers each mapped user's tree for new/changed files (see
	 * DiscoveryService::discoverIncremental()) and spawns a TransferWorkerJob
	 * for any user with something new to do. A user whose lineage is still
	 * busy from a previous tick simply gets a redundant spawn that finds
	 * nothing to claim and exits immediately - harmless, and simpler than
	 * tracking in-flight lineages across ticks.
	 */
	public function runSyncPass(int $runId): void {
		$run = $this->runMapper->find($runId);
		if ($run->getState() !== MigrationRun::STATE_SYNCING) {
			return;
		}

		// Narrows discoverIncremental()'s underlying search to
		// likely-changed candidates (see DiscoveryService::walk()) instead
		// of re-examining every file on every tick. Falls back to
		// finishedAt (when the initial pipeline completed) for the very
		// first sync pass, since a file could already have changed between
		// then and whenever the admin later enabled continuous sync.
		$since = $run->getLastSyncAt() ?? $run->getFinishedAt() ?? 0;

		foreach ($this->userMapMapper->findByRun($runId) as $userMap) {
			if ($userMap->getState() === UserMap::STATE_FAILED) {
				continue;
			}

			try {
				$result = $this->discoveryService->discoverIncremental($runId, $userMap, $userMap->getSourceUserId(), $since);
			} catch (\Throwable $e) {
				$this->eventLogger->log($runId, 'sync_scan_failed', "Incremental sync scan failed for user '{$userMap->getSourceUserId()}': {$e->getMessage()}", 'error');
				continue;
			}

			if ($result['new'] > 0 || $result['changed'] > 0) {
				$this->jobList->add(TransferWorkerJob::class, ['runId' => $runId, 'userMapId' => $userMap->getId(), 'workerToken' => UuidGenerator::v4()], JobScheduling::IMMEDIATE_FIRST_CHECK);
			}
		}


		$counts = $this->fileMapper->countByState($runId);
		$run->setTotalFiles(array_sum($counts));
		$run->setTotalBytes($this->fileMapper->sumDiscoveredBytes($runId));
		$this->refreshRunCounters($run, $counts);
		$run->setLastSyncAt(time());
		$run->setUpdatedAt(time());
		$this->runMapper->update($run);
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
		// Only STILL-RETRYABLE failed rows count as remaining work here -
		// see the comment in anyUserStillTransferring()/anyUserStillVerifying()
		// for why counting exhausted-retry rows would make a run with any
		// permanent failures resume into the same phase forever.
		$retryable = $this->fileMapper->countRetryableFailures($runId);
		$transferableRemaining = ($counts[MigrationFile::STATE_DISCOVERED] ?? 0) + $retryable['transferRetryable'];
		$verifiableRemaining = !$run->getSkipVerification()
			? ($counts[MigrationFile::STATE_TRANSFERRED] ?? 0) + $retryable['verificationRetryable']
			: 0;

		if ($transferableRemaining > 0) {
			$run->setState(MigrationRun::STATE_TRANSFERRING);
			$this->runMapper->update($run);
			$this->jobList->add(EnqueueTransfersJob::class, ['runId' => $runId], JobScheduling::IMMEDIATE_FIRST_CHECK);
		} elseif ($verifiableRemaining > 0) {
			$run->setState(MigrationRun::STATE_VERIFYING);
			$this->runMapper->update($run);
			$this->spawnVerifyWorkers($runId);
		} else {
			$run->setState(MigrationRun::STATE_FINALIZING);
			$this->runMapper->update($run);
			$this->jobList->add(FinalizeJob::class, ['runId' => $runId], JobScheduling::IMMEDIATE_FIRST_CHECK);
		}

		$this->eventLogger->log($runId, 'run_resumed', "Run resumed into state {$run->getState()}");

		return $run;
	}

	/**
	 * Admin-triggered retry of every currently-failed file on a finished
	 * run: resets them all back to DISCOVERED (see
	 * MigrationFileMapper::resetFailuresForRetry() - including files that
	 * had already exhausted their retry budget, which would otherwise
	 * never be picked up again) and re-arms the run, reusing resumeRun()'s
	 * existing phase-decision logic by momentarily treating the run as
	 * PAUSED.
	 *
	 * @throws \RuntimeException if the run hasn't finished with failures,
	 *         or has no failed files to retry
	 */
	public function retryFailures(int $runId): MigrationRun {
		$run = $this->runMapper->find($runId);
		$retryableStates = [MigrationRun::STATE_COMPLETED_WITH_ERRORS, MigrationRun::STATE_FAILED, MigrationRun::STATE_SYNCING];
		if (!in_array($run->getState(), $retryableStates, true)) {
			throw new \RuntimeException("Run must have finished with failures to retry failed files (currently '{$run->getState()}')");
		}

		$resetCount = $this->fileMapper->resetFailuresForRetry($runId);
		if ($resetCount === 0) {
			throw new \RuntimeException('No failed files to retry');
		}

		$this->eventLogger->log($runId, 'retry_requested', "Admin requested retry of {$resetCount} failed file(s)");

		if ($run->getState() === MigrationRun::STATE_SYNCING) {
			// Stay in SYNCING throughout - just re-spawn transfer workers for
			// the affected users rather than routing through the terminal-run
			// resume/finalize flow below, which doesn't apply here.
			foreach ($this->userMapMapper->findByRun($runId) as $userMap) {
				if ($userMap->getState() === UserMap::STATE_FAILED) {
					continue;
				}
				$this->jobList->add(TransferWorkerJob::class, ['runId' => $runId, 'userMapId' => $userMap->getId(), 'workerToken' => UuidGenerator::v4()], JobScheduling::IMMEDIATE_FIRST_CHECK);
			}
			return $run;
		}

		$run->setState(MigrationRun::STATE_PAUSED);
		$run->setFinishedAt(null);
		$this->runMapper->update($run);

		return $this->resumeRun($runId);
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
	 * Permanently removes a finished run and everything recorded for it
	 * (mapped users, discovered files, audit events) - used by the admin UI
	 * once a run reaches a state where nothing more will ever happen to it
	 * on its own, to clear it out of the way for a new migration. Only
	 * allowed from a state where no background job could still be
	 * referencing this run (an active run must be cancelled first).
	 *
	 * @throws \RuntimeException if the run is still active
	 */
	public function deleteRun(int $runId): void {
		$run = $this->runMapper->find($runId);
		$doneStates = [
			MigrationRun::STATE_COMPLETED,
			MigrationRun::STATE_COMPLETED_WITH_ERRORS,
			MigrationRun::STATE_CANCELLED,
			MigrationRun::STATE_FAILED,
			MigrationRun::STATE_VALIDATION_FAILED,
		];
		if (!in_array($run->getState(), $doneStates, true)) {
			throw new \RuntimeException("Run cannot be deleted from state '{$run->getState()}' - cancel it first");
		}

		$this->fileMapper->deleteByRun($runId);
		$this->resourceItemMapper->deleteByRun($runId);
		$this->userMapMapper->deleteByRun($runId);
		$this->eventLogger->deleteRunEvents($runId);
		$this->runMapper->delete($run);
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
	 * Recomputes transferredFiles/verifiedFiles/failedFiles on $run from a
	 * fresh per-state file count, mutating the entity in place. Callers
	 * that want this persisted must call runMapper->update($run)
	 * themselves afterwards (onTransferPoolIdle()/finalizeRun() do; this
	 * method is also called by StatusController to refresh an in-memory
	 * $run for a status response without writing to the DB on every poll).
	 *
	 * @param array<string,int> $counts
	 */
	public function refreshRunCounters(MigrationRun $run, array $counts): void {
		// "Transferred" means the file has left the pre-transfer pool
		// successfully - i.e. it's at TRANSFERRED or any later stage in the
		// pipeline (verifying/verified/verification_failed all necessarily
		// passed through TRANSFERRED first), not just files currently
		// sitting in the TRANSFERRED state.
		$run->setTransferredFiles(
			($counts[MigrationFile::STATE_TRANSFERRED] ?? 0)
			+ ($counts[MigrationFile::STATE_VERIFYING] ?? 0)
			+ ($counts[MigrationFile::STATE_VERIFIED] ?? 0)
			+ ($counts[MigrationFile::STATE_VERIFICATION_FAILED] ?? 0)
		);
		$run->setVerifiedFiles($counts[MigrationFile::STATE_VERIFIED] ?? 0);
		$run->setFailedFiles(
			($counts[MigrationFile::STATE_TRANSFER_FAILED] ?? 0)
			+ ($counts[MigrationFile::STATE_VERIFICATION_FAILED] ?? 0)
			+ ($counts[MigrationFile::STATE_MAPPING_FAILED] ?? 0)
		);
	}

	/**
	 * Spawns one VerifyWorkerJob per mapped user (skipping users whose
	 * discovery failed - nothing of theirs was ever transferred), mirroring
	 * the one-worker-lineage-per-user model EnqueueTransfersJob uses for
	 * transfer. Each user's own credentials are used consistently for that
	 * whole lineage's requests, so WebDavClient never has to switch target
	 * users (and thus never has to tear down/reopen its connection) mid-job.
	 */
	private function spawnVerifyWorkers(int $runId): void {
		foreach ($this->userMapMapper->findByRun($runId) as $userMap) {
			if ($userMap->getState() === UserMap::STATE_FAILED) {
				continue;
			}
			$this->jobList->add(VerifyWorkerJob::class, ['runId' => $runId, 'userMapId' => $userMap->getId(), 'workerToken' => UuidGenerator::v4()], JobScheduling::IMMEDIATE_FIRST_CHECK);
		}
	}

	/**
	 * @see onUserTransferComplete()
	 */
	private function anyUserStillTransferring(int $runId): bool {
		foreach ($this->userMapMapper->findByRun($runId) as $userMap) {
			if ($userMap->getState() === UserMap::STATE_FAILED) {
				continue;
			}
			$counts = $this->fileMapper->countByState($runId, $userMap->getId());
			// Only STILL-RETRYABLE transfer_failed rows count as remaining
			// work - ones that have exhausted MAX_TRANSFER_ATTEMPTS are
			// permanently stuck (findTransferable() will never pick them up
			// again) and must not block this user's lineage from ever being
			// considered done.
			$retryable = $this->fileMapper->countRetryableFailures($runId, $userMap->getId());
			$remaining = ($counts[MigrationFile::STATE_DISCOVERED] ?? 0)
				+ $retryable['transferRetryable']
				+ ($counts[MigrationFile::STATE_TRANSFERRING] ?? 0);
			if ($remaining > 0) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @see onUserVerificationComplete()
	 */
	private function anyUserStillVerifying(int $runId): bool {
		foreach ($this->userMapMapper->findByRun($runId) as $userMap) {
			if ($userMap->getState() === UserMap::STATE_FAILED) {
				continue;
			}
			$counts = $this->fileMapper->countByState($runId, $userMap->getId());
			// Same reasoning as anyUserStillTransferring() above, but for
			// verification_failed rows that have exhausted MAX_VERIFY_ATTEMPTS.
			$retryable = $this->fileMapper->countRetryableFailures($runId, $userMap->getId());
			$remaining = ($counts[MigrationFile::STATE_TRANSFERRED] ?? 0)
				+ $retryable['verificationRetryable']
				+ ($counts[MigrationFile::STATE_VERIFYING] ?? 0);
			if ($remaining > 0) {
				return true;
			}
		}

		return false;
	}
}
