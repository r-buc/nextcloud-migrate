<?php

declare(strict_types=1);

namespace OCP;

interface IGroupManager {
	/**
	 * @return string[]
	 */
	public function getUserGroupIds(IUser $user): array;
}
