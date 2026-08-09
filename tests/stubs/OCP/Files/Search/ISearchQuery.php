<?php

declare(strict_types=1);

namespace OCP\Files\Search;

use OCP\IUser;

interface ISearchQuery {
	public function getSearchOperation();

	public function getLimit();

	public function getOffset();

	/**
	 * @return ISearchOrder[]
	 */
	public function getOrder();

	public function getUser(): ?IUser;

	public function limitToHome(): bool;
}
