<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<MigrationEvent>
 */
class MigrationEventMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'migrate_events', MigrationEvent::class);
	}

	/**
	 * @return MigrationEvent[]
	 * @throws Exception
	 */
	public function findByRun(int $runId, int $limit = 200, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->orderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		return $this->findEntities($qb);
	}

	/**
	 * @throws Exception
	 */
	public function countByType(int $runId, string $eventType): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->eq('event_type', $qb->createNamedParameter($eventType)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
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
