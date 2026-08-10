<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service;

use OC\Files\Search\SearchComparison;
use OC\Files\Search\SearchOrder;
use OC\Files\Search\SearchQuery;
use OCA\NextcloudMigrate\Db\MigrationFile;
use OCA\NextcloudMigrate\Db\MigrationFileMapper;
use OCA\NextcloudMigrate\Db\UserMap;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOrder;
use Psr\Log\LoggerInterface;

/**
 * Snapshots a source user's files into migrate_files rows for a run using
 * Nextcloud's own Files-API search (`OCP\Files\Folder::search()`) - a
 * single paginated, path-ordered query per user, instead of a recursive
 * `getDirectoryListing()` tree walk:
 *  - Far cheaper at 100k-file scale than instantiating a Node per entry via
 *    a recursive walk (each of which does its own permission/mount checks
 *    and possibly further queries).
 *  - `ORDER BY path` gives parent-before-child ordering "for free" (a
 *    shorter string that is a strict prefix of a longer one always sorts
 *    first) - no explicit tree-walk bookkeeping needed to guarantee it.
 *  - Scoped to the given Folder automatically (internally jailed to that
 *    subtree), so trashbin/versions/cache and similar unrelated top-level
 *    content is never returned.
 * The concrete `OC\Files\Search\*` value classes used to build the query
 * have no OCP-namespaced equivalent, but this is the documented, standard
 * way apps construct a Files API search query (there is no public factory).
 *
 * No attempt is made here to detect or exclude server-side-encrypted files:
 * a file that can't actually be decrypted for whatever reason simply fails
 * to transfer like any other unreadable file (TransferService's existing
 * generic error handling already logs that as a normal failed file), rather
 * than needing bespoke pre-detection.
 */
class DiscoveryService {
	// Page size for Folder::search() (LIMIT/OFFSET - the search API has no
	// keyset-pagination support; 'path' only supports eq/like comparisons,
	// not gt/gte) and for batching migrate_files inserts.
	private const BATCH_SIZE = 500;

	public function __construct(
		private IRootFolder $rootFolder,
		private MigrationFileMapper $fileMapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Discovers every file/folder under the given source user's "files"
	 * root and persists a snapshot row per node.
	 *
	 * @return array{files:int,folders:int,bytes:int}
	 */
	public function discoverUser(int $runId, UserMap $userMap, string $sourceUserId): array {
		$userFolder = $this->rootFolder->getUserFolder($sourceUserId);

		$stats = ['files' => 0, 'folders' => 0, 'bytes' => 0];
		$batch = [];
		$now = time();

		foreach ($this->walk($userFolder) as $node) {
			$isDirectory = $node instanceof Folder;
			$batch[] = $this->buildEntity($runId, $userMap->getId(), $userFolder, $node, $isDirectory, $now);

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
	 *    disappeared path simply isn't returned by the search and its row
	 *    is left exactly as-is. (Not supported yet; see README.)
	 * Folders are only ever inserted when new (MKCOL is idempotent and
	 * folders carry no content to "change"), never reset.
	 *
	 * $since narrows the underlying search to files/folders whose mtime is
	 * at/after that timestamp - an efficiency optimization, not a
	 * guarantee: a genuinely new file whose mtime was deliberately
	 * preserved from elsewhere (e.g. copied in from an old backup) could
	 * have an older mtime and be missed until the next full scan (pass
	 * null to force one). Accepted trade-off for using the officially
	 * supported search API, which offers no reliable "definitely new/
	 * changed since X" signal (unlike raw SQL against filecache's own
	 * auto-incrementing fileid, which the search field whitelist - see
	 * `\OC\Files\Cache\SearchBuilder::validateComparison()` - only exposes
	 * for `eq`/`in` comparisons, not `gt`).
	 *
	 * @return array{new:int,changed:int}
	 */
	public function discoverIncremental(int $runId, UserMap $userMap, string $sourceUserId, ?int $since = null): array {
		$userFolder = $this->rootFolder->getUserFolder($sourceUserId);

		$counts = ['new' => 0, 'changed' => 0];
		$batch = [];
		$now = time();
		$resyncableStates = [MigrationFile::STATE_VERIFIED, MigrationFile::STATE_COMPLETED];

		foreach ($this->walk($userFolder, $since) as $node) {
			$isDirectory = $node instanceof Folder;
			$relativePath = $this->relativePath($userFolder, $node);
			$pathHash = hash('sha256', $relativePath);
			$existing = $this->fileMapper->findByRunAndPathHash($runId, $userMap->getId(), $pathHash);

			if ($existing === null) {
				$batch[] = $this->buildEntity($runId, $userMap->getId(), $userFolder, $node, $isDirectory, $now);
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

			if ($existing->getSize() === $node->getSize() && $existing->getMtime() === $node->getMTime()) {
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
	 * Paginated, path-ordered search of everything under $userFolder
	 * (parent-before-child - see class docblock), excluding the root
	 * folder row itself.
	 *
	 * @return \Generator<Node>
	 */
	private function walk(Folder $userFolder, ?int $sinceMtime = null): \Generator {
		// 'mtime' is the only reliably cross-version-supported field for
		// this kind of comparison (see discoverIncremental()'s docblock) -
		// >= 0 is just an always-true condition for a full scan, since a
		// real mtime is never negative.
		$comparison = new SearchComparison(ISearchComparison::COMPARE_GREATER_THAN_EQUAL, 'mtime', $sinceMtime ?? 0);
		$order = [new SearchOrder(ISearchOrder::DIRECTION_ASCENDING, 'path')];

		$offset = 0;
		while (true) {
			$query = new SearchQuery($comparison, self::BATCH_SIZE, $offset, $order);
			$nodes = $userFolder->search($query);

			foreach ($nodes as $node) {
				if ($node->getPath() === $userFolder->getPath()) {
					continue;
				}
				yield $node;
			}

			// `\OC\Files\Node\Folder::search()` applies the SQL-level
			// LIMIT/OFFSET *before* stripping the folder's own row from
			// the results (it always matches the jail's "path = root"
			// clause, and sorts first since a prefix always sorts before
			// anything it's a prefix of). On the very first page only
			// (offset 0), for a full scan (`mtime >= 0`, which the root
			// folder's own mtime always satisfies) that silently eats one
			// of the BATCH_SIZE raw slots, capping this page's real yield
			// at BATCH_SIZE - 1 even when many more real files remain.
			// Without this adjustment, a folder with >= BATCH_SIZE real
			// entries would have every entry past the first ~499 silently
			// dropped. Using a one-lower threshold only for the first
			// page costs at most one extra, cheap empty-page query at the
			// very end when the true count happens to land exactly on
			// this boundary (or when $sinceMtime excludes the root row
			// anyway, e.g. during an incremental sync).
			$shortPageThreshold = $offset === 0 ? self::BATCH_SIZE - 1 : self::BATCH_SIZE;
			if (count($nodes) < $shortPageThreshold) {
				return;
			}
			$offset += self::BATCH_SIZE;
		}
	}

	private function buildEntity(int $runId, int $userMapId, Folder $userFolder, Node $node, bool $isDirectory, int $now): MigrationFile {
		$relativePath = $this->relativePath($userFolder, $node);

		$entity = new MigrationFile();
		$entity->setRunId($runId);
		$entity->setUserMapId($userMapId);
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

		return $entity;
	}

	private function relativePath(Folder $userFolder, Node $node): string {
		$rootPath = rtrim($userFolder->getPath(), '/');
		$nodePath = $node->getPath();

		$relative = substr($nodePath, strlen($rootPath));

		return ltrim($relative, '/');
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

