<?php

declare(strict_types=1);

namespace OCP;

interface IUserManager {
	public function get(string $uid): ?IUser;

	/**
	 * @return IUser[]
	 */
	public function search(string $pattern, ?int $limit = null, ?int $offset = null): array;
}
