<?php

declare(strict_types=1);

namespace OCP\AppFramework\Db;

/**
 * Minimal test-only stand-in for OCP\AppFramework\Db\Entity.
 *
 * Replicates just enough of the real Entity's behavior for our entity
 * subclasses (RemoteInstance, MigrationRun, UserMap, MigrationFile,
 * MigrationEvent) to work under test: id storage, addType() bookkeeping,
 * and magic getFoo()/setFoo() accessors backed by protected properties.
 */
abstract class Entity implements \JsonSerializable {
	protected $id;
	private array $fieldTypes = [];
	private array $updatedFields = [];

	public function getId() {
		return $this->id;
	}

	public function setId($id): void {
		$this->id = $id;
	}

	protected function addType(string $fieldName, string $type): void {
		$this->fieldTypes[$fieldName] = $type;
	}

	public function __call(string $name, array $arguments) {
		if (str_starts_with($name, 'get')) {
			$property = lcfirst(substr($name, 3));
			return $this->$property;
		}

		if (str_starts_with($name, 'set')) {
			$property = lcfirst(substr($name, 3));
			$this->$property = $arguments[0];
			$this->updatedFields[$property] = true;
			return null;
		}

		throw new \BadMethodCallException("Undefined method '{$name}'");
	}

	public function jsonSerialize(): array {
		return get_object_vars($this);
	}
}
