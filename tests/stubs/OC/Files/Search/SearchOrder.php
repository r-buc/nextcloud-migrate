<?php

declare(strict_types=1);

namespace OC\Files\Search;

use OCP\Files\FileInfo;
use OCP\Files\Search\ISearchOrder;

class SearchOrder implements ISearchOrder {
	public function __construct(
		private string $direction,
		private string $field,
		private string $extra = '',
	) {
	}

	public function getDirection(): string {
		return $this->direction;
	}

	public function getField(): string {
		return $this->field;
	}

	public function getExtra(): string {
		return $this->extra;
	}

	public function sortFileInfo(FileInfo $a, FileInfo $b): int {
		return 0;
	}
}
