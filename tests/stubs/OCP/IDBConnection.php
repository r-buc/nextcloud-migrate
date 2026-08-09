<?php

declare(strict_types=1);

namespace OCP;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Only enough of the real interface for classes that take IDBConnection as
 * a constructor dependency to be loadable/mockable in these unit tests -
 * such classes (FilecacheReader, and the QBMapper-based Db\*Mapper classes)
 * are always mocked rather than really executed against a database; real
 * DB behavior is covered by the e2e integration test instead.
 */
interface IDBConnection {
	public function getQueryBuilder(): IQueryBuilder;
}
