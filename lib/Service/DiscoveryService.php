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

		// Iterative DFS (explicit stack) to avoid PHP call-stack recursion
		// limits on very deep trees.
		$stack = [$userFolder];

		while ($stack !== []) {
			/** @var Node $node */
			$node = array_pop($stack);

			if ($node->getPath() === $userFolder->getPath()) {
				// Don't create a row for the root folder itself.
				if ($node instanceof Folder) {
					foreach ($node->getDirectoryListing() as $child) {
						$stack[] = $child;
					}
				}
				continue;
			}

			$relativePath = $this->relativePath($userFolder, $node);
			$isDirectory = $node instanceof Folder;

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
				foreach ($node->getDirectoryListing() as $child) {
					$stack[] = $child;
				}
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
