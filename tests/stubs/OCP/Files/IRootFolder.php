<?php

declare(strict_types=1);

namespace OCP\Files;

interface IRootFolder {
	public function getUserFolder(string $userId): Folder;
}
