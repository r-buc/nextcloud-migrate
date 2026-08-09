<?php

declare(strict_types=1);

namespace OCP\Files\Search;

interface ISearchComparison extends ISearchOperator {
	public const COMPARE_EQUAL = 'eq';
	public const COMPARE_GREATER_THAN = 'gt';
	public const COMPARE_GREATER_THAN_EQUAL = 'gte';
	public const COMPARE_LESS_THAN = 'lt';
	public const COMPARE_LESS_THAN_EQUAL = 'lte';
	public const COMPARE_LIKE = 'like';
	public const COMPARE_LIKE_CASE_SENSITIVE = 'clike';
	public const COMPARE_DEFINED = 'is-defined';

	public function getType(): string;

	public function getField(): string;

	public function getExtra(): string;

	public function getValue(): string|int|bool|\DateTime;
}
