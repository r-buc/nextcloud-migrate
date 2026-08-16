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
use OCA\NextcloudMigrate\Service\ProvisioningClient;
use OCA\NextcloudMigrate\Service\ResourceMigrator\UserInfoMigrationService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Covers UserInfoMigrationService's per-user sync/verify flow: new item
 * creation on first sync, skipping already-terminal items, a vanished
 * source user producing an immediate permanent failure, a provisioning
 * failure being recorded, and isRunComplete()'s convergence check.
 */
final class UserInfoMigrationServiceTest extends TestCase {
	private IUserManager $userManager;
	private IGroupManager $groupManager;
	private IConfig $config;
	private ProvisioningClient $provisioningClient;
	private CredentialService $credentialService;
	private MigrationResourceItemMapper $resourceItemMapper;
	private UserMapMapper $userMapMapper;
	private EventLogger $eventLogger;
	private UserInfoMigrationService $service;

	protected function setUp(): void {
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->provisioningClient = $this->createMock(ProvisioningClient::class);
		$this->credentialService = $this->createMock(CredentialService::class);
		$this->resourceItemMapper = $this->createMock(MigrationResourceItemMapper::class);
		$this->userMapMapper = $this->createMock(UserMapMapper::class);
		$this->eventLogger = $this->createMock(EventLogger::class);

		$this->credentialService->method('decrypt')->willReturn('decrypted-admin-password');
		$this->resourceItemMapper->method('insert')->willReturnArgument(0);
		$this->resourceItemMapper->method('update')->willReturnArgument(0);

		$this->service = new UserInfoMigrationService(
			$this->userManager,
			$this->groupManager,
			$this->config,
			$this->provisioningClient,
			$this->credentialService,
			$this->resourceItemMapper,
			$this->userMapMapper,
			$this->eventLogger,
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
		$instance->setAdminUserId('admin');
		$instance->setAdminAppPasswordEncrypted('encrypted');
		return $instance;
	}

	private function makeUserMap(int $id, string $sourceUserId, string $targetUserId): UserMap {
		$userMap = new UserMap();
		$userMap->setId($id);
		$userMap->setRunId(42);
		$userMap->setSourceUserId($sourceUserId);
		$userMap->setTargetUserId($targetUserId);
		$userMap->setState(UserMap::STATE_ACTIVE);
		return $userMap;
	}

	public function testSyncRunCreatesAndSyncsNewItem(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);
		$this->resourceItemMapper->method('findOne')->willReturn(null);

		$sourceUser = $this->createMock(IUser::class);
		$sourceUser->method('getDisplayName')->willReturn('Alice Wonderland');
		$sourceUser->method('getEMailAddress')->willReturn('alice@example.com');
		$sourceUser->method('getQuota')->willReturn('5 GB');
		$this->userManager->method('get')->with('alice')->willReturn($sourceUser);
		$this->groupManager->method('getUserGroupIds')->willReturn(['editors']);
		$this->config->method('getUserValue')->with('alice', 'core', 'lang', '')->willReturn('de');

		$this->provisioningClient->expects($this->exactly(4))->method('editUserField');
		$this->provisioningClient->expects($this->once())->method('ensureGroupExists');
		$this->provisioningClient->expects($this->once())->method('addUserToGroup');
		$this->provisioningClient->method('getUser')->willReturn([
			'displayname' => 'Alice Wonderland',
			'email' => 'alice@example.com',
			'language' => 'de',
			'groups' => ['editors'],
		]);

		$saved = null;
		$this->resourceItemMapper->method('update')->willReturnCallback(function ($item) use (&$saved) {
			$saved = $item;
			return $item;
		});

		$this->service->syncRun($this->makeRun(), $this->makeInstance(), time() + 60);

		self::assertNotNull($saved);
		self::assertSame(MigrationResourceItem::STATE_SYNCED, $saved->getState());
		self::assertSame('alice', $saved->getTargetRef());
	}

	public function testVanishedSourceUserIsMarkedFailedImmediately(): void {
		$userMap = $this->makeUserMap(1, 'ghost', 'ghost');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);
		$this->resourceItemMapper->method('findOne')->willReturn(null);
		$this->userManager->method('get')->willReturn(null);

		$this->provisioningClient->expects($this->never())->method('editUserField');

		$saved = null;
		$this->resourceItemMapper->method('insert')->willReturnCallback(function ($item) use (&$saved) {
			$saved = $item;
			return $item;
		});

		$this->service->syncRun($this->makeRun(), $this->makeInstance(), time() + 60);

		self::assertNotNull($saved);
		self::assertSame(MigrationResourceItem::STATE_FAILED, $saved->getState());
		self::assertStringContainsString('no longer exists', $saved->getLastError());
	}

	public function testAlreadyTerminalItemIsSkipped(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$existing = new MigrationResourceItem();
		$existing->setState(MigrationResourceItem::STATE_SYNCED);
		$this->resourceItemMapper->method('findOne')->willReturn($existing);

		$this->userManager->expects($this->never())->method('get');
		$this->provisioningClient->expects($this->never())->method('editUserField');

		$this->service->syncRun($this->makeRun(), $this->makeInstance(), time() + 60);
	}

	public function testFailedUserMapIsSkipped(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$userMap->setState(UserMap::STATE_FAILED);
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$this->resourceItemMapper->expects($this->never())->method('findOne');

		$this->service->syncRun($this->makeRun(), $this->makeInstance(), time() + 60);
	}

	public function testProvisioningFailureMarksItemFailed(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);
		$this->resourceItemMapper->method('findOne')->willReturn(null);

		$sourceUser = $this->createMock(IUser::class);
		$sourceUser->method('getDisplayName')->willReturn('Alice Wonderland');
		$sourceUser->method('getEMailAddress')->willReturn('alice@example.com');
		$sourceUser->method('getQuota')->willReturn('5 GB');
		$this->userManager->method('get')->willReturn($sourceUser);
		$this->groupManager->method('getUserGroupIds')->willReturn([]);
		$this->config->method('getUserValue')->willReturn('');

		$this->provisioningClient->method('editUserField')->willThrowException(new RemoteConnectionException('target unreachable', 502));

		$saved = null;
		$this->resourceItemMapper->method('update')->willReturnCallback(function ($item) use (&$saved) {
			$saved = $item;
			return $item;
		});

		$this->service->syncRun($this->makeRun(), $this->makeInstance(), time() + 60);

		self::assertNotNull($saved);
		self::assertSame(MigrationResourceItem::STATE_FAILED, $saved->getState());
		self::assertSame('target unreachable', $saved->getLastError());
	}

	public function testSyncRunStopsAtDeadline(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$this->resourceItemMapper->expects($this->never())->method('findOne');

		// Deadline already in the past - nothing should be processed.
		$this->service->syncRun($this->makeRun(), $this->makeInstance(), time() - 1);
	}

	public function testIsRunCompleteFalseWhenItemPending(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$pending = new MigrationResourceItem();
		$pending->setState(MigrationResourceItem::STATE_PENDING);
		$this->resourceItemMapper->method('findOne')->willReturn($pending);

		self::assertFalse($this->service->isRunComplete(42));
	}

	public function testIsRunCompleteTrueWhenAllTerminal(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$synced = new MigrationResourceItem();
		$synced->setState(MigrationResourceItem::STATE_SYNCED);
		$this->resourceItemMapper->method('findOne')->willReturn($synced);

		self::assertTrue($this->service->isRunComplete(42));
	}

	public function testIsRunCompleteIgnoresFailedUserMaps(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$userMap->setState(UserMap::STATE_FAILED);
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$this->resourceItemMapper->expects($this->never())->method('findOne');

		self::assertTrue($this->service->isRunComplete(42));
	}
}
