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
 * Migrates a mapped user's own address books (and the vCards within them)
 * to the target instance. Source data is read in-process via
 * SourceCardDavReader (see its docblock for why); target writes go over
 * CardDAV via WebDavClient, authenticated as the mapped user's own app
 * password (from UserMap), same as file transfer - there is no admin
 * bypass for CardDAV either.
 *
 * Each vCard becomes one MigrationResourceItem (external_id =
 * "{addressBookUri}/{cardUri}"). A dedicated marker row
 * (external_id = self::MARKER_EXTERNAL_ID) records that discovery has run
 * for a user, since a user can legitimately own zero address books/cards -
 * see isRunComplete().
 */
class ContactsMigrationService implements ResourceMigratorInterface {
	public const TYPE = 'contact';
	// Public so StatusController can exclude this internal bookkeeping
	// row (see countByState()'s $excludeExternalId) from reported totals.
	public const MARKER_EXTERNAL_ID = '__discovered__';

	public function __construct(
		private SourceCardDavReader $sourceReader,
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
		foreach ($this->sourceReader->listOwnedAddressBooks($userMap->getSourceUserId()) as $book) {
			foreach ($this->sourceReader->listCards($book['id']) as $card) {
				$externalId = $book['uri'] . '/' . $card['uri'];
				if ($this->resourceItemMapper->findOne($run->getId(), $userMap->getId(), self::TYPE, $externalId) !== null) {
					// Already discovered in a previous (interrupted) pass.
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
					'addressBookUri' => $book['uri'],
					'addressBookDisplayName' => $book['displayName'],
					'cardUri' => $card['uri'],
					'cardData' => $card['cardData'],
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
		$addressBookUri = (string)($payload['addressBookUri'] ?? '');
		$cardUri = (string)($payload['cardUri'] ?? '');
		$cardData = (string)($payload['cardData'] ?? '');
		$bookPath = "addressbooks/users/{$targetUserId}/{$addressBookUri}";
		$cardPath = "{$bookPath}/{$cardUri}";

		try {
			$appPassword = $this->credentialService->decrypt($userMap->getTargetAppPasswordEncrypted());

			$this->webDavClient->makeAddressBook($instance, $targetUserId, $appPassword, $bookPath, (string)($payload['addressBookDisplayName'] ?? $addressBookUri));
			$this->webDavClient->putRaw($instance, $targetUserId, $appPassword, $cardPath, $cardData, 'text/vcard; charset=utf-8');

			$actual = $this->webDavClient->getRaw($instance, $targetUserId, $appPassword, $cardPath);
			$verified = trim($actual) === trim($cardData);

			$item->setState($verified ? MigrationResourceItem::STATE_SYNCED : MigrationResourceItem::STATE_FAILED);
			$item->setTargetRef($cardPath);
			$item->setLastError($verified ? null : 'Post-sync verification found mismatched vCard content on the target');
			$item->setAttempts($item->getAttempts() + 1);
			$item->setUpdatedAt(time());
			$this->resourceItemMapper->update($item);

			$this->eventLogger->log(
				$run->getId(),
				$verified ? 'contact_synced' : 'contact_verify_failed',
				"Contact '{$cardUri}' in addressbook '{$addressBookUri}' for '{$userMap->getSourceUserId()}' -> '{$targetUserId}'" . ($verified ? ' synced' : ' synced but verification found mismatches'),
				$verified ? 'info' : 'warning',
			);
		} catch (\Throwable $e) {
			$item->setState(MigrationResourceItem::STATE_FAILED);
			$item->setAttempts($item->getAttempts() + 1);
			$item->setLastError($e->getMessage());
			$item->setUpdatedAt(time());
			$this->resourceItemMapper->update($item);

			$this->eventLogger->log($run->getId(), 'contact_sync_failed', "Contact sync failed for '{$userMap->getSourceUserId()}' addressbook '{$addressBookUri}' card '{$cardUri}': {$e->getMessage()}", 'error');
		}
	}
}
