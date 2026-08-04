<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service;

use OCA\NextcloudMigrate\Db\MigrationFile;
use OCA\NextcloudMigrate\Db\MigrationFileMapper;
use OCA\NextcloudMigrate\Db\RemoteInstance;
use OCA\NextcloudMigrate\Exception\RemoteConnectionException;

/**
 * Resolves each discovered file's destination path on the target instance
 * and applies the run's collision strategy when a path already exists
 * remotely.
 */
class MappingService {
	public const STRATEGY_RENAME = 'rename';
	public const STRATEGY_SKIP = 'skip';
	public const STRATEGY_OVERWRITE = 'overwrite';

	public const STRATEGIES = [self::STRATEGY_RENAME, self::STRATEGY_SKIP, self::STRATEGY_OVERWRITE];

	public function __construct(
		private WebDavClient $webDavClient,
		private MigrationFileMapper $fileMapper,
	) {
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public function mapFile(
		MigrationFile $file,
		RemoteInstance $instance,
		string $appPassword,
		string $collisionStrategy,
	): void {
		if (!in_array($collisionStrategy, self::STRATEGIES, true)) {
			throw new \InvalidArgumentException("Unknown collision strategy '{$collisionStrategy}'");
		}

		$now = time();

		// Folders are cheap to reconcile: Nextcloud/WebDAV MKCOL is
		// idempotent, so folders never collide in a way that blocks
		// migration - we always map them 1:1 by relative path.
		if ($file->getIsDirectory()) {
			$file->setTargetPath($file->getSourcePath());
			$file->setState(MigrationFile::STATE_MAPPED);
			$file->setUpdatedAt($now);
			$this->fileMapper->update($file);
			return;
		}

		try {
			$existing = $this->webDavClient->stat($instance, $appPassword, $file->getSourcePath());
		} catch (RemoteConnectionException $e) {
			$file->setState(MigrationFile::STATE_MAPPING_FAILED);
			$file->setLastError('Collision check failed: ' . $e->getMessage());
			$file->setUpdatedAt($now);
			$this->fileMapper->update($file);
			return;
		}

		if ($existing === null) {
			$file->setTargetPath($file->getSourcePath());
			$file->setState(MigrationFile::STATE_MAPPED);
			$file->setUpdatedAt($now);
			$this->fileMapper->update($file);
			return;
		}

		switch ($collisionStrategy) {
			case self::STRATEGY_SKIP:
				$file->setState(MigrationFile::STATE_SKIPPED);
				$file->setLastError('Skipped: target path already exists');
				break;

			case self::STRATEGY_OVERWRITE:
				$file->setTargetPath($file->getSourcePath());
				$file->setState(MigrationFile::STATE_MAPPED);
				break;

			case self::STRATEGY_RENAME:
			default:
				$file->setTargetPath($this->renamedPath($file->getSourcePath()));
				$file->setState(MigrationFile::STATE_MAPPED);
				break;
		}

		$file->setUpdatedAt($now);
		$this->fileMapper->update($file);
	}

	private function renamedPath(string $path): string {
		$dot = strrpos($path, '.');
		$suffix = '_migrated_' . time();

		if ($dot === false || $dot === strrpos($path, '/')) {
			return $path . $suffix;
		}

		return substr($path, 0, $dot) . $suffix . substr($path, $dot);
	}
}
