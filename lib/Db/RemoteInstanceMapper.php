<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<RemoteInstance>
 */
class RemoteInstanceMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'migrate_instances', RemoteInstance::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws Exception
	 */
	public function find(int $id): RemoteInstance {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity($qb);
	}

	/**
	 * @return RemoteInstance[]
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
}
