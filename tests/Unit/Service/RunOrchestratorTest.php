<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use OCA\NextcloudMigrate\BackgroundJob\EnqueueTransfersJob;
use OCA\NextcloudMigrate\BackgroundJob\FinalizeJob;
use OCA\NextcloudMigrate\BackgroundJob\TransferWorkerJob;
use OCA\NextcloudMigrate\BackgroundJob\UserInfoSyncJob;
use OCA\NextcloudMigrate\BackgroundJob\VerifyWorkerJob;
use OCA\NextcloudMigrate\Db\MigrationFile;
use OCA\NextcloudMigrate\Db\MigrationFileMapper;
use OCA\NextcloudMigrate\Db\MigrationResourceItemMapper;
use OCA\NextcloudMigrate\Db\MigrationRun;
use OCA\NextcloudMigrate\Db\MigrationRunMapper;
use OCA\NextcloudMigrate\Db\RemoteInstanceMapper;
use OCA\NextcloudMigrate\Db\UserMap;
use OCA\NextcloudMigrate\Db\UserMapMapper;
use OCA\NextcloudMigrate\Service\CredentialService;
use OCA\NextcloudMigrate\Service\DiscoveryService;
use OCA\NextcloudMigrate\Service\EventLogger;
use OCA\NextcloudMigrate\Service\ProvisioningClient;
use OCA\NextcloudMigrate\Service\ReportService;
use OCA\NextcloudMigrate\Service\RunOrchestrator;
use OCA\NextcloudMigrate\Service\WebDavClient;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Targeted tests for RunOrchestrator's state-machine guards and the
 * resumeRun() decision logic (which of TRANSFERRING/VERIFYING/FINALIZING to
 * resume into, based on remaining file counts).
 */
final class RunOrchestratorTest extends TestCase {
	private MigrationRunMapper $runMapper;
	private RemoteInstanceMapper $instanceMapper;
	private UserMapMapper $userMapMapper;
	private MigrationFileMapper $fileMapper;
	private MigrationResourceItemMapper $resourceItemMapper;
	private WebDavClient $webDavClient;
	private ProvisioningClient $provisioningClient;
	private CredentialService $credentialService;
	private ReportService $reportService;
	private EventLogger $eventLogger;
	private IJobList $jobList;
	private IConfig $config;
	private DiscoveryService $discoveryService;
	private RunOrchestrator $orchestrator;

	protected function setUp(): void {
		$this->runMapper = $this->createMock(MigrationRunMapper::class);
		$this->instanceMapper = $this->createMock(RemoteInstanceMapper::class);
		$this->userMapMapper = $this->createMock(UserMapMapper::class);
		$this->fileMapper = $this->createMock(MigrationFileMapper::class);
		$this->resourceItemMapper = $this->createMock(MigrationResourceItemMapper::class);
		$this->webDavClient = $this->createMock(WebDavClient::class);
		$this->provisioningClient = $this->createMock(ProvisioningClient::class);
		$this->credentialService = $this->createMock(CredentialService::class);
		$this->reportService = $this->createMock(ReportService::class);
		$this->eventLogger = $this->createMock(EventLogger::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->config = $this->createMock(IConfig::class);
		$this->discoveryService = $this->createMock(DiscoveryService::class);

		// update() just needs to accept a MigrationRun and hand it back.
		$this->runMapper->method('update')->willReturnArgument(0);

		// Default: no retryable failed rows at all. Individual tests that
		// care about retryable-vs-exhausted transfer/verification failures
		// override this with a more specific stub.
		$this->fileMapper->method('countRetryableFailures')->willReturn([
			'transferRetryable' => 0,
			'verificationRetryable' => 0,
		]);

		$this->orchestrator = new RunOrchestrator(
			$this->runMapper,
			$this->instanceMapper,
			$this->userMapMapper,
			$this->fileMapper,
			$this->resourceItemMapper,
			$this->webDavClient,
			$this->provisioningClient,
			$this->credentialService,
			$this->reportService,
			$this->eventLogger,
			$this->jobList,
			$this->config,
			$this->discoveryService,
		);
	}

	private function makeRun(string $state): MigrationRun {
		$run = new MigrationRun();
		$run->setId(42);
		$run->setUuid('uuid-1');
		$run->setInstanceId(1);
		$run->setState($state);
		$run->setCollisionStrategy('rename');
		$run->setTotalUsers(1);
		$run->setTotalFiles(10);
		$run->setTransferredFiles(0);
		$run->setVerifiedFiles(0);
		$run->setFailedFiles(0);
		$run->setTotalBytes(0);
		$run->setTransferredBytes(0);
		$run->setCreatedBy('admin');
		$run->setCreatedAt(1000);
		$run->setUpdatedAt(1000);

		return $run;
	}

	public function testApproveRunRejectsWrongState(): void {
		$this->runMapper->method('find')->willReturn($this->makeRun(MigrationRun::STATE_CREATED));

		$this->expectException(\RuntimeException::class);
		$this->orchestrator->approveRun(42, 'admin');
	}

	public function testApproveRunTransitionsToApprovedAndEnqueuesTransfers(): void {
		$this->runMapper->method('find')->willReturn($this->makeRun(MigrationRun::STATE_DRY_RUN_READY));
		$this->jobList->expects($this->once())
			->method('add')
			->with(EnqueueTransfersJob::class, ['runId' => 42], 0);

		$run = $this->orchestrator->approveRun(42, 'admin');

		self::assertSame(MigrationRun::STATE_APPROVED, $run->getState());
		self::assertSame('admin', $run->getApprovedBy());
	}

	public function testApproveRunAlsoEnqueuesUserInfoSyncWhenEnabled(): void {
		$run = $this->makeRun(MigrationRun::STATE_DRY_RUN_READY);
		$run->setMigrateUserInfo(true);
		$this->runMapper->method('find')->willReturn($run);

		$addedJobs = [];
		$this->jobList->method('add')->willReturnCallback(function ($job, $argument) use (&$addedJobs) {
			$addedJobs[] = $job;
		});

		$this->orchestrator->approveRun(42, 'admin');

		self::assertSame([EnqueueTransfersJob::class, UserInfoSyncJob::class], $addedJobs);
	}

	public function testOnUserInfoSyncCompleteLogsEvent(): void {
		$this->eventLogger->expects($this->once())
			->method('log')
			->with(42, 'user_info_sync_completed', self::isType('string'));

		$this->orchestrator->onUserInfoSyncComplete(42);
	}

	public function testPauseRunRejectsFromTerminalState(): void {
		$this->runMapper->method('find')->willReturn($this->makeRun(MigrationRun::STATE_COMPLETED));

		$this->expectException(\RuntimeException::class);
		$this->orchestrator->pauseRun(42);
	}

	public function testPauseRunSucceedsFromTransferring(): void {
		$this->runMapper->method('find')->willReturn($this->makeRun(MigrationRun::STATE_TRANSFERRING));

		$run = $this->orchestrator->pauseRun(42);

		self::assertSame(MigrationRun::STATE_PAUSED, $run->getState());
	}

	public function testCancelRunRejectsAlreadyTerminalRun(): void {
		$this->runMapper->method('find')->willReturn($this->makeRun(MigrationRun::STATE_CANCELLED));

		$this->expectException(\RuntimeException::class);
		$this->orchestrator->cancelRun(42);
	}

	public function testCancelRunSucceedsFromActiveState(): void {
		$this->runMapper->method('find')->willReturn($this->makeRun(MigrationRun::STATE_VERIFYING));

		$run = $this->orchestrator->cancelRun(42);

		self::assertSame(MigrationRun::STATE_CANCELLED, $run->getState());
	}

	public function testDeleteRunRejectsActiveRun(): void {
		$this->runMapper->method('find')->willReturn($this->makeRun(MigrationRun::STATE_TRANSFERRING));

		$this->fileMapper->expects($this->never())->method('deleteByRun');

		$this->expectException(\RuntimeException::class);
		$this->orchestrator->deleteRun(42);
	}

	public function testDeleteRunSucceedsFromCompletedState(): void {
		$run = $this->makeRun(MigrationRun::STATE_COMPLETED);
		$this->runMapper->method('find')->willReturn($run);

		$this->fileMapper->expects($this->once())->method('deleteByRun')->with(42);
		$this->resourceItemMapper->expects($this->once())->method('deleteByRun')->with(42);
		$this->userMapMapper->expects($this->once())->method('deleteByRun')->with(42);
		$this->eventLogger->expects($this->once())->method('deleteRunEvents')->with(42);
		$this->runMapper->expects($this->once())->method('delete')->with($run);

		$this->orchestrator->deleteRun(42);
	}

	public function testRetryFailuresRejectsFromWrongState(): void {
		$this->runMapper->method('find')->willReturn($this->makeRun(MigrationRun::STATE_COMPLETED));

		$this->fileMapper->expects($this->never())->method('resetFailuresForRetry');

		$this->expectException(\RuntimeException::class);
		$this->orchestrator->retryFailures(42);
	}

	public function testRetryFailuresRejectsWhenNothingToRetry(): void {
		$this->runMapper->method('find')->willReturn($this->makeRun(MigrationRun::STATE_COMPLETED_WITH_ERRORS));
		$this->fileMapper->method('resetFailuresForRetry')->willReturn(0);

		$this->expectException(\RuntimeException::class);
		$this->orchestrator->retryFailures(42);
	}

	public function testRetryFailuresResetsFilesAndResumesIntoTransferring(): void {
		$run = $this->makeRun(MigrationRun::STATE_COMPLETED_WITH_ERRORS);
		$run->setFinishedAt(1234);
		$this->runMapper->method('find')->willReturn($run);
		$this->fileMapper->method('resetFailuresForRetry')->willReturn(5);
		$this->fileMapper->method('countByState')->willReturn([
			MigrationFile::STATE_DISCOVERED => 5,
		]);

		$this->jobList->expects($this->once())
			->method('add')
			->with(EnqueueTransfersJob::class, ['runId' => 42], 0);

		$run = $this->orchestrator->retryFailures(42);

		self::assertSame(MigrationRun::STATE_TRANSFERRING, $run->getState());
		self::assertNull($run->getFinishedAt());
	}

	public function testResumeRunRejectsNonPausedRun(): void {
		$this->runMapper->method('find')->willReturn($this->makeRun(MigrationRun::STATE_TRANSFERRING));

		$this->expectException(\RuntimeException::class);
		$this->orchestrator->resumeRun(42);
	}

	public function testResumeRunGoesBackToTransferringWhenFilesStillNeedTransfer(): void {
		$this->runMapper->method('find')->willReturn($this->makeRun(MigrationRun::STATE_PAUSED));
		$this->fileMapper->method('countByState')->willReturn([
			MigrationFile::STATE_DISCOVERED => 3,
			MigrationFile::STATE_TRANSFER_FAILED => 1,
		]);
		$this->jobList->expects($this->once())
			->method('add')
			->with(EnqueueTransfersJob::class, ['runId' => 42], 0);

		$run = $this->orchestrator->resumeRun(42);

		self::assertSame(MigrationRun::STATE_TRANSFERRING, $run->getState());
	}

	public function testResumeRunGoesToVerifyingWhenOnlyVerificationRemains(): void {
		$this->runMapper->method('find')->willReturn($this->makeRun(MigrationRun::STATE_PAUSED));
		$this->fileMapper->method('countByState')->willReturn([
			MigrationFile::STATE_TRANSFERRED => 2,
		]);
		$userMapA = new UserMap();
		$userMapA->setId(1);
		$userMapA->setState(UserMap::STATE_ACTIVE);
		$userMapB = new UserMap();
		$userMapB->setId(2);
		$userMapB->setState(UserMap::STATE_ACTIVE);
		$this->userMapMapper->method('findByRun')->willReturn([$userMapA, $userMapB]);
		$this->jobList->expects($this->exactly(2))
			->method('add')
			->with(VerifyWorkerJob::class, self::callback(fn ($arg) => $arg['runId'] === 42), 0);

		$run = $this->orchestrator->resumeRun(42);

		self::assertSame(MigrationRun::STATE_VERIFYING, $run->getState());
	}

	public function testResumeRunGoesToFinalizingWhenNothingRemains(): void {
		$this->runMapper->method('find')->willReturn($this->makeRun(MigrationRun::STATE_PAUSED));
		$this->fileMapper->method('countByState')->willReturn([
			MigrationFile::STATE_VERIFIED => 10,
		]);
		$this->jobList->expects($this->once())
			->method('add')
			->with(FinalizeJob::class, ['runId' => 42], 0);

		$run = $this->orchestrator->resumeRun(42);

		self::assertSame(MigrationRun::STATE_FINALIZING, $run->getState());
	}

	public function testResumeRunGoesToFinalizingWhenOnlyExhaustedFailuresRemain(): void {
		// All 5 transfer_failed rows have exhausted MAX_TRANSFER_ATTEMPTS
		// (countRetryableFailures reports 0 retryable) - they will never
		// become transferable again, so they must not keep the run stuck
		// resuming into TRANSFERRING forever.
		$this->runMapper->method('find')->willReturn($this->makeRun(MigrationRun::STATE_PAUSED));
		$this->fileMapper->method('countByState')->willReturn([
			MigrationFile::STATE_TRANSFER_FAILED => 5,
		]);
		$this->fileMapper->method('countRetryableFailures')->willReturn([
			'transferRetryable' => 0,
			'verificationRetryable' => 0,
		]);
		$this->jobList->expects($this->once())
			->method('add')
			->with(FinalizeJob::class, ['runId' => 42], 0);

		$run = $this->orchestrator->resumeRun(42);

		self::assertSame(MigrationRun::STATE_FINALIZING, $run->getState());
	}

	public function testOnUserTransferCompleteAdvancesRunWhenOnlyExhaustedFailuresRemain(): void {
		// Mirrors a real-world status snapshot: a user's lineage stopped
		// (nothing left to claim) with a chunk of permanently-failed files
		// sitting in transfer_failed. Before the fix, anyUserStillTransferring()
		// counted every transfer_failed row as "remaining work" regardless
		// of whether its retries were exhausted, so the run would never
		// leave TRANSFERRING even though no worker was doing anything more.
		$run = $this->makeRun(MigrationRun::STATE_TRANSFERRING);
		$run->setSkipVerification(true);
		$this->runMapper->method('find')->willReturn($run);

		$userMap = new UserMap();
		$userMap->setId(9);
		$userMap->setState(UserMap::STATE_ACTIVE);
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$this->fileMapper->method('countByState')->willReturn([
			MigrationFile::STATE_TRANSFER_FAILED => 98,
			MigrationFile::STATE_TRANSFERRED => 2223,
		]);
		$this->fileMapper->method('countRetryableFailures')->willReturn([
			'transferRetryable' => 0,
			'verificationRetryable' => 0,
		]);

		$this->jobList->expects($this->once())
			->method('add')
			->with(FinalizeJob::class, ['runId' => 42], 0);

		$this->orchestrator->onUserTransferComplete(42, 9);

		self::assertSame(MigrationRun::STATE_FINALIZING, $run->getState());
	}

	public function testReconcileStalledRunsAdvancesTransferringRunWithNoRemainingWork(): void {
		// Simulates a run left wedged in TRANSFERRING because its last
		// worker lineage finished without ever calling
		// onUserTransferComplete() (e.g. a crash, or a run that stalled
		// under since-fixed logic before this reconciliation existed).
		// CleanupLocksJob's periodic sweep should notice nothing is left
		// and advance it on its own, without needing a manual pause/resume.
		$run = $this->makeRun(MigrationRun::STATE_TRANSFERRING);
		$run->setSkipVerification(true);
		$this->runMapper->method('find')->willReturn($run);
		$this->runMapper->method('findActive')->willReturn([$run]);

		$userMap = new UserMap();
		$userMap->setId(9);
		$userMap->setState(UserMap::STATE_ACTIVE);
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$this->fileMapper->method('countByState')->willReturn([
			MigrationFile::STATE_TRANSFERRED => 2223,
		]);

		$this->jobList->expects($this->once())
			->method('add')
			->with(FinalizeJob::class, ['runId' => 42], 0);

		$this->orchestrator->reconcileStalledRuns();

		self::assertSame(MigrationRun::STATE_FINALIZING, $run->getState());
	}

	public function testReconcileStalledRunsLeavesTransferringRunAloneWhileWorkRemains(): void {
		$run = $this->makeRun(MigrationRun::STATE_TRANSFERRING);
		$this->runMapper->method('find')->willReturn($run);
		$this->runMapper->method('findActive')->willReturn([$run]);

		$userMap = new UserMap();
		$userMap->setId(9);
		$userMap->setState(UserMap::STATE_ACTIVE);
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$this->fileMapper->method('countByState')->willReturn([
			MigrationFile::STATE_DISCOVERED => 5,
		]);

		$this->jobList->expects($this->never())->method('add');

		$this->orchestrator->reconcileStalledRuns();

		self::assertSame(MigrationRun::STATE_TRANSFERRING, $run->getState());
	}

	public function testReconcileStalledRunsAdvancesVerifyingRunWithNoRemainingWork(): void {
		$run = $this->makeRun(MigrationRun::STATE_VERIFYING);
		$this->runMapper->method('find')->willReturn($run);
		$this->runMapper->method('findActive')->willReturn([$run]);

		$userMap = new UserMap();
		$userMap->setId(9);
		$userMap->setState(UserMap::STATE_ACTIVE);
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$this->fileMapper->method('countByState')->willReturn([
			MigrationFile::STATE_VERIFIED => 10,
		]);

		$this->jobList->expects($this->once())
			->method('add')
			->with(FinalizeJob::class, ['runId' => 42], 0);

		$this->orchestrator->reconcileStalledRuns();

		self::assertSame(MigrationRun::STATE_FINALIZING, $run->getState());
	}

	public function testStartSyncingRejectsUnfinishedRun(): void {
		$this->runMapper->method('find')->willReturn($this->makeRun(MigrationRun::STATE_TRANSFERRING));

		$this->expectException(\RuntimeException::class);
		$this->orchestrator->startSyncing(42);
	}

	public function testStartSyncingRejectsWrongCollisionStrategy(): void {
		$run = $this->makeRun(MigrationRun::STATE_COMPLETED);
		$run->setCollisionStrategy('rename');
		$this->runMapper->method('find')->willReturn($run);

		$this->expectException(\RuntimeException::class);
		$this->orchestrator->startSyncing(42);
	}

	public function testStartSyncingSucceedsForFinishedOverwriteNewerRun(): void {
		$run = $this->makeRun(MigrationRun::STATE_COMPLETED_WITH_ERRORS);
		$run->setCollisionStrategy('overwrite_newer');
		$this->runMapper->method('find')->willReturn($run);

		$run = $this->orchestrator->startSyncing(42);

		self::assertSame(MigrationRun::STATE_SYNCING, $run->getState());
	}

	public function testStopSyncingRejectsWhenNotSyncing(): void {
		$this->runMapper->method('find')->willReturn($this->makeRun(MigrationRun::STATE_COMPLETED));

		$this->expectException(\RuntimeException::class);
		$this->orchestrator->stopSyncing(42);
	}

	public function testStopSyncingSettlesIntoCompletedWithoutFailures(): void {
		$run = $this->makeRun(MigrationRun::STATE_SYNCING);
		$run->setCollisionStrategy('overwrite_newer');
		$this->runMapper->method('find')->willReturn($run);
		$this->fileMapper->method('countByState')->willReturn([
			MigrationFile::STATE_VERIFIED => 20,
		]);

		$run = $this->orchestrator->stopSyncing(42);

		self::assertSame(MigrationRun::STATE_COMPLETED, $run->getState());
		self::assertNotNull($run->getFinishedAt());
	}

	public function testStopSyncingSettlesIntoCompletedWithErrorsWhenFailuresRemain(): void {
		$run = $this->makeRun(MigrationRun::STATE_SYNCING);
		$run->setCollisionStrategy('overwrite_newer');
		$this->runMapper->method('find')->willReturn($run);
		$this->fileMapper->method('countByState')->willReturn([
			MigrationFile::STATE_VERIFIED => 18,
			MigrationFile::STATE_TRANSFER_FAILED => 2,
		]);

		$run = $this->orchestrator->stopSyncing(42);

		self::assertSame(MigrationRun::STATE_COMPLETED_WITH_ERRORS, $run->getState());
	}

	public function testRunSyncPassDoesNothingWhenRunIsNotSyncing(): void {
		$this->runMapper->method('find')->willReturn($this->makeRun(MigrationRun::STATE_COMPLETED));

		$this->userMapMapper->expects($this->never())->method('findByRun');

		$this->orchestrator->runSyncPass(42);
	}

	public function testRunSyncPassSpawnsTransferWorkerWhenChangesFound(): void {
		$run = $this->makeRun(MigrationRun::STATE_SYNCING);
		$run->setCollisionStrategy('overwrite_newer');
		$this->runMapper->method('find')->willReturn($run);

		$userMapA = new UserMap();
		$userMapA->setId(1);
		$userMapA->setState(UserMap::STATE_ACTIVE);
		$userMapA->setSourceUserId('alice');
		$userMapB = new UserMap();
		$userMapB->setId(2);
		$userMapB->setState(UserMap::STATE_FAILED);
		$userMapB->setSourceUserId('bob');
		$this->userMapMapper->method('findByRun')->willReturn([$userMapA, $userMapB]);

		$this->discoveryService->expects($this->once())
			->method('discoverIncremental')
			->with(42, $userMapA, 'alice', 0)
			->willReturn(['new' => 1, 'changed' => 0]);

		$this->fileMapper->method('countByState')->willReturn([
			MigrationFile::STATE_DISCOVERED => 1,
			MigrationFile::STATE_VERIFIED => 20,
		]);

		$this->jobList->expects($this->once())
			->method('add')
			->with(TransferWorkerJob::class, self::callback(fn ($arg) => $arg['runId'] === 42 && $arg['userMapId'] === 1), 0);

		$this->orchestrator->runSyncPass(42);

		self::assertNotNull($run->getLastSyncAt());
	}

	public function testRunSyncPassSkipsSpawningWhenNothingChanged(): void {
		$run = $this->makeRun(MigrationRun::STATE_SYNCING);
		$run->setCollisionStrategy('overwrite_newer');
		$this->runMapper->method('find')->willReturn($run);

		$userMap = new UserMap();
		$userMap->setId(1);
		$userMap->setState(UserMap::STATE_ACTIVE);
		$userMap->setSourceUserId('alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$this->discoveryService->method('discoverIncremental')->willReturn(['new' => 0, 'changed' => 0]);
		$this->fileMapper->method('countByState')->willReturn([
			MigrationFile::STATE_VERIFIED => 20,
		]);

		$this->jobList->expects($this->never())->method('add');

		$this->orchestrator->runSyncPass(42);
	}

	public function testRetryFailuresFromSyncingStaysSyncingAndRespawnsWorkers(): void {
		$run = $this->makeRun(MigrationRun::STATE_SYNCING);
		$run->setCollisionStrategy('overwrite_newer');
		$this->runMapper->method('find')->willReturn($run);
		$this->fileMapper->method('resetFailuresForRetry')->willReturn(3);

		$userMapA = new UserMap();
		$userMapA->setId(1);
		$userMapA->setState(UserMap::STATE_ACTIVE);
		$userMapB = new UserMap();
		$userMapB->setId(2);
		$userMapB->setState(UserMap::STATE_FAILED);
		$this->userMapMapper->method('findByRun')->willReturn([$userMapA, $userMapB]);

		$this->jobList->expects($this->once())
			->method('add')
			->with(TransferWorkerJob::class, self::callback(fn ($arg) => $arg['runId'] === 42 && $arg['userMapId'] === 1), 0);

		$run = $this->orchestrator->retryFailures(42);

		self::assertSame(MigrationRun::STATE_SYNCING, $run->getState());
	}
}
