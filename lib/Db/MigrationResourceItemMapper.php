<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<MigrationResourceItem>
 */
class MigrationResourceItemMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'migrate_resource_items', MigrationResourceItem::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws Exception
	 */
	public function find(int $id): MigrationResourceItem {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity($qb);
	}

	/**
	 * @throws Exception
	 */
	public function findOne(int $runId, int $userMapId, string $resourceType, string $externalId): ?MigrationResourceItem {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->eq('user_map_id', $qb->createNamedParameter($userMapId)))
			->andWhere($qb->expr()->eq('resource_type', $qb->createNamedParameter($resourceType)))
			->andWhere($qb->expr()->eq('external_id', $qb->createNamedParameter($externalId)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return MigrationResourceItem[]
	 * @throws Exception
	 */
	public function findByRun(int $runId, string $resourceType, int $limit = 500, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->eq('resource_type', $qb->createNamedParameter($resourceType)))
			->setMaxResults($limit)
			->setFirstResult($offset)
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @return array<string,int> counts keyed by state
	 * @throws Exception
	 */
	public function countByState(int $runId, string $resourceType): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('state')
			->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->eq('resource_type', $qb->createNamedParameter($resourceType)))
			->groupBy('state');

		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$counts[$row['state']] = (int)$row['cnt'];
		}
		$result->closeCursor();

		return $counts;
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
