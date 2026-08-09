<?php

declare(strict_types=1);

namespace OC\Files\Search;

use OCP\Files\Search\ISearchComparison;

class SearchComparison implements ISearchComparison {
	private array $hints = [];

	public function __construct(
		private string $type,
		private string $field,
		private \DateTime|int|string|bool $value,
		private string $extra = '',
	) {
	}

	public function getType(): string {
		return $this->type;
	}

	public function getField(): string {
		return $this->field;
	}

	public function getValue(): string|int|bool|\DateTime {
		return $this->value;
	}

	public function getExtra(): string {
		return $this->extra;
	}

	public function getQueryHint(string $name, $default) {
		return $this->hints[$name] ?? $default;
	}

	public function setQueryHint(string $name, $value): void {
		$this->hints[$name] = $value;
	}
}
