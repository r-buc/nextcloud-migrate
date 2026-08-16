<?php

declare(strict_types=1);

namespace OCP\Share;

use OCP\Files\Node;

interface IManager {
	/**
	 * @return IShare[]
	 */
	public function getSharesBy(string $userId, int $shareType, ?Node $path = null, bool $reshares = false, int $limit = 50, int $offset = 0): array;
}
