<?php

declare(strict_types=1);

namespace OCP\Files;

interface Node {
	public function getId(): int;

	public function getPath(): string;

	public function getSize(): int|float;

	public function getMTime(): int;

	public function getMimetype(): string;
}
