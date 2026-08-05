<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use OCA\NextcloudMigrate\BackgroundJob\EnqueueTransfersJob;
use OCA\NextcloudMigrate\BackgroundJob\FinalizeJob;
use OCA\NextcloudMigrate\BackgroundJob\VerifyWorkerJob;
use OCA\NextcloudMigrate\Db\MigrationFile;
use OCA\NextcloudMigrate\Db\MigrationFileMapper;
use OCA\NextcloudMigrate\Db\MigrationRun;
use OCA\NextcloudMigrate\Db\MigrationRunMapper;
use OCA\NextcloudMigrate\Db\RemoteInstanceMapper;
use OCA\NextcloudMigrate\Db\UserMap;
use OCA\NextcloudMigrate\Db\UserMapMapper;
use OCA\NextcloudMigrate\Service\CredentialService;
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
	private WebDavClient $webDavClient;
	private ProvisioningClient $provisioningClient;
	private CredentialService $credentialService;
	private ReportService $reportService;
	private EventLogger $eventLogger;
	private IJobList $jobList;
	private IConfig $config;
	private RunOrchestrator $orchestrator;

	protected function setUp(): void {
		$this->runMapper = $this->createMock(MigrationRunMapper::class);
		$this->instanceMapper = $this->createMock(RemoteInstanceMapper::class);
		$this->userMapMapper = $this->createMock(UserMapMapper::class);
		$this->fileMapper = $this->createMock(MigrationFileMapper::class);
		$this->webDavClient = $this->createMock(WebDavClient::class);
		$this->provisioningClient = $this->createMock(ProvisioningClient::class);
		$this->credentialService = $this->createMock(CredentialService::class);
		$this->reportService = $this->createMock(ReportService::class);
		$this->eventLogger = $this->createMock(EventLogger::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->config = $this->createMock(IConfig::class);

		// update() just needs to accept a MigrationRun and hand it back.
		$this->runMapper->method('update')->willReturnArgument(0);

		$this->orchestrator = new RunOrchestrator(
			$this->runMapper,
			$this->instanceMapper,
			$this->userMapMapper,
			$this->fileMapper,
			$this->webDavClient,
			$this->provisioningClient,
			$this->credentialService,
			$this->reportService,
			$this->eventLogger,
			$this->jobList,
			$this->config,
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
}
