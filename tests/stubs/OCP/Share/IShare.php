<?php

declare(strict_types=1);

namespace OCP\Share;

interface IShare {
	public const TYPE_USER = 0;
	public const TYPE_GROUP = 1;
	public const TYPE_LINK = 3;

	public function getId(): string;

	public function getShareType(): int;

	public function getSharedWith(): ?string;

	public function getPermissions(): int;

	public function getNode(): \OCP\Files\Node;

	public function getExpirationDate(): ?\DateTimeInterface;

	public function getLabel(): ?string;
}
