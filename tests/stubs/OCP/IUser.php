<?php

declare(strict_types=1);

namespace OCP;

interface IUser {
	public function getUID(): string;

	public function getDisplayName(): string;

	public function getEMailAddress(): ?string;

	public function getQuota(): string;
}
