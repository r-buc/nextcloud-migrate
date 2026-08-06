<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<UserMap>
 */
class UserMapMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'migrate_user_map', UserMap::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws Exception
	 */
	public function find(int $id): UserMap {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity($qb);
	}

	/**
	 * @return UserMap[]
	 * @throws Exception
	 */
	public function findByRun(int $runId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)));

		return $this->findEntities($qb);
	}

	/**
	 * @throws Exception
	 */
	public function deleteByRun(int $runId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)));
		$qb->executeStatement();
	}
}
