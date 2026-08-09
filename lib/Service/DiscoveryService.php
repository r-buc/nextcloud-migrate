<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service;

use OCA\NextcloudMigrate\Db\MigrationFile;
use OCA\NextcloudMigrate\Db\MigrationFileMapper;
use OCA\NextcloudMigrate\Db\UserMap;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Walks a source user's file tree using the local Files API (not WebDAV,
 * since this app runs on the SOURCE instance) and snapshots it into
 * migrate_files rows for a run.
 */
class DiscoveryService {
	// Flush discovered rows to the DB in batches to bound memory usage on
	// large trees (target scale: up to ~100k files/run).
	private const BATCH_SIZE = 500;

	public function __construct(
		private IRootFolder $rootFolder,
		private MigrationFileMapper $fileMapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Recursively discovers every file/folder under the given source user's
	 * "files" root and persists a snapshot row per node.
	 *
	 * @return array{files:int,folders:int,bytes:int}
	 */
	public function discoverUser(int $runId, UserMap $userMap, string $sourceUserId): array {
		$userFolder = $this->rootFolder->getUserFolder($sourceUserId);

		$stats = ['files' => 0, 'folders' => 0, 'bytes' => 0];
		$batch = [];
		$now = time();

		foreach ($this->walk($userFolder) as [$relativePath, $node, $isDirectory]) {
			$entity = new MigrationFile();
			$entity->setRunId($runId);
			$entity->setUserMapId($userMap->getId());
			$entity->setSourcePath($relativePath);
			$entity->setSourcePathHash(hash('sha256', $relativePath));
			$entity->setSourceFileid($node->getId());
			$entity->setIsDirectory($isDirectory);
			$entity->setSize($isDirectory ? 0 : $node->getSize());
			$entity->setMtime($node->getMTime());
			$entity->setMimetype($isDirectory ? 'httpd/unix-directory' : $node->getMimetype());
			$entity->setState(MigrationFile::STATE_DISCOVERED);
			$entity->setTransferAttempts(0);
			$entity->setVerifyAttempts(0);
			$entity->setBytesTransferred(0);
			$entity->setCreatedAt($now);
			$entity->setUpdatedAt($now);

			$batch[] = $entity;

			if ($isDirectory) {
				$stats['folders']++;
			} else {
				$stats['files']++;
				$stats['bytes'] += $node->getSize();
			}

			if (count($batch) >= self::BATCH_SIZE) {
				$this->flush($batch);
				$batch = [];
			}
		}

		$this->flush($batch);

		$this->logger->info('Discovery completed for user', [
			'app' => 'nextcloud_migrate',
			'runId' => $runId,
			'sourceUserId' => $sourceUserId,
			'stats' => $stats,
		]);

		return $stats;
	}

	/**
	 * Re-walks a source user's tree for a run already fully discovered
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
	 *    disappeared path simply isn't visited by this walk and its row is
	 *    left exactly as-is. (Not supported yet; see README.)
	 * Folders are only ever inserted when new (MKCOL is idempotent and
	 * folders carry no content to "change"), never reset.
	 *
	 * @return array{new:int,changed:int}
	 */
	public function discoverIncremental(int $runId, UserMap $userMap, string $sourceUserId): array {
		$userFolder = $this->rootFolder->getUserFolder($sourceUserId);

		$counts = ['new' => 0, 'changed' => 0];
		$batch = [];
		$now = time();
		$resyncableStates = [MigrationFile::STATE_VERIFIED, MigrationFile::STATE_COMPLETED];

		foreach ($this->walk($userFolder) as [$relativePath, $node, $isDirectory]) {
			$pathHash = hash('sha256', $relativePath);
			$existing = $this->fileMapper->findByRunAndPathHash($runId, $userMap->getId(), $pathHash);

			if ($existing === null) {
				$entity = new MigrationFile();
				$entity->setRunId($runId);
				$entity->setUserMapId($userMap->getId());
				$entity->setSourcePath($relativePath);
				$entity->setSourcePathHash($pathHash);
				$entity->setSourceFileid($node->getId());
				$entity->setIsDirectory($isDirectory);
				$entity->setSize($isDirectory ? 0 : $node->getSize());
				$entity->setMtime($node->getMTime());
				$entity->setMimetype($isDirectory ? 'httpd/unix-directory' : $node->getMimetype());
				$entity->setState(MigrationFile::STATE_DISCOVERED);
				$entity->setTransferAttempts(0);
				$entity->setVerifyAttempts(0);
				$entity->setBytesTransferred(0);
				$entity->setCreatedAt($now);
				$entity->setUpdatedAt($now);

				$batch[] = $entity;
				$counts['new']++;

				if (count($batch) >= self::BATCH_SIZE) {
					$this->flush($batch);
					$batch = [];
				}
				continue;
			}

			if ($isDirectory || !in_array($existing->getState(), $resyncableStates, true)) {
				continue;
			}

			$sizeChanged = $existing->getSize() !== $node->getSize();
			$mtimeChanged = $existing->getMtime() !== $node->getMTime();
			if (!$sizeChanged && !$mtimeChanged) {
				continue;
			}

			$existing->setSize($node->getSize());
			$existing->setMtime($node->getMTime());
			$existing->setSourceFileid($node->getId());
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
	 * Iterative DFS (explicit stack, not recursion, to avoid PHP call-stack
	 * limits on very deep trees) over every file/folder under $userFolder,
	 * excluding the root folder itself.
	 *
	 * @return \Generator<array{0:string,1:Node,2:bool}>
	 */
	private function walk(Folder $userFolder): \Generator {
		$stack = [$userFolder];

		while ($stack !== []) {
			/** @var Node $node */
			$node = array_pop($stack);

			if ($node->getPath() === $userFolder->getPath()) {
				if ($node instanceof Folder) {
					foreach ($node->getDirectoryListing() as $child) {
						$stack[] = $child;
					}
				}
				continue;
			}

			$isDirectory = $node instanceof Folder;
			yield [$this->relativePath($userFolder, $node), $node, $isDirectory];

			if ($isDirectory) {
				foreach ($node->getDirectoryListing() as $child) {
					$stack[] = $child;
				}
			}
		}
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

	private function relativePath(Folder $userFolder, Node $node): string {
		$rootPath = rtrim($userFolder->getPath(), '/');
		$nodePath = $node->getPath();

		$relative = substr($nodePath, strlen($rootPath));

		return ltrim($relative, '/');
	}
}
