<?php

declare(strict_types=1);

namespace OCP\Files;

use OCP\Files\Search\ISearchQuery;

interface Folder extends Node {
	/**
	 * @return Node[]
	 */
	public function getDirectoryListing(): array;

	/**
	 * @param string|ISearchQuery $query
	 * @return Node[]
	 */
	public function search($query): array;
}
