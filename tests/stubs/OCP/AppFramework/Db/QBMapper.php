<?php

declare(strict_types=1);

namespace OCP\AppFramework\Db;

/**
 * Minimal test-only stand-in for OCP\AppFramework\Db\QBMapper.
 *
 * Our concrete mapper classes (MigrationFileMapper, MigrationRunMapper,
 * etc.) are always mocked wholesale via PHPUnit's createMock() in tests
 * (which disables the constructor), so this stub only needs to exist for
 * the `class Foo extends QBMapper` declarations to be loadable - no real
 * query logic is required.
 */
abstract class QBMapper {
	public function __construct($db = null, $tableName = null, $entityClass = null) {
	}

	public function insert($entity) {
		return $entity;
	}

	public function update($entity) {
		return $entity;
	}

	public function delete($entity) {
		return $entity;
	}

	protected function findEntity($query) {
		throw new DoesNotExistException('stub');
	}

	protected function findEntities($query): array {
		return [];
	}
}
