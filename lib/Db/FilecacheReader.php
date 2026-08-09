<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads discovery-relevant rows directly from Nextcloud's own filecache
 * (`oc_storages`/`oc_filecache`/`oc_mimetypes`) instead of walking a user's
 * tree via the Files API (`IRootFolder`/`Folder::getDirectoryListing()`).
 * Used by DiscoveryService, which owns the actual discovery semantics (what
 * counts as "new"/"changed", how migrate_files rows get built) - this class
 * only knows how to efficiently enumerate candidate rows.
 *
 * Reaches into Nextcloud-core-owned tables rather than the public OCP Files
 * API. Trade-off accepted deliberately for:
 *  - Performance: a single indexed SQL scan over `oc_filecache`, ordered by
 *    path, is far cheaper at 100k-file scale than instantiating a Node per
 *    entry via the Files API (each of which does its own permission/mount
 *    checks and possibly further queries).
 *  - Ordering "for free": `ORDER BY path ASC` on the underlying VARCHAR
 *    column always yields parents before their own children (a shorter
 *    string that is a strict prefix of a longer one always sorts first),
 *    with no explicit tree-walk bookkeeping needed to guarantee it.
 *  - Automatic exclusion of currently-encrypted files (`encrypted != 0`,
 *    the same filecache column this app's earlier server-side-encryption
 *    incident-response work identified - see README "Leftover server-side
 *    encryption") directly at the SQL level, rather than only detecting
 *    raw ciphertext content at transfer time.
 *  - Efficient incremental re-scans for continuous sync: a `since` mtime
 *    filter plus a `minFileId` filter (see walk()) let a re-scan return
 *    only rows that plausibly changed, instead of re-examining the whole
 *    tree every pass.
 *
 * Like the app's other `Db\*Mapper` classes, this is not unit tested
 * directly (DiscoveryServiceTest mocks it instead) - real SQL/DB behavior
 * is covered by the e2e integration test.
 */
class FilecacheReader {
	// How many filecache rows to fetch per page during walk() - keyset
	// pagination on `path` (not OFFSET, which gets progressively more
	// expensive as the offset grows) keeps this cheap even for very large
	// trees.
	private const PAGE_SIZE = 500;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Resolves a local user's home storage's numeric id (`oc_storages.id`
	 * is the string `"home::<uid>"`), or null if no such storage row exists
	 * (e.g. the user has never logged in / has no home storage yet).
	 */
	public function resolveHomeStorageId(string $userId): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('numeric_id')
			->from('storages')
			->where($qb->expr()->eq('id', $qb->createNamedParameter('home::' . $userId)));

		$result = $qb->executeQuery();
		$numericId = $result->fetchOne();
		$result->closeCursor();

		return $numericId !== false ? (int)$numericId : null;
	}

	/**
	 * How many files under this storage's "files/" subtree are currently
	 * flagged encrypted (see walk(), which excludes them) - used by
	 * DiscoveryService to log a visible, countable notice rather than
	 * silently omitting them with no trace anywhere an admin would see.
	 */
	public function countEncrypted(int $storageId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from('filecache')
			->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->neq('encrypted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($this->underFilesSubtree($qb));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Enumerates every non-encrypted file/folder under a home storage's
	 * "files/" subtree, ordered by path (parents before children - see
	 * class docblock), excluding the "files" root row itself.
	 *
	 * $sinceMtime/$minFileId narrow this to likely-changed candidates for
	 * an efficient re-scan (continuous sync - see
	 * DiscoveryService::discoverIncremental()):
	 *  - $sinceMtime matches rows whose content mtime is newer than the
	 *    last scan, catching genuinely edited/rewritten files.
	 *  - $minFileId matches rows whose fileid (assigned once, in a strictly
	 *    increasing global sequence, when a filecache row is first created)
	 *    is higher than any previously seen for this run/user - catching
	 *    brand new files even if their mtime was preserved from an old
	 *    source (e.g. copied in from a backup) and so isn't actually
	 *    "recent" by clock time.
	 * Passing both applies them as an OR (a row matching *either* signal is
	 * a candidate); passing neither returns the full subtree (used for the
	 * initial, one-time discovery pass).
	 *
	 * @return \Generator<array{path:string,fileid:int,size:int,mtime:int,mimetype:string,isDirectory:bool}>
	 */
	public function walk(int $storageId, ?int $sinceMtime = null, ?int $minFileId = null): \Generator {
		$lastPath = '';

		while (true) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('f.fileid', 'f.path', 'f.size', 'f.mtime', 'm.mimetype')
				->from('filecache', 'f')
				->innerJoin('f', 'mimetypes', 'm', $qb->expr()->eq('f.mimetype', 'm.id'))
				->where($qb->expr()->eq('f.storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('f.encrypted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
				->andWhere($this->underFilesSubtree($qb, 'f'))
				->andWhere($qb->expr()->gt('f.path', $qb->createNamedParameter($lastPath)))
				->orderBy('f.path', 'ASC')
				->setMaxResults(self::PAGE_SIZE);

			if ($sinceMtime !== null && $minFileId !== null) {
				$qb->andWhere($qb->expr()->orX(
					$qb->expr()->gt('f.mtime', $qb->createNamedParameter($sinceMtime, IQueryBuilder::PARAM_INT)),
					$qb->expr()->gt('f.fileid', $qb->createNamedParameter($minFileId, IQueryBuilder::PARAM_INT)),
				));
			}

			$result = $qb->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();

			if ($rows === []) {
				return;
			}

			foreach ($rows as $row) {
				$path = (string)$row['path'];
				if ($path === 'files') {
					continue;
				}

				yield [
					'path' => substr($path, strlen('files/')),
					'fileid' => (int)$row['fileid'],
					'size' => (int)$row['size'],
					'mtime' => (int)$row['mtime'],
					'mimetype' => (string)$row['mimetype'],
					'isDirectory' => $row['mimetype'] === 'httpd/unix-directory',
				];
			}

			$lastPath = (string)end($rows)['path'];
		}
	}

	/**
	 * Restricts a filecache query to the "files/<anything>" subtree plus
	 * the "files" root row itself - a home storage's filecache also holds
	 * unrelated top-level entries (files_trashbin, files_versions, cache,
	 * uploads, ...) that must never be treated as migratable content.
	 */
	private function underFilesSubtree(IQueryBuilder $qb, string $alias = 'f'): string {
		return $qb->expr()->orX(
			$qb->expr()->eq("{$alias}.path", $qb->createNamedParameter('files')),
			$qb->expr()->like("{$alias}.path", $qb->createNamedParameter('files/%')),
		);
	}
}
