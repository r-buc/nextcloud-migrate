<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service\ResourceMigrator;

use OCA\NextcloudMigrate\Db\MigrationResourceItem;
use OCA\NextcloudMigrate\Db\MigrationResourceItemMapper;
use OCA\NextcloudMigrate\Db\MigrationRun;
use OCA\NextcloudMigrate\Db\RemoteInstance;
use OCA\NextcloudMigrate\Db\UserMap;
use OCA\NextcloudMigrate\Db\UserMapMapper;
use OCA\NextcloudMigrate\Service\CredentialService;
use OCA\NextcloudMigrate\Service\EventLogger;
use OCA\NextcloudMigrate\Service\WebDavClient;

/**
 * Migrates a mapped user's own calendars (and the events/tasks within
 * them) to the target instance. Mirrors ContactsMigrationService exactly
 * (see its docblock for the shared reasoning) - source data read
 * in-process via SourceCalendarReader, target writes over CalDAV via
 * WebDavClient authenticated as the mapped user's own app password.
 *
 * Each calendar object becomes one MigrationResourceItem (external_id =
 * "{calendarUri}/{objectUri}"), plus a per-user discovery marker row (see
 * ContactsMigrationService::MARKER_EXTERNAL_ID's docblock for why).
 */
class CalendarsMigrationService implements ResourceMigratorInterface {
	public const TYPE = 'calendar';
	public const MARKER_EXTERNAL_ID = '__discovered__';

	public function __construct(
		private SourceCalendarReader $sourceReader,
		private WebDavClient $webDavClient,
		private CredentialService $credentialService,
		private MigrationResourceItemMapper $resourceItemMapper,
		private UserMapMapper $userMapMapper,
		private EventLogger $eventLogger,
	) {
	}

	public function getType(): string {
		return self::TYPE;
	}

	public function syncRun(MigrationRun $run, RemoteInstance $instance, int $deadline): void {
		foreach ($this->userMapMapper->findByRun($run->getId()) as $userMap) {
			if ($userMap->getState() === UserMap::STATE_FAILED) {
				continue;
			}
			if (time() >= $deadline) {
				return;
			}

			$this->ensureDiscovered($run, $userMap);

			foreach ($this->resourceItemMapper->findPendingForUser($run->getId(), $userMap->getId(), self::TYPE) as $item) {
				if (time() >= $deadline) {
					return;
				}
				$this->processItem($run, $instance, $userMap, $item);
			}
		}
	}

	public function isRunComplete(int $runId): bool {
		foreach ($this->userMapMapper->findByRun($runId) as $userMap) {
			if ($userMap->getState() === UserMap::STATE_FAILED) {
				continue;
			}
			$marker = $this->resourceItemMapper->findOne($runId, $userMap->getId(), self::TYPE, self::MARKER_EXTERNAL_ID);
			if ($marker === null) {
				return false;
			}
			$counts = $this->resourceItemMapper->countByStateForUser($runId, $userMap->getId(), self::TYPE);
			if (($counts[MigrationResourceItem::STATE_PENDING] ?? 0) > 0) {
				return false;
			}
		}

		return true;
	}

	private function ensureDiscovered(MigrationRun $run, UserMap $userMap): void {
		$marker = $this->resourceItemMapper->findOne($run->getId(), $userMap->getId(), self::TYPE, self::MARKER_EXTERNAL_ID);
		if ($marker !== null) {
			return;
		}

		$now = time();
		foreach ($this->sourceReader->listOwnedCalendars($userMap->getSourceUserId()) as $calendar) {
			foreach ($this->sourceReader->listCalendarObjects($calendar['id']) as $object) {
				$externalId = $calendar['uri'] . '/' . $object['uri'];
				if ($this->resourceItemMapper->findOne($run->getId(), $userMap->getId(), self::TYPE, $externalId) !== null) {
					continue;
				}

				$item = new MigrationResourceItem();
				$item->setRunId($run->getId());
				$item->setUserMapId($userMap->getId());
				$item->setResourceType(self::TYPE);
				$item->setExternalId($externalId);
				$item->setState(MigrationResourceItem::STATE_PENDING);
				$item->setAttempts(0);
				$item->setPayload(json_encode([
					'calendarUri' => $calendar['uri'],
					'calendarDisplayName' => $calendar['displayName'],
					'objectUri' => $object['uri'],
					'calendarData' => $object['calendarData'],
				]));
				$item->setCreatedAt($now);
				$item->setUpdatedAt($now);
				$this->resourceItemMapper->insert($item);
			}
		}

		$marker = new MigrationResourceItem();
		$marker->setRunId($run->getId());
		$marker->setUserMapId($userMap->getId());
		$marker->setResourceType(self::TYPE);
		$marker->setExternalId(self::MARKER_EXTERNAL_ID);
		$marker->setState(MigrationResourceItem::STATE_SYNCED);
		$marker->setAttempts(0);
		$marker->setCreatedAt($now);
		$marker->setUpdatedAt($now);
		$this->resourceItemMapper->insert($marker);
	}

	private function processItem(MigrationRun $run, RemoteInstance $instance, UserMap $userMap, MigrationResourceItem $item): void {
		$payload = json_decode($item->getPayload() ?? '[]', true);
		if (!is_array($payload)) {
			$payload = [];
		}

		$targetUserId = $userMap->getTargetUserId();
		$calendarUri = (string)($payload['calendarUri'] ?? '');
		$objectUri = (string)($payload['objectUri'] ?? '');
		$calendarData = (string)($payload['calendarData'] ?? '');
		$calendarPath = "calendars/{$targetUserId}/{$calendarUri}";
		$objectPath = "{$calendarPath}/{$objectUri}";

		try {
			$appPassword = $this->credentialService->decrypt($userMap->getTargetAppPasswordEncrypted());

			$this->webDavClient->makeCalendar($instance, $targetUserId, $appPassword, $calendarPath, (string)($payload['calendarDisplayName'] ?? $calendarUri));
			$this->webDavClient->putRaw($instance, $targetUserId, $appPassword, $objectPath, $calendarData, 'text/calendar; charset=utf-8');

			$actual = $this->webDavClient->getRaw($instance, $targetUserId, $appPassword, $objectPath);
			$verified = trim($actual) === trim($calendarData);

			$item->setState($verified ? MigrationResourceItem::STATE_SYNCED : MigrationResourceItem::STATE_FAILED);
			$item->setTargetRef($objectPath);
			$item->setLastError($verified ? null : 'Post-sync verification found mismatched calendar object content on the target');
			$item->setAttempts($item->getAttempts() + 1);
			$item->setUpdatedAt(time());
			$this->resourceItemMapper->update($item);

			$this->eventLogger->log(
				$run->getId(),
				$verified ? 'calendar_object_synced' : 'calendar_object_verify_failed',
				"Calendar object '{$objectUri}' in calendar '{$calendarUri}' for '{$userMap->getSourceUserId()}' -> '{$targetUserId}'" . ($verified ? ' synced' : ' synced but verification found mismatches'),
				$verified ? 'info' : 'warning',
			);
		} catch (\Throwable $e) {
			$item->setState(MigrationResourceItem::STATE_FAILED);
			$item->setAttempts($item->getAttempts() + 1);
			$item->setLastError($e->getMessage());
			$item->setUpdatedAt(time());
			$this->resourceItemMapper->update($item);

			$this->eventLogger->log($run->getId(), 'calendar_sync_failed', "Calendar sync failed for '{$userMap->getSourceUserId()}' calendar '{$calendarUri}' object '{$objectUri}': {$e->getMessage()}", 'error');
		}
	}
}
