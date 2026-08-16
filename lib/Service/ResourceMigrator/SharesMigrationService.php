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
use OCA\NextcloudMigrate\Service\RunOrchestrator;
use OCA\NextcloudMigrate\Service\SharingClient;
use OCP\Share\IShare;

/**
 * Migrates a mapped user's own shares (user/group/link - v1 scope, no
 * federated/remote shares) to the target instance. Source data is read
 * via the public OCP\Share\IManager (see SourceShareReader); target
 * writes go through the OCS Share API via SharingClient, authenticated as
 * the mapped user's own app password - there is no admin bypass for
 * creating a share as another user either.
 *
 * Unlike the other resource types, share recreation has a real ordering
 * dependency on file transfer: a share can only be recreated once its
 * underlying file exists on the target. ensureDiscovered() enforces this
 * by simply not discovering (and therefore not creating the per-user
 * marker row) until RunOrchestrator::isUserFilesSettled() is true for
 * that user - the worker job just finds nothing to do yet and re-checks
 * on its next execution.
 *
 * Known limitations (see plan/Further Considerations):
 *  - Link-share passwords can never be migrated (Nextcloud never exposes
 *    a plaintext password, only a hash) - recreated without one.
 *  - Group shares assume a group of the same name already exists on the
 *    target; group membership itself is not translated per-run the way
 *    user recipients are (that would require a full group-mapping
 *    concept, out of scope for v1).
 *  - A share whose user recipient isn't part of this run's mapping is
 *    skipped with a logged warning event, not a hard failure.
 */
class SharesMigrationService implements ResourceMigratorInterface {
	public const TYPE = 'share';
	public const MARKER_EXTERNAL_ID = '__discovered__';

	public function __construct(
		private SourceShareReader $sourceReader,
		private SharingClient $sharingClient,
		private CredentialService $credentialService,
		private MigrationResourceItemMapper $resourceItemMapper,
		private UserMapMapper $userMapMapper,
		private EventLogger $eventLogger,
		private RunOrchestrator $runOrchestrator,
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

		if (!$this->runOrchestrator->isUserFilesSettled($run->getId(), $userMap->getId())) {
			// Not ready yet - this user's files are still transferring/
			// verifying. Re-checked on the worker's next execution.
			return;
		}

		$now = time();
		foreach ($this->sourceReader->listOwnedShares($userMap->getSourceUserId()) as $share) {
			$externalId = $share['id'];
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
			$item->setPayload(json_encode($share));
			$item->setCreatedAt($now);
			$item->setUpdatedAt($now);
			$this->resourceItemMapper->insert($item);
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
		$shareType = (int)($payload['shareType'] ?? -1);
		$path = (string)($payload['path'] ?? '');
		$permissions = (int)($payload['permissions'] ?? 1);
		$sharedWith = $payload['sharedWith'] ?? null;
		$expiration = $payload['expiration'] ?? null;
		$label = $payload['label'] ?? null;

		if ($shareType === IShare::TYPE_USER) {
			$resolved = $this->resolveTargetUser($run->getId(), (string)$sharedWith);
			if ($resolved === null) {
				$item->setState(MigrationResourceItem::STATE_FAILED);
				$item->setAttempts($item->getAttempts() + 1);
				$item->setLastError("Recipient '{$sharedWith}' is not part of this migration's user mapping; share skipped");
				$item->setUpdatedAt(time());
				$this->resourceItemMapper->update($item);

				$this->eventLogger->log($run->getId(), 'share_recipient_unmapped', "Skipped share of '{$path}' to unmapped user '{$sharedWith}'", 'warning');

				return;
			}
			$sharedWith = $resolved;
		}

		try {
			$appPassword = $this->credentialService->decrypt($userMap->getTargetAppPasswordEncrypted());
			$created = $this->sharingClient->createShare($instance, $targetUserId, $appPassword, $path, $shareType, $sharedWith, $permissions, $expiration, $label);

			$item->setState(MigrationResourceItem::STATE_SYNCED);
			$item->setTargetRef((string)($created['id'] ?? ''));
			$item->setLastError(null);
			$item->setAttempts($item->getAttempts() + 1);
			$item->setUpdatedAt(time());
			$this->resourceItemMapper->update($item);

			$this->eventLogger->log($run->getId(), 'share_synced', "Share of '{$path}' recreated for '{$userMap->getSourceUserId()}' -> '{$targetUserId}'", 'info');
		} catch (\Throwable $e) {
			$item->setState(MigrationResourceItem::STATE_FAILED);
			$item->setAttempts($item->getAttempts() + 1);
			$item->setLastError($e->getMessage());
			$item->setUpdatedAt(time());
			$this->resourceItemMapper->update($item);

			$this->eventLogger->log($run->getId(), 'share_sync_failed', "Share sync failed for '{$userMap->getSourceUserId()}' path '{$path}': {$e->getMessage()}", 'error');
		}
	}

	private function resolveTargetUser(int $runId, string $sourceUserId): ?string {
		foreach ($this->userMapMapper->findByRun($runId) as $userMap) {
			if ($userMap->getSourceUserId() === $sourceUserId) {
				return $userMap->getTargetUserId();
			}
		}

		return null;
	}
}
