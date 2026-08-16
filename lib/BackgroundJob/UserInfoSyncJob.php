<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\BackgroundJob;

use OCA\NextcloudMigrate\Db\MigrationRun;
use OCA\NextcloudMigrate\Db\MigrationRunMapper;
use OCA\NextcloudMigrate\Db\RemoteInstanceMapper;
use OCA\NextcloudMigrate\Service\ResourceMigrator\UserInfoMigrationService;
use OCA\NextcloudMigrate\Service\RunOrchestrator;
use OCA\NextcloudMigrate\Util\JobScheduling;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Syncs every mapped user's core account profile (see
 * UserInfoMigrationService) to the target instance for a run with
 * migrate_user_info enabled. Spawned once by
 * RunOrchestrator::approveRun(), alongside (not instead of)
 * EnqueueTransfersJob's file transfer pipeline - this job re-enqueues
 * itself until UserInfoMigrationService::isRunComplete() is true, entirely
 * independently of the file transfer/verification phases.
 *
 * Deliberately NOT wired into the file pipeline's phase-advancement
 * (RunOrchestrator::onTransferPoolIdle()/onVerificationPoolIdle()/
 * finalizeRun()): user info sync is a handful of small OCS API calls per
 * user, negligible next to file transfer time for any realistic
 * migration, so gating the run's overall COMPLETED/COMPLETED_WITH_ERRORS
 * transition on it as well was judged not worth the added state-machine
 * complexity for this first resource type. Its own progress is reported
 * independently (see StatusController::runStatus()'s resourceProgress).
 */
class UserInfoSyncJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private MigrationRunMapper $runMapper,
		private RemoteInstanceMapper $instanceMapper,
		private UserInfoMigrationService $userInfoMigrationService,
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
			$run = $this->runMapper->find($runId);
		} catch (DoesNotExistException) {
			return;
		} catch (\Throwable $e) {
			$this->logger->warning('UserInfoSyncJob could not load run; dropping stale/invalid job', [
				'app' => 'nextcloud_migrate',
				'runId' => $runId,
				'exception' => $e,
			]);
			return;
		}

		if (!$run->getMigrateUserInfo()) {
			return;
		}

		$deadStates = [MigrationRun::STATE_CANCELLED, MigrationRun::STATE_FAILED, MigrationRun::STATE_VALIDATION_FAILED];
		if (in_array($run->getState(), $deadStates, true)) {
			return;
		}

		try {
			$instance = $this->instanceMapper->find($run->getInstanceId());
		} catch (\Throwable $e) {
			$this->logger->warning('UserInfoSyncJob could not resolve target instance; dropping job', [
				'app' => 'nextcloud_migrate',
				'runId' => $runId,
				'exception' => $e,
			]);
			return;
		}

		$deadline = time() + $this->runOrchestrator->getBatchSeconds();
		$this->userInfoMigrationService->syncRun($run, $instance, $deadline);

		if ($this->userInfoMigrationService->isRunComplete($runId)) {
			$this->runOrchestrator->onUserInfoSyncComplete($runId);
			return;
		}

		// Backdated firstCheck so this re-enqueue is picked up within the
		// same cron.php pass rather than losing a last_checked tie-break
		// (see JobScheduling::IMMEDIATE_FIRST_CHECK).
		$this->jobList->add(self::class, ['runId' => $runId], JobScheduling::IMMEDIATE_FIRST_CHECK);
	}

	private function extractRunId($argument): ?int {
		if (!is_array($argument) || !isset($argument['runId']) || !is_numeric($argument['runId'])) {
			$this->logger->warning('UserInfoSyncJob invoked with missing/invalid runId argument; skipping', [
				'app' => 'nextcloud_migrate',
			]);
			return null;
		}

		return (int)$argument['runId'];
	}
}
