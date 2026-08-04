<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Exception;

/**
 * Thrown when a transferred file's checksum does not match the source.
 */
class VerificationException extends \RuntimeException {
}
