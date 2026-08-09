<?php

declare(strict_types=1);

namespace OCP\Files\Search;

interface ISearchOperator {
	public function getQueryHint(string $name, $default);

	public function setQueryHint(string $name, $value): void;
}
