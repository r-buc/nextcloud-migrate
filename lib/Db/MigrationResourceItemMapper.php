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
	public function countByState(int $runId, string $resourceType, ?string $excludeExternalId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('state')
			->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->eq('resource_type', $qb->createNamedParameter($resourceType)))
			->groupBy('state');
		if ($excludeExternalId !== null) {
			$qb->andWhere($qb->expr()->neq('external_id', $qb->createNamedParameter($excludeExternalId)));
		}

		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$counts[$row['state']] = (int)$row['cnt'];
		}
		$result->closeCursor();

		return $counts;
	}

	/**
	 * Per-user variant of countByState(), used by each ResourceMigratorInterface
	 * implementation's isRunComplete() to check whether a specific mapped
	 * user still has pending work (contacts/calendars/shares can
	 * legitimately have zero items for a user, so a plain "no rows" check
	 * can't tell "nothing to do" apart from "not discovered yet" - callers
	 * pair this with a discovery marker row for that).
	 *
	 * @return array<string,int> counts keyed by state
	 * @throws Exception
	 */
	public function countByStateForUser(int $runId, int $userMapId, string $resourceType): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('state')
			->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->eq('user_map_id', $qb->createNamedParameter($userMapId)))
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
	 * Pending (not yet synced/failed) items for a specific mapped user -
	 * used by each resource type's worker job to process one user's
	 * remaining work within a batch execution.
	 *
	 * @return MigrationResourceItem[]
	 * @throws Exception
	 */
	public function findPendingForUser(int $runId, int $userMapId, string $resourceType, int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->eq('user_map_id', $qb->createNamedParameter($userMapId)))
			->andWhere($qb->expr()->eq('resource_type', $qb->createNamedParameter($resourceType)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(MigrationResourceItem::STATE_PENDING)))
			->setMaxResults($limit)
			->orderBy('id', 'ASC');

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
