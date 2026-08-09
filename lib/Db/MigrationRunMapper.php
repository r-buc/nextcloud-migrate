<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<MigrationRun>
 */
class MigrationRunMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'migrate_runs', MigrationRun::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws Exception
	 */
	public function find(int $id): MigrationRun {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity($qb);
	}

	/**
	 * @return MigrationRun[]
	 * @throws Exception
	 */
	public function findAllForOwner(string $ownerUserId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('created_by', $qb->createNamedParameter($ownerUserId)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * @return MigrationRun[]
	 * @throws Exception
	 */
	public function findActive(): array {
		$active = [
			MigrationRun::STATE_VALIDATING,
			MigrationRun::STATE_DISCOVERING,
			MigrationRun::STATE_TRANSFERRING,
			MigrationRun::STATE_VERIFYING,
			MigrationRun::STATE_FINALIZING,
		];

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('state', $qb->createNamedParameter($active, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));

		return $this->findEntities($qb);
	}

	/**
	 * Runs currently in continuous-sync steady state (see
	 * MigrationRun::STATE_SYNCING) - scanned by SyncDiscoveryJob on every
	 * tick to re-discover new/changed files for each.
	 *
	 * @return MigrationRun[]
	 * @throws Exception
	 */
	public function findSyncing(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter(MigrationRun::STATE_SYNCING)));

		return $this->findEntities($qb);
	}
}
