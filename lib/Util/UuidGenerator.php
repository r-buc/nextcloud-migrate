<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Util;

/**
 * Minimal dependency-free UUID v4 generator.
 *
 * Nextcloud apps must ship their own vendor dependencies; rather than
 * bundling a vendor/ directory just for UUID generation, we generate
 * RFC 4122 version 4 UUIDs using PHP's own random_bytes().
 */
class UuidGenerator {
	public static function v4(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
