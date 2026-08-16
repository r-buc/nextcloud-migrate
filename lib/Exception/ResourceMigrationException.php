<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Exception;

/**
 * Thrown by a ResourceMigratorInterface implementation (contacts/calendars/
 * shares migrators - see lib/Service/ResourceMigrator/) when syncing a
 * single item to the target fails. Mirrors TransferException's shape;
 * $retryable is currently informational only (every resource item gets a
 * single attempt, same as MigrationResourceItem::MAX_ATTEMPTS - see its
 * docblock for why automatic retries within a run aren't worth the delay),
 * but is kept for parity with TransferException and in case a future
 * resource type's worker wants to distinguish transient vs. permanent
 * failures.
 */
class ResourceMigrationException extends \Exception {
	public function __construct(string $message, private readonly bool $retryable = false, ?\Throwable $previous = null) {
		parent::__construct($message, 0, $previous);
	}

	public function isRetryable(): bool {
		return $this->retryable;
	}
}
