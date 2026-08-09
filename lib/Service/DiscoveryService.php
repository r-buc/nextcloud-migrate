<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service;

use OCA\NextcloudMigrate\Db\FilecacheReader;
use OCA\NextcloudMigrate\Db\MigrationFile;
use OCA\NextcloudMigrate\Db\MigrationFileMapper;
use OCA\NextcloudMigrate\Db\UserMap;
use Psr\Log\LoggerInterface;

/**
 * Snapshots a source user's files into migrate_files rows for a run by
 * reading directly from Nextcloud's own filecache (see FilecacheReader)
 * rather than walking the tree via the Files API. See FilecacheReader's
 * class docblock for why: performance at scale, ordering "for free" so
 * parent folders are always created before their children, and automatic
 * exclusion of currently-encrypted files.
 */
class DiscoveryService {
	// Flush newly-discovered rows to the DB in batches to bound memory
	// usage on large trees (target scale: up to ~100k files/run).
	private const BATCH_SIZE = 500;

	public function __construct(
		private FilecacheReader $filecacheReader,
		private MigrationFileMapper $fileMapper,
		private EventLogger $eventLogger,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Discovers every file/folder under the given source user's "files"
	 * root and persists a snapshot row per node.
	 *
	 * @throws \RuntimeException if the user has no home storage
	 * @return array{files:int,folders:int,bytes:int}
	 */
	public function discoverUser(int $runId, UserMap $userMap, string $sourceUserId): array {
		$storageId = $this->requireHomeStorageId($sourceUserId);

		$stats = ['files' => 0, 'folders' => 0, 'bytes' => 0];
		$batch = [];
		$now = time();

		foreach ($this->filecacheReader->walk($storageId) as $row) {
			$batch[] = $this->buildEntity($runId, $userMap->getId(), $row, $now);

			if ($row['isDirectory']) {
				$stats['folders']++;
			} else {
				$stats['files']++;
				$stats['bytes'] += $row['size'];
			}

			if (count($batch) >= self::BATCH_SIZE) {
				$this->flush($batch);
				$batch = [];
			}
		}

		$this->flush($batch);
		$this->logExcludedEncrypted($runId, $storageId, $sourceUserId);

		$this->logger->info('Discovery completed for user', [
			'app' => 'nextcloud_migrate',
			'runId' => $runId,
			'sourceUserId' => $sourceUserId,
			'stats' => $stats,
		]);

		return $stats;
	}

	/**
	 * Re-scans a source user's tree for a run already fully discovered
	 * (and, typically, already transferred/verified once) - used by
	 * continuous sync (MigrationRun::STATE_SYNCING, see
	 * RunOrchestrator::runSyncPass()) to pick up files that appeared or
	 * changed since the last pass:
	 *  - A path with no existing row for this run+user is a brand new
	 *    file/folder: inserted exactly like discoverUser() would, so it
	 *    goes through the normal mapping/transfer/verification pipeline
	 *    (including the run's own collision strategy, in case something
	 *    unrelated already occupies that path on the target).
	 *  - An existing row already at VERIFIED/COMPLETED whose mtime or size
	 *    no longer matches what's on disk is reset back to DISCOVERED with
	 *    per-cycle fields cleared and the fresh size/mtime recorded, so it
	 *    re-enters the pipeline and gets re-transferred. Deliberately only
	 *    resets rows sitting at VERIFIED/COMPLETED - anything still mid-
	 *    pipeline or already in a failure state is left alone (a failure
	 *    is surfaced via the normal failed-files list/manual retry, not
	 *    silently reset by a background scan).
	 *  - Deletions on the source are NOT propagated to the target - a
	 *    disappeared path simply isn't returned by FilecacheReader::walk()
	 *    and its row is left exactly as-is. (Not supported yet; see README.)
	 * Folders are only ever inserted when new (MKCOL is idempotent and
	 * folders carry no content to "change"), never reset.
	 *
	 * $since narrows the underlying filecache scan to likely-changed rows
	 * (see FilecacheReader::walk()) instead of re-examining every file on
	 * every pass - pass null (or omit) to force a full re-scan.
	 *
	 * @throws \RuntimeException if the user has no home storage
	 * @return array{new:int,changed:int}
	 */
	public function discoverIncremental(int $runId, UserMap $userMap, string $sourceUserId, ?int $since = null): array {
		$storageId = $this->requireHomeStorageId($sourceUserId);
		$minFileId = $since !== null ? $this->fileMapper->maxSourceFileId($runId, $userMap->getId()) : null;

		$counts = ['new' => 0, 'changed' => 0];
		$batch = [];
		$now = time();
		$resyncableStates = [MigrationFile::STATE_VERIFIED, MigrationFile::STATE_COMPLETED];

		foreach ($this->filecacheReader->walk($storageId, $since, $minFileId) as $row) {
			$pathHash = hash('sha256', $row['path']);
			$existing = $this->fileMapper->findByRunAndPathHash($runId, $userMap->getId(), $pathHash);

			if ($existing === null) {
				$batch[] = $this->buildEntity($runId, $userMap->getId(), $row, $now);
				$counts['new']++;

				if (count($batch) >= self::BATCH_SIZE) {
					$this->flush($batch);
					$batch = [];
				}
				continue;
			}

			if ($row['isDirectory'] || !in_array($existing->getState(), $resyncableStates, true)) {
				continue;
			}

			if ($existing->getSize() === $row['size'] && $existing->getMtime() === $row['mtime']) {
				continue;
			}

			$existing->setSize($row['size']);
			$existing->setMtime($row['mtime']);
			$existing->setSourceFileid($row['fileid']);
			$existing->setState(MigrationFile::STATE_DISCOVERED);
			$existing->setSourceChecksum(null);
			$existing->setTargetChecksum(null);
			$existing->setBytesTransferred(0);
			$existing->setTransferAttempts(0);
			$existing->setVerifyAttempts(0);
			$existing->setLastError(null);
			$existing->setTransferId(null);
			$existing->setNextChunkIndex(0);
			$existing->setLockOwner(null);
			$existing->setLockExpiresAt(null);
			$existing->setNextRetryAt(null);
			$existing->setTransferredAt(null);
			$existing->setVerifiedAt(null);
			$existing->setUpdatedAt($now);
			$this->fileMapper->update($existing);
			$counts['changed']++;
		}

		$this->flush($batch);

		if ($counts['new'] > 0 || $counts['changed'] > 0) {
			$this->logger->info('Incremental sync discovery found changes', [
				'app' => 'nextcloud_migrate',
				'runId' => $runId,
				'sourceUserId' => $sourceUserId,
				'counts' => $counts,
			]);
		}

		return $counts;
	}

	/**
	 * @throws \RuntimeException
	 */
	private function requireHomeStorageId(string $sourceUserId): int {
		$storageId = $this->filecacheReader->resolveHomeStorageId($sourceUserId);
		if ($storageId === null) {
			throw new \RuntimeException("No home storage found for user '{$sourceUserId}'");
		}

		return $storageId;
	}

	/**
	 * @param array{path:string,fileid:int,size:int,mtime:int,mimetype:string,isDirectory:bool} $row
	 */
	private function buildEntity(int $runId, int $userMapId, array $row, int $now): MigrationFile {
		$entity = new MigrationFile();
		$entity->setRunId($runId);
		$entity->setUserMapId($userMapId);
		$entity->setSourcePath($row['path']);
		$entity->setSourcePathHash(hash('sha256', $row['path']));
		$entity->setSourceFileid($row['fileid']);
		$entity->setIsDirectory($row['isDirectory']);
		$entity->setSize($row['isDirectory'] ? 0 : $row['size']);
		$entity->setMtime($row['mtime']);
		$entity->setMimetype($row['isDirectory'] ? 'httpd/unix-directory' : $row['mimetype']);
		$entity->setState(MigrationFile::STATE_DISCOVERED);
		$entity->setTransferAttempts(0);
		$entity->setVerifyAttempts(0);
		$entity->setBytesTransferred(0);
		$entity->setCreatedAt($now);
		$entity->setUpdatedAt($now);

		return $entity;
	}

	/**
	 * Files excluded from discovery entirely because they're still
	 * server-side encrypted (see FilecacheReader::walk()) are otherwise
	 * invisible anywhere an admin would see them - unlike a transfer
	 * failure, there's no failed-file row at all to surface via the normal
	 * failed-files list. Logging a run-level event here at least makes
	 * their existence and count visible in the audit trail, rather than a
	 * silent gap between "files on disk" and "files discovered".
	 */
	private function logExcludedEncrypted(int $runId, int $storageId, string $sourceUserId): void {
		$excluded = $this->filecacheReader->countEncrypted($storageId);
		if ($excluded === 0) {
			return;
		}

		$this->eventLogger->log(
			$runId,
			'source_files_excluded_encrypted',
			"{$excluded} file(s) for user '{$sourceUserId}' are still server-side encrypted on the source and were excluded from this migration. Re-enable the encryption app and run 'occ encryption:decrypt-all' on the source instance to decrypt them, then retry discovery.",
			'warning',
		);
	}

	/**
	 * @param MigrationFile[] $batch
	 */
	private function flush(array $batch): void {
		if ($batch === []) {
			return;
		}
		$this->fileMapper->insertBatch($batch);
	}
}
