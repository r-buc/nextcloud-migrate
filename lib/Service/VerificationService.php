<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service;

use OCA\NextcloudMigrate\Db\MigrationFile;
use OCA\NextcloudMigrate\Db\MigrationFileMapper;
use OCA\NextcloudMigrate\Db\RemoteInstance;
use OCA\NextcloudMigrate\Exception\TransferException;

/**
 * Confirms transferred file content matches the source via checksum
 * comparison (preferring the server-reported OC-Checksum, falling back to a
 * full download+hash only when the target doesn't expose one).
 */
class VerificationService {
	public function __construct(
		private WebDavClient $webDavClient,
		private MigrationFileMapper $fileMapper,
	) {
	}

	public function verifyFile(MigrationFile $file, RemoteInstance $instance, string $appPassword): void {
		$now = time();
		$targetPath = $file->getTargetPath() ?? $file->getSourcePath();

		try {
			$remote = $this->webDavClient->stat($instance, $appPassword, $targetPath);
			if ($remote === null) {
				throw new TransferException('Target file missing at verification time', true);
			}

			$targetChecksum = $remote['checksum'] ?? $this->webDavClient->fetchSha256($instance, $appPassword, $targetPath);
			$file->setTargetChecksum($targetChecksum);

			$matches = $file->getSourceChecksum() !== null
				&& hash_equals(strtolower($file->getSourceChecksum()), strtolower($targetChecksum))
				&& $remote['size'] === $file->getSize();

			if ($matches) {
				$file->setState(MigrationFile::STATE_VERIFIED);
				$file->setVerifiedAt($now);
				$file->setLastError(null);
			} else {
				$this->recordMismatch($file, 'Checksum/size mismatch between source and target');
			}
		} catch (\Throwable $e) {
			$this->recordMismatch($file, $e->getMessage());
		}

		$file->setLockOwner(null);
		$file->setLockExpiresAt(null);
		$file->setUpdatedAt($now);
		$this->fileMapper->update($file);
	}

	private function recordMismatch(MigrationFile $file, string $reason): void {
		$attempts = $file->getVerifyAttempts() + 1;
		$file->setVerifyAttempts($attempts);
		$file->setLastError($reason);

		if ($attempts < MigrationFile::MAX_VERIFY_ATTEMPTS) {
			// Re-queue the transfer itself, not just verification: a
			// mismatch means the bytes on target are suspect, so the safest
			// recovery is a full re-upload rather than re-checking the same
			// (possibly corrupt) remote content.
			$file->setState(MigrationFile::STATE_TRANSFER_FAILED);
			$file->setNextRetryAt(time());
		} else {
			$file->setState(MigrationFile::STATE_VERIFICATION_FAILED);
			$file->setNextRetryAt(null);
		}
	}
}
