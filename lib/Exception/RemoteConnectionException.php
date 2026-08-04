<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Exception;

/**
 * Thrown when the target instance cannot be reached, authenticated against,
 * or otherwise fails a connectivity/validation check.
 */
class RemoteConnectionException extends \RuntimeException {
}
