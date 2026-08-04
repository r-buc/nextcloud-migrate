<?php

declare(strict_types=1);

namespace OCP;

interface IConfig {
	public function getAppValue(string $app, string $key, string $default = ''): string;

	public function setAppValue(string $app, string $key, string $value): void;
}
