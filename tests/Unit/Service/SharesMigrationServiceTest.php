<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use OCA\NextcloudMigrate\Db\MigrationResourceItem;
use OCA\NextcloudMigrate\Db\MigrationResourceItemMapper;
use OCA\NextcloudMigrate\Db\MigrationRun;
use OCA\NextcloudMigrate\Db\RemoteInstance;
use OCA\NextcloudMigrate\Db\UserMap;
use OCA\NextcloudMigrate\Db\UserMapMapper;
use OCA\NextcloudMigrate\Exception\RemoteConnectionException;
use OCA\NextcloudMigrate\Service\CredentialService;
use OCA\NextcloudMigrate\Service\EventLogger;
use OCA\NextcloudMigrate\Service\ResourceMigrator\SharesMigrationService;
use OCA\NextcloudMigrate\Service\ResourceMigrator\SourceShareReader;
use OCA\NextcloudMigrate\Service\RunOrchestrator;
use OCA\NextcloudMigrate\Service\SharingClient;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * Covers SharesMigrationService's file-settled gating, discovery,
 * per-item sync flow (incl. the unmappable-recipient skip case), and
 * isRunComplete()'s convergence check.
 */
final class SharesMigrationServiceTest extends TestCase {
	private SourceShareReader $sourceReader;
	private SharingClient $sharingClient;
	private CredentialService $credentialService;
	private MigrationResourceItemMapper $resourceItemMapper;
	private UserMapMapper $userMapMapper;
	private EventLogger $eventLogger;
	private RunOrchestrator $runOrchestrator;
	private SharesMigrationService $service;

	protected function setUp(): void {
		$this->sourceReader = $this->createMock(SourceShareReader::class);
		$this->sharingClient = $this->createMock(SharingClient::class);
		$this->credentialService = $this->createMock(CredentialService::class);
		$this->resourceItemMapper = $this->createMock(MigrationResourceItemMapper::class);
		$this->userMapMapper = $this->createMock(UserMapMapper::class);
		$this->eventLogger = $this->createMock(EventLogger::class);
		$this->runOrchestrator = $this->createMock(RunOrchestrator::class);

		$this->credentialService->method('decrypt')->willReturn('decrypted-app-password');
		$this->resourceItemMapper->method('insert')->willReturnArgument(0);
		$this->resourceItemMapper->method('update')->willReturnArgument(0);
		$this->runOrchestrator->method('isUserFilesSettled')->willReturn(true);

		$this->service = new SharesMigrationService(
			$this->sourceReader,
			$this->sharingClient,
			$this->credentialService,
			$this->resourceItemMapper,
			$this->userMapMapper,
			$this->eventLogger,
			$this->runOrchestrator,
		);
	}

	private function makeRun(): MigrationRun {
		$run = new MigrationRun();
		$run->setId(42);
		return $run;
	}

	private function makeInstance(): RemoteInstance {
		$instance = new RemoteInstance();
		$instance->setId(1);
		return $instance;
	}

	private function makeUserMap(int $id, string $sourceUserId, string $targetUserId): UserMap {
		$userMap = new UserMap();
		$userMap->setId($id);
		$userMap->setRunId(42);
		$userMap->setSourceUserId($sourceUserId);
		$userMap->setTargetUserId($targetUserId);
		$userMap->setTargetAppPasswordEncrypted('encrypted-app-password');
		$userMap->setState(UserMap::STATE_ACTIVE);
		return $userMap;
	}

	public function testDiscoveryWaitsUntilFilesAreSettled(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);
		$this->resourceItemMapper->method('findOne')->willReturn(null);
		$this->runOrchestrator = $this->createMock(RunOrchestrator::class);
		$this->runOrchestrator->method('isUserFilesSettled')->willReturn(false);
		$this->service = new SharesMigrationService(
			$this->sourceReader,
			$this->sharingClient,
			$this->credentialService,
			$this->resourceItemMapper,
			$this->userMapMapper,
			$this->eventLogger,
			$this->runOrchestrator,
		);

		$this->sourceReader->expects($this->never())->method('listOwnedShares');
		$this->resourceItemMapper->expects($this->never())->method('insert');

		$this->service->syncRun($this->makeRun(), $this->makeInstance(), time() + 60);
	}

	public function testSyncRunDiscoversAndSyncsLinkShare(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);
		$this->resourceItemMapper->method('findOne')->willReturn(null);

		$this->sourceReader->method('listOwnedShares')->with('alice')->willReturn([
			['id' => 'share1', 'shareType' => IShare::TYPE_LINK, 'path' => 'Documents/report.pdf', 'sharedWith' => null, 'permissions' => 1, 'expiration' => null, 'label' => 'Public link'],
		]);

		$inserted = [];
		$this->resourceItemMapper->method('insert')->willReturnCallback(function ($item) use (&$inserted) {
			$inserted[] = $item;
			return $item;
		});
		$this->resourceItemMapper->method('findPendingForUser')->willReturnCallback(function () use (&$inserted) {
			return array_values(array_filter($inserted, fn ($i) => $i->getExternalId() !== '__discovered__'));
		});

		$this->sharingClient->expects($this->once())->method('createShare')->willReturn(['id' => '99']);

		$saved = null;
		$this->resourceItemMapper->method('update')->willReturnCallback(function ($item) use (&$saved) {
			$saved = $item;
			return $item;
		});

		$this->service->syncRun($this->makeRun(), $this->makeInstance(), time() + 60);

		self::assertCount(2, $inserted);
		self::assertNotNull($saved);
		self::assertSame(MigrationResourceItem::STATE_SYNCED, $saved->getState());
		self::assertSame('99', $saved->getTargetRef());
	}

	public function testUnmappableRecipientIsSkippedWithWarning(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$marker = new MigrationResourceItem();
		$marker->setState(MigrationResourceItem::STATE_SYNCED);
		$pending = new MigrationResourceItem();
		$pending->setState(MigrationResourceItem::STATE_PENDING);
		$pending->setAttempts(0);
		$pending->setPayload(json_encode([
			'shareType' => IShare::TYPE_USER,
			'path' => 'Documents/report.pdf',
			'sharedWith' => 'not-in-this-run',
			'permissions' => 1,
		]));

		$this->resourceItemMapper->method('findOne')->willReturn($marker);
		$this->resourceItemMapper->method('findPendingForUser')->willReturn([$pending]);

		$this->sharingClient->expects($this->never())->method('createShare');
		$this->eventLogger->expects($this->once())->method('log')->with(42, 'share_recipient_unmapped', self::isType('string'), 'warning');

		$saved = null;
		$this->resourceItemMapper->method('update')->willReturnCallback(function ($item) use (&$saved) {
			$saved = $item;
			return $item;
		});

		$this->service->syncRun($this->makeRun(), $this->makeInstance(), time() + 60);

		self::assertNotNull($saved);
		self::assertSame(MigrationResourceItem::STATE_FAILED, $saved->getState());
	}

	public function testUserShareRecipientIsTranslatedWhenMapped(): void {
		$aliceMap = $this->makeUserMap(1, 'alice', 'alice');
		$bobMap = $this->makeUserMap(2, 'bob', 'bob2');
		$this->userMapMapper->method('findByRun')->willReturn([$aliceMap, $bobMap]);

		$marker = new MigrationResourceItem();
		$marker->setState(MigrationResourceItem::STATE_SYNCED);
		$pending = new MigrationResourceItem();
		$pending->setState(MigrationResourceItem::STATE_PENDING);
		$pending->setAttempts(0);
		$pending->setPayload(json_encode([
			'shareType' => IShare::TYPE_USER,
			'path' => 'Documents/report.pdf',
			'sharedWith' => 'bob',
			'permissions' => 1,
		]));

		$this->resourceItemMapper->method('findOne')->willReturn($marker);
		$this->resourceItemMapper->method('findPendingForUser')->willReturnOnConsecutiveCalls([$pending], []);

		$this->sharingClient->expects($this->once())->method('createShare')
			->with(self::anything(), 'alice', self::anything(), 'Documents/report.pdf', IShare::TYPE_USER, 'bob2', 1, null, null)
			->willReturn(['id' => '5']);

		$this->service->syncRun($this->makeRun(), $this->makeInstance(), time() + 60);
	}

	public function testFailedUserMapIsSkipped(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$userMap->setState(UserMap::STATE_FAILED);
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$this->resourceItemMapper->expects($this->never())->method('findOne');
		$this->sourceReader->expects($this->never())->method('listOwnedShares');

		$this->service->syncRun($this->makeRun(), $this->makeInstance(), time() + 60);
	}

	public function testShareApiFailureMarksItemFailed(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$marker = new MigrationResourceItem();
		$marker->setState(MigrationResourceItem::STATE_SYNCED);
		$pending = new MigrationResourceItem();
		$pending->setState(MigrationResourceItem::STATE_PENDING);
		$pending->setAttempts(0);
		$pending->setPayload(json_encode(['shareType' => IShare::TYPE_LINK, 'path' => 'Documents/report.pdf', 'permissions' => 1]));

		$this->resourceItemMapper->method('findOne')->willReturn($marker);
		$this->resourceItemMapper->method('findPendingForUser')->willReturn([$pending]);

		$this->sharingClient->method('createShare')->willThrowException(new RemoteConnectionException('path not found', 404));

		$saved = null;
		$this->resourceItemMapper->method('update')->willReturnCallback(function ($item) use (&$saved) {
			$saved = $item;
			return $item;
		});

		$this->service->syncRun($this->makeRun(), $this->makeInstance(), time() + 60);

		self::assertNotNull($saved);
		self::assertSame(MigrationResourceItem::STATE_FAILED, $saved->getState());
		self::assertSame('path not found', $saved->getLastError());
	}

	public function testIsRunCompleteFalseWithoutMarker(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);
		$this->resourceItemMapper->method('findOne')->willReturn(null);

		self::assertFalse($this->service->isRunComplete(42));
	}

	public function testIsRunCompleteTrueWhenAllTerminal(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$marker = new MigrationResourceItem();
		$marker->setState(MigrationResourceItem::STATE_SYNCED);
		$this->resourceItemMapper->method('findOne')->willReturn($marker);
		$this->resourceItemMapper->method('countByStateForUser')->willReturn(['synced' => 2, 'failed' => 1]);

		self::assertTrue($this->service->isRunComplete(42));
	}
}
