<?php

declare(strict_types=1);

namespace OCP\DB\QueryBuilder;

/**
 * Only the constants our mapper classes reference are needed for these
 * unit tests (the mappers themselves are always mocked, never really
 * executed against a database).
 */
interface IQueryBuilder {
	public const PARAM_STR_ARRAY = 102;
	public const PARAM_BOOL = 5;
	public const PARAM_INT = 1;
}
