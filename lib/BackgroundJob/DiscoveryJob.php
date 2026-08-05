<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\BackgroundJob;

use OCA\NextcloudMigrate\Db\MigrationRun;
use OCA\NextcloudMigrate\Db\MigrationRunMapper;
use OCA\NextcloudMigrate\Db\UserMap;
use OCA\NextcloudMigrate\Db\UserMapMapper;
use OCA\NextcloudMigrate\Service\DiscoveryService;
use OCA\NextcloudMigrate\Service\RunOrchestrator;
use OCA\NextcloudMigrate\Util\UuidGenerator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Processes as many pending source users as fit within
 * RunOrchestrator::getBatchSeconds() per execution (rather than exactly one
 * user per execution), re-enqueueing itself only once that budget is
 * exhausted and pending users remain. Discovering only one user per job
 * invocation would gate overall progress on the cron interval (commonly
 * ~5 minutes under system cron), making runs with many mapped users appear
 * "stuck" even though each individual discovery only takes seconds. Once
 * no PENDING user mappings remain, hands off to
 * RunOrchestrator::onDiscoveryComplete().
 */
class DiscoveryJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private MigrationRunMapper $runMapper,
		private UserMapMapper $userMapMapper,
		private DiscoveryService $discoveryService,
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

		$deadline = time() + $this->runOrchestrator->getBatchSeconds();

		do {
			try {
				$run = $this->runMapper->find($runId);
			} catch (DoesNotExistException) {
				return;
			} catch (\Throwable $e) {
				$this->logger->warning('DiscoveryJob could not load run; dropping stale/invalid job', [
					'app' => 'nextcloud_migrate',
					'runId' => $runId,
					'exception' => $e,
				]);
				return;
			}

			if ($run->getState() !== MigrationRun::STATE_DISCOVERING) {
				// Run was paused/cancelled/failed before this execution started.
				return;
			}

			$userMaps = $this->userMapMapper->findByRun($runId);
			$pending = array_values(array_filter($userMaps, static fn (UserMap $um) => $um->getState() === UserMap::STATE_PENDING));

			if ($pending === []) {
				$this->runOrchestrator->onDiscoveryComplete($runId);
				return;
			}

			$userMap = $pending[0];
			$userMap->setState(UserMap::STATE_ACTIVE);
			$this->userMapMapper->update($userMap);

			try {
				$stats = $this->discoveryService->discoverUser($runId, $userMap, $userMap->getSourceUserId());
				$userMap->setTotalFiles($stats['files'] + $stats['folders']);
				$this->userMapMapper->update($userMap);
			} catch (\Throwable $e) {
				$userMap->setState(UserMap::STATE_FAILED);
				$this->userMapMapper->update($userMap);
				$this->logger->error('Discovery failed for source user', [
					'app' => 'nextcloud_migrate',
					'runId' => $runId,
					'sourceUserId' => $userMap->getSourceUserId(),
					'exception' => $e,
				]);
			}
		} while (time() < $deadline);

		$remaining = $this->userMapMapper->findByRun($runId);
		$stillPending = array_filter($remaining, static fn (UserMap $um) => $um->getState() === UserMap::STATE_PENDING);

		if ($stillPending !== []) {
			// A fresh nonce on every re-enqueue is required: IJobList::add()
			// dedupes by class+argument, so a stable argument would just
			// update the *same* jobs-table row in place instead of inserting
			// a new one. cron.php aborts its entire run (not just this job)
			// the moment it sees the same job row id twice in one pass, so an
			// unchanging argument here would prematurely kill cron.php after
			// only one batch's worth of discovery.
			$this->jobList->add(self::class, ['runId' => $runId, 'nonce' => UuidGenerator::v4()]);
		} else {
			$this->runOrchestrator->onDiscoveryComplete($runId);
		}
	}

	private function extractRunId($argument): ?int {
		if (!is_array($argument) || !isset($argument['runId']) || !is_numeric($argument['runId'])) {
			$this->logger->warning('DiscoveryJob invoked with missing/invalid runId argument; skipping', [
				'app' => 'nextcloud_migrate',
			]);
			return null;
		}

		return (int)$argument['runId'];
	}
}
