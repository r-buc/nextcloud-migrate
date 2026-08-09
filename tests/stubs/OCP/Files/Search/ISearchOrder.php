<?php

declare(strict_types=1);

namespace OCP\Files\Search;

use OCP\Files\FileInfo;

interface ISearchOrder {
	public const DIRECTION_ASCENDING = 'asc';
	public const DIRECTION_DESCENDING = 'desc';

	public function getDirection(): string;

	public function getField(): string;

	public function getExtra(): string;

	public function sortFileInfo(FileInfo $a, FileInfo $b): int;
}
