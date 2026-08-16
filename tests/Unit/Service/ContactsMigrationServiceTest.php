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
use OCA\NextcloudMigrate\Service\ResourceMigrator\ContactsMigrationService;
use OCA\NextcloudMigrate\Service\ResourceMigrator\SourceCardDavReader;
use OCA\NextcloudMigrate\Service\WebDavClient;
use PHPUnit\Framework\TestCase;

/**
 * Covers ContactsMigrationService's discovery (incl. the zero-addressbook
 * and already-discovered cases), per-item sync/verify flow, and
 * isRunComplete()'s convergence check.
 */
final class ContactsMigrationServiceTest extends TestCase {
	private SourceCardDavReader $sourceReader;
	private WebDavClient $webDavClient;
	private CredentialService $credentialService;
	private MigrationResourceItemMapper $resourceItemMapper;
	private UserMapMapper $userMapMapper;
	private EventLogger $eventLogger;
	private ContactsMigrationService $service;

	protected function setUp(): void {
		$this->sourceReader = $this->createMock(SourceCardDavReader::class);
		$this->webDavClient = $this->createMock(WebDavClient::class);
		$this->credentialService = $this->createMock(CredentialService::class);
		$this->resourceItemMapper = $this->createMock(MigrationResourceItemMapper::class);
		$this->userMapMapper = $this->createMock(UserMapMapper::class);
		$this->eventLogger = $this->createMock(EventLogger::class);

		$this->credentialService->method('decrypt')->willReturn('decrypted-app-password');
		$this->resourceItemMapper->method('insert')->willReturnArgument(0);
		$this->resourceItemMapper->method('update')->willReturnArgument(0);

		$this->service = new ContactsMigrationService(
			$this->sourceReader,
			$this->webDavClient,
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

	public function testSyncRunDiscoversAndSyncsNewCard(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);
		// No discovery marker yet, no existing card item.
		$this->resourceItemMapper->method('findOne')->willReturn(null);

		$this->sourceReader->method('listOwnedAddressBooks')->with('alice')->willReturn([
			['id' => 10, 'uri' => 'contacts', 'displayName' => 'Contacts'],
		]);
		$this->sourceReader->method('listCards')->with(10)->willReturn([
			['uri' => 'card1.vcf', 'cardData' => "BEGIN:VCARD\r\nUID:card1\r\nEND:VCARD"],
		]);

		$inserted = [];
		$this->resourceItemMapper->method('insert')->willReturnCallback(function ($item) use (&$inserted) {
			$inserted[] = $item;
			return $item;
		});

		// findPendingForUser is called after discovery; return the just
		// "inserted" pending card item (simulating a fresh DB read).
		$this->resourceItemMapper->method('findPendingForUser')->willReturnCallback(function () use (&$inserted) {
			return array_values(array_filter($inserted, fn ($i) => $i->getExternalId() !== '__discovered__'));
		});

		$this->webDavClient->expects($this->once())->method('makeAddressBook');
		$this->webDavClient->expects($this->once())->method('putRaw');
		$this->webDavClient->method('getRaw')->willReturn("BEGIN:VCARD\r\nUID:card1\r\nEND:VCARD");

		$saved = null;
		$this->resourceItemMapper->method('update')->willReturnCallback(function ($item) use (&$saved) {
			$saved = $item;
			return $item;
		});

		$this->service->syncRun($this->makeRun(), $this->makeInstance(), time() + 60);

		// A marker row plus the real card row should have been inserted.
		self::assertCount(2, $inserted);
		self::assertNotNull($saved);
		self::assertSame(MigrationResourceItem::STATE_SYNCED, $saved->getState());
		self::assertSame('addressbooks/users/alice/contacts/card1.vcf', $saved->getTargetRef());
	}

	public function testSyncRunCreatesMarkerEvenWithNoAddressBooks(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);
		$this->resourceItemMapper->method('findOne')->willReturn(null);
		$this->sourceReader->method('listOwnedAddressBooks')->willReturn([]);

		$inserted = [];
		$this->resourceItemMapper->method('insert')->willReturnCallback(function ($item) use (&$inserted) {
			$inserted[] = $item;
			return $item;
		});
		$this->resourceItemMapper->method('findPendingForUser')->willReturn([]);

		$this->webDavClient->expects($this->never())->method('makeAddressBook');

		$this->service->syncRun($this->makeRun(), $this->makeInstance(), time() + 60);

		self::assertCount(1, $inserted);
		self::assertSame('__discovered__', $inserted[0]->getExternalId());
	}

	public function testSyncRunSkipsAlreadyDiscoveredUser(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$marker = new MigrationResourceItem();
		$marker->setState(MigrationResourceItem::STATE_SYNCED);
		$this->resourceItemMapper->method('findOne')->willReturn($marker);
		$this->resourceItemMapper->method('findPendingForUser')->willReturn([]);

		$this->sourceReader->expects($this->never())->method('listOwnedAddressBooks');

		$this->service->syncRun($this->makeRun(), $this->makeInstance(), time() + 60);
	}

	public function testFailedUserMapIsSkipped(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$userMap->setState(UserMap::STATE_FAILED);
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$this->resourceItemMapper->expects($this->never())->method('findOne');
		$this->sourceReader->expects($this->never())->method('listOwnedAddressBooks');

		$this->service->syncRun($this->makeRun(), $this->makeInstance(), time() + 60);
	}

	public function testWebDavFailureMarksItemFailed(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$marker = new MigrationResourceItem();
		$marker->setState(MigrationResourceItem::STATE_SYNCED);
		$pending = new MigrationResourceItem();
		$pending->setState(MigrationResourceItem::STATE_PENDING);
		$pending->setAttempts(0);
		$pending->setPayload(json_encode(['addressBookUri' => 'contacts', 'cardUri' => 'card1.vcf', 'cardData' => 'X']));

		// findOne(marker) returns already-discovered; findPendingForUser
		// returns the one pending item to process.
		$this->resourceItemMapper->method('findOne')->willReturn($marker);
		$this->resourceItemMapper->method('findPendingForUser')->willReturn([$pending]);

		$this->webDavClient->method('makeAddressBook')->willThrowException(new RemoteConnectionException('unreachable', 502));

		$saved = null;
		$this->resourceItemMapper->method('update')->willReturnCallback(function ($item) use (&$saved) {
			$saved = $item;
			return $item;
		});

		$this->service->syncRun($this->makeRun(), $this->makeInstance(), time() + 60);

		self::assertNotNull($saved);
		self::assertSame(MigrationResourceItem::STATE_FAILED, $saved->getState());
		self::assertSame('unreachable', $saved->getLastError());
	}

	public function testIsRunCompleteFalseWithoutMarker(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);
		$this->resourceItemMapper->method('findOne')->willReturn(null);

		self::assertFalse($this->service->isRunComplete(42));
	}

	public function testIsRunCompleteFalseWithPendingItems(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$marker = new MigrationResourceItem();
		$marker->setState(MigrationResourceItem::STATE_SYNCED);
		$this->resourceItemMapper->method('findOne')->willReturn($marker);
		$this->resourceItemMapper->method('countByStateForUser')->willReturn(['pending' => 2, 'synced' => 1]);

		self::assertFalse($this->service->isRunComplete(42));
	}

	public function testIsRunCompleteTrueWhenAllTerminal(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$marker = new MigrationResourceItem();
		$marker->setState(MigrationResourceItem::STATE_SYNCED);
		$this->resourceItemMapper->method('findOne')->willReturn($marker);
		$this->resourceItemMapper->method('countByStateForUser')->willReturn(['synced' => 3, 'failed' => 1]);

		self::assertTrue($this->service->isRunComplete(42));
	}
}
