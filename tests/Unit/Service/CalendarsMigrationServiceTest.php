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
use OCA\NextcloudMigrate\Service\ResourceMigrator\CalendarsMigrationService;
use OCA\NextcloudMigrate\Service\ResourceMigrator\SourceCalendarReader;
use OCA\NextcloudMigrate\Service\WebDavClient;
use PHPUnit\Framework\TestCase;

/**
 * Covers CalendarsMigrationService's discovery, per-item sync/verify flow,
 * and isRunComplete()'s convergence check - mirrors
 * ContactsMigrationServiceTest's structure (same underlying pattern).
 */
final class CalendarsMigrationServiceTest extends TestCase {
	private SourceCalendarReader $sourceReader;
	private WebDavClient $webDavClient;
	private CredentialService $credentialService;
	private MigrationResourceItemMapper $resourceItemMapper;
	private UserMapMapper $userMapMapper;
	private EventLogger $eventLogger;
	private CalendarsMigrationService $service;

	protected function setUp(): void {
		$this->sourceReader = $this->createMock(SourceCalendarReader::class);
		$this->webDavClient = $this->createMock(WebDavClient::class);
		$this->credentialService = $this->createMock(CredentialService::class);
		$this->resourceItemMapper = $this->createMock(MigrationResourceItemMapper::class);
		$this->userMapMapper = $this->createMock(UserMapMapper::class);
		$this->eventLogger = $this->createMock(EventLogger::class);

		$this->credentialService->method('decrypt')->willReturn('decrypted-app-password');
		$this->resourceItemMapper->method('insert')->willReturnArgument(0);
		$this->resourceItemMapper->method('update')->willReturnArgument(0);

		$this->service = new CalendarsMigrationService(
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

	public function testSyncRunDiscoversAndSyncsNewEvent(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);
		$this->resourceItemMapper->method('findOne')->willReturn(null);

		$this->sourceReader->method('listOwnedCalendars')->with('alice')->willReturn([
			['id' => 20, 'uri' => 'personal', 'displayName' => 'Personal'],
		]);
		$this->sourceReader->method('listCalendarObjects')->with(20)->willReturn([
			['uri' => 'event1.ics', 'calendarData' => "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:event1\r\nEND:VEVENT\r\nEND:VCALENDAR"],
		]);

		$inserted = [];
		$this->resourceItemMapper->method('insert')->willReturnCallback(function ($item) use (&$inserted) {
			$inserted[] = $item;
			return $item;
		});
		$this->resourceItemMapper->method('findPendingForUser')->willReturnCallback(function () use (&$inserted) {
			return array_values(array_filter($inserted, fn ($i) => $i->getExternalId() !== '__discovered__'));
		});

		$this->webDavClient->expects($this->once())->method('makeCalendar');
		$this->webDavClient->expects($this->once())->method('putRaw');
		$this->webDavClient->method('getRaw')->willReturn("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:event1\r\nEND:VEVENT\r\nEND:VCALENDAR");

		$saved = null;
		$this->resourceItemMapper->method('update')->willReturnCallback(function ($item) use (&$saved) {
			$saved = $item;
			return $item;
		});

		$this->service->syncRun($this->makeRun(), $this->makeInstance(), time() + 60);

		self::assertCount(2, $inserted);
		self::assertNotNull($saved);
		self::assertSame(MigrationResourceItem::STATE_SYNCED, $saved->getState());
		self::assertSame('calendars/alice/personal/event1.ics', $saved->getTargetRef());
	}

	public function testSyncRunCreatesMarkerEvenWithNoCalendars(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);
		$this->resourceItemMapper->method('findOne')->willReturn(null);
		$this->sourceReader->method('listOwnedCalendars')->willReturn([]);

		$inserted = [];
		$this->resourceItemMapper->method('insert')->willReturnCallback(function ($item) use (&$inserted) {
			$inserted[] = $item;
			return $item;
		});
		$this->resourceItemMapper->method('findPendingForUser')->willReturn([]);

		$this->webDavClient->expects($this->never())->method('makeCalendar');

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

		$this->sourceReader->expects($this->never())->method('listOwnedCalendars');

		$this->service->syncRun($this->makeRun(), $this->makeInstance(), time() + 60);
	}

	public function testFailedUserMapIsSkipped(): void {
		$userMap = $this->makeUserMap(1, 'alice', 'alice');
		$userMap->setState(UserMap::STATE_FAILED);
		$this->userMapMapper->method('findByRun')->willReturn([$userMap]);

		$this->resourceItemMapper->expects($this->never())->method('findOne');
		$this->sourceReader->expects($this->never())->method('listOwnedCalendars');

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
		$pending->setPayload(json_encode(['calendarUri' => 'personal', 'objectUri' => 'event1.ics', 'calendarData' => 'X']));

		$this->resourceItemMapper->method('findOne')->willReturn($marker);
		$this->resourceItemMapper->method('findPendingForUser')->willReturn([$pending]);

		$this->webDavClient->method('makeCalendar')->willThrowException(new RemoteConnectionException('unreachable', 502));

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
