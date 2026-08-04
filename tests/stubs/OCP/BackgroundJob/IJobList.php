<?php

declare(strict_types=1);

namespace OCP\BackgroundJob;

interface IJobList {
	public function add($job, $argument = null, int $firstCheck = 0): void;

	public function remove($job, $argument = null): void;
}
