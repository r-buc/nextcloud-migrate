<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\BackgroundJob;

use OCA\NextcloudMigrate\Db\MigrationRun;
use OCA\NextcloudMigrate\Db\MigrationRunMapper;
use OCA\NextcloudMigrate\Db\RemoteInstanceMapper;
use OCA\NextcloudMigrate\Service\ResourceMigrator\ContactsMigrationService;
use OCA\NextcloudMigrate\Service\RunOrchestrator;
use OCA\NextcloudMigrate\Util\JobScheduling;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Syncs every mapped user's own address books/contacts (see
 * ContactsMigrationService) for a run with migrate_contacts enabled.
 * Mirrors UserInfoSyncJob's shape exactly: a single run-scoped batch job
 * (not a per-user lineage with locking - see repo/session notes for why
 * that's unnecessary here) that re-enqueues itself until
 * ContactsMigrationService::isRunComplete() is true, independently of the
 * file transfer/verification phases.
 */
class ContactsWorkerJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private MigrationRunMapper $runMapper,
		private RemoteInstanceMapper $instanceMapper,
		private ContactsMigrationService $contactsMigrationService,
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
			$this->logger->warning('ContactsWorkerJob could not load run; dropping stale/invalid job', [
				'app' => 'nextcloud_migrate',
				'runId' => $runId,
				'exception' => $e,
			]);
			return;
		}

		if (!$run->getMigrateContacts()) {
			return;
		}

		$deadStates = [MigrationRun::STATE_CANCELLED, MigrationRun::STATE_FAILED, MigrationRun::STATE_VALIDATION_FAILED];
		if (in_array($run->getState(), $deadStates, true)) {
			return;
		}

		try {
			$instance = $this->instanceMapper->find($run->getInstanceId());
		} catch (\Throwable $e) {
			$this->logger->warning('ContactsWorkerJob could not resolve target instance; dropping job', [
				'app' => 'nextcloud_migrate',
				'runId' => $runId,
				'exception' => $e,
			]);
			return;
		}

		$deadline = time() + $this->runOrchestrator->getBatchSeconds();
		$this->contactsMigrationService->syncRun($run, $instance, $deadline);

		if ($this->contactsMigrationService->isRunComplete($runId)) {
			$this->runOrchestrator->onContactsSyncComplete($runId);
			return;
		}

		$this->jobList->add(self::class, ['runId' => $runId], JobScheduling::IMMEDIATE_FIRST_CHECK);
	}

	private function extractRunId($argument): ?int {
		if (!is_array($argument) || !isset($argument['runId']) || !is_numeric($argument['runId'])) {
			$this->logger->warning('ContactsWorkerJob invoked with missing/invalid runId argument; skipping', [
				'app' => 'nextcloud_migrate',
			]);
			return null;
		}

		return (int)$argument['runId'];
	}
}
