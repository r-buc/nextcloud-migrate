<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service;

use OCP\Security\ICrypto;

/**
 * Encrypts/decrypts the target instance app password at rest.
 *
 * Uses Nextcloud's own ICrypto service (AES-256-GCM under the hood, keyed off
 * the instance secret) rather than a bespoke implementation. Plaintext
 * passwords only ever exist in PHP memory for the duration of a single
 * request/job and are never logged.
 */
class CredentialService {
	public function __construct(
		private ICrypto $crypto,
	) {
	}

	public function encrypt(string $plainTextAppPassword): string {
		return $this->crypto->encrypt($plainTextAppPassword);
	}

	public function decrypt(string $encryptedAppPassword): string {
		return $this->crypto->decrypt($encryptedAppPassword);
	}

	/**
	 * Redacts a secret for safe inclusion in log/event messages.
	 */
	public function redact(string $secret): string {
		if ($secret === '') {
			return '';
		}

		return substr($secret, 0, 2) . str_repeat('*', max(3, strlen($secret) - 4)) . substr($secret, -2);
	}
}
