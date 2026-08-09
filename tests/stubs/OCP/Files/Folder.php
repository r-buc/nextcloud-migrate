<?php

declare(strict_types=1);

namespace OCP\Files;

interface Folder extends Node {
	/**
	 * @return Node[]
	 */
	public function getDirectoryListing(): array;
}
