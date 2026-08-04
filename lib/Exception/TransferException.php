<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Exception;

/**
 * Thrown for transient or permanent file transfer failures. Callers use
 * isRetryable() to decide whether the file state machine should schedule a
 * retry or move directly to a terminal failed state.
 */
class TransferException extends \RuntimeException {
	private bool $retryable;

	public function __construct(string $message, bool $retryable = true, ?\Throwable $previous = null) {
		parent::__construct($message, 0, $previous);
		$this->retryable = $retryable;
	}

	public function isRetryable(): bool {
		return $this->retryable;
	}
}
