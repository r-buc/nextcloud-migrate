<?php

declare(strict_types=1);

namespace OC\Files\Search;

use OCP\Files\Search\ISearchOperator;
use OCP\Files\Search\ISearchOrder;
use OCP\Files\Search\ISearchQuery;
use OCP\IUser;

class SearchQuery implements ISearchQuery {
	public function __construct(
		private ISearchOperator $searchOperation,
		private int $limit,
		private int $offset,
		private array $order,
		private ?IUser $user = null,
		private bool $limitToHome = false,
	) {
	}

	public function getSearchOperation() {
		return $this->searchOperation;
	}

	public function getLimit() {
		return $this->limit;
	}

	public function getOffset() {
		return $this->offset;
	}

	public function getOrder() {
		return $this->order;
	}

	public function getUser(): ?IUser {
		return $this->user;
	}

	public function limitToHome(): bool {
		return $this->limitToHome;
	}
}
