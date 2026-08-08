<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service;

use OCA\NextcloudMigrate\Db\MigrationEvent;
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
	// Only overwrites the target if the source file's mtime is strictly
	// newer than the target's; otherwise behaves like STRATEGY_SKIP. Lets a
	// migration be re-run repeatedly (e.g. after fixing a batch of source-side
	// issues) without clobbering target files that already match or are
	// ahead of the source, while still picking up files that changed since
	// the last run.
	public const STRATEGY_OVERWRITE_IF_NEWER = 'overwrite_newer';

	public const STRATEGIES = [self::STRATEGY_RENAME, self::STRATEGY_SKIP, self::STRATEGY_OVERWRITE, self::STRATEGY_OVERWRITE_IF_NEWER];

	public function __construct(
		private WebDavClient $webDavClient,
		private MigrationFileMapper $fileMapper,
		private EventLogger $eventLogger,
	) {
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public function mapFile(
		MigrationFile $file,
		RemoteInstance $instance,
		string $targetUserId,
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
			$existing = $this->webDavClient->stat($instance, $targetUserId, $appPassword, $file->getSourcePath());
		} catch (RemoteConnectionException $e) {
			$file->setState(MigrationFile::STATE_MAPPING_FAILED);
			$file->setLastError('Collision check failed: ' . $e->getMessage());
			$file->setUpdatedAt($now);
			$this->fileMapper->update($file);
			$this->eventLogger->log(
				$file->getRunId(),
				'mapping_failed',
				"Collision check for '{$file->getSourcePath()}' failed: {$e->getMessage()}",
				MigrationEvent::SEVERITY_ERROR,
				$file->getId(),
			);
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

			case self::STRATEGY_OVERWRITE_IF_NEWER:
				if ($this->isSourceNewer($file, $existing)) {
					$file->setTargetPath($file->getSourcePath());
					$file->setState(MigrationFile::STATE_MAPPED);
				} else {
					$file->setState(MigrationFile::STATE_SKIPPED);
					$file->setLastError('Skipped: target already exists and is not older than the source');
				}
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

	/**
	 * True only when both the source's discovered mtime and the target's
	 * PROPFIND-reported mtime are known and the source is strictly newer.
	 * Either side being unknown (e.g. the target server didn't return
	 * d:getlastmodified, or discovery somehow left mtime unset) is treated
	 * conservatively as "not newer" - skipping is always safe, whereas
	 * guessing wrong and overwriting could clobber data we can't prove is
	 * actually older.
	 *
	 * @param array{size:int,etag:?string,checksum:?string,mtime:?int} $existing
	 */
	private function isSourceNewer(MigrationFile $file, array $existing): bool {
		$sourceMtime = $file->getMtime();
		$targetMtime = $existing['mtime'] ?? null;

		if ($sourceMtime === null || $targetMtime === null) {
			return false;
		}

		return $sourceMtime > $targetMtime;
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
