<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<MigrationFile>
 */
class MigrationFileMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'migrate_files', MigrationFile::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws Exception
	 */
	public function find(int $id): MigrationFile {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity($qb);
	}

	/**
	 * @throws Exception
	 */
	public function findByRunAndPathHash(int $runId, int $userMapId, string $pathHash): ?MigrationFile {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->eq('user_map_id', $qb->createNamedParameter($userMapId)))
			->andWhere($qb->expr()->eq('source_path_hash', $qb->createNamedParameter($pathHash)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return MigrationFile[]
	 * @throws Exception
	 */
	public function findByRun(int $runId, ?string $state = null, int $limit = 500, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->setMaxResults($limit)
			->setFirstResult($offset)
			->orderBy('id', 'ASC');

		if ($state !== null) {
			$qb->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($state)));
		}

		return $this->findEntities($qb);
	}

	/**
	 * Find files eligible for a transfer worker to pick up: not currently
	 * locked (or lock expired) and, if previously failed, past their retry
	 * backoff deadline.
	 *
	 * DISCOVERED rows are included because path mapping/collision-detection
	 * runs inline at the start of the worker's transfer step rather than as
	 * a separate bulk pre-pass (see MappingService + TransferWorkerJob).
	 *
	 * @return MigrationFile[]
	 * @throws Exception
	 */
	public function findTransferable(int $runId, int $now, int $limit, ?int $userMapId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->in('state', $qb->createNamedParameter(
				[MigrationFile::STATE_DISCOVERED, MigrationFile::STATE_TRANSFER_FAILED],
				IQueryBuilder::PARAM_STR_ARRAY
			)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('lock_expires_at'),
				$qb->expr()->lt('lock_expires_at', $qb->createNamedParameter($now))
			))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('next_retry_at'),
				$qb->expr()->lte('next_retry_at', $qb->createNamedParameter($now))
			))
			->andWhere($qb->expr()->lt('transfer_attempts', $qb->createNamedParameter(MigrationFile::MAX_TRANSFER_ATTEMPTS)));
		if ($userMapId !== null) {
			$qb->andWhere($qb->expr()->eq('user_map_id', $qb->createNamedParameter($userMapId)));
		}
		$qb->setMaxResults($limit)
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @return MigrationFile[]
	 * @throws Exception
	 */
	public function findVerifiable(int $runId, int $now, int $limit, ?int $userMapId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->in('state', $qb->createNamedParameter(
				[MigrationFile::STATE_TRANSFERRED, MigrationFile::STATE_VERIFICATION_FAILED],
				IQueryBuilder::PARAM_STR_ARRAY
			)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('lock_expires_at'),
				$qb->expr()->lt('lock_expires_at', $qb->createNamedParameter($now))
			))
			->andWhere($qb->expr()->lt('verify_attempts', $qb->createNamedParameter(MigrationFile::MAX_VERIFY_ATTEMPTS)));
		if ($userMapId !== null) {
			$qb->andWhere($qb->expr()->eq('user_map_id', $qb->createNamedParameter($userMapId)));
		}
		$qb->setMaxResults($limit)
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @return array<string,int> counts keyed by state
	 * @throws Exception
	 */
	public function countByState(int $runId, ?int $userMapId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('state')
			->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)));
		if ($userMapId !== null) {
			$qb->andWhere($qb->expr()->eq('user_map_id', $qb->createNamedParameter($userMapId)));
		}
		$qb->groupBy('state');

		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$counts[$row['state']] = (int)$row['cnt'];
		}
		$result->closeCursor();

		return $counts;
	}

	/**
	 * Sums the size of every non-directory row for a run, regardless of
	 * state. Used to populate migration_runs.total_bytes once discovery
	 * completes.
	 *
	 * @throws Exception
	 */
	public function sumDiscoveredBytes(int $runId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('SUM(size)'), 'total')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->eq('is_directory', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));

		$result = $qb->executeQuery();
		$total = $result->fetchOne();
		$result->closeCursor();

		return $total !== false ? (int)$total : 0;
	}

	/**
	 * Atomically claim a file for processing by a worker, guarding against
	 * concurrent workers picking up the same row. Also flips the row into
	 * the given "in progress" state so it becomes invisible to
	 * findTransferable()/findVerifiable() until the worker finishes (or the
	 * lock expires and CleanupLocksJob reclaims it).
	 *
	 * @throws Exception
	 */
	public function tryAcquireLock(int $fileId, string $ownerToken, int $lockTtlSeconds, string $inProgressState): bool {
		$now = time();
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('lock_owner', $qb->createNamedParameter($ownerToken))
			->set('lock_expires_at', $qb->createNamedParameter($now + $lockTtlSeconds))
			->set('state', $qb->createNamedParameter($inProgressState))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($fileId)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('lock_expires_at'),
				$qb->expr()->lt('lock_expires_at', $qb->createNamedParameter($now))
			));

		return $qb->executeStatement() === 1;
	}

	/**
	 * Reclaims rows left in an "in progress" state by a worker that crashed
	 * or was killed before it could persist a terminal state, so they become
	 * eligible for retry again. Called periodically by CleanupLocksJob.
	 *
	 * @throws Exception
	 */
	public function reclaimStaleLocks(int $now): int {
		$reclaimed = 0;

		$map = [
			MigrationFile::STATE_TRANSFERRING => MigrationFile::STATE_TRANSFER_FAILED,
			MigrationFile::STATE_VERIFYING => MigrationFile::STATE_VERIFICATION_FAILED,
		];

		foreach ($map as $staleState => $resetState) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('state', $qb->createNamedParameter($resetState))
				->set('lock_owner', $qb->createNamedParameter(null))
				->set('next_retry_at', $qb->createNamedParameter($now))
				->set('updated_at', $qb->createNamedParameter($now))
				->where($qb->expr()->eq('state', $qb->createNamedParameter($staleState)))
				->andWhere($qb->expr()->lt('lock_expires_at', $qb->createNamedParameter($now)));

			$reclaimed += $qb->executeStatement();
		}

		return $reclaimed;
	}

	/**
	 * Inserts many discovered file rows within a single transaction for
	 * throughput on large trees (target scale: up to ~100k files/run).
	 *
	 * @param MigrationFile[] $files
	 * @throws Exception
	 */
	public function insertBatch(array $files): void {
		if (empty($files)) {
			return;
		}

		$this->db->beginTransaction();
		try {
			foreach ($files as $file) {
				$this->insert($file);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}
