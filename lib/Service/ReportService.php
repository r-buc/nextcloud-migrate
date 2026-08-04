<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service;

use OCA\NextcloudMigrate\Db\MigrationFile;
use OCA\NextcloudMigrate\Db\MigrationFileMapper;
use OCA\NextcloudMigrate\Db\MigrationRun;
use OCA\NextcloudMigrate\Db\UserMapMapper;

/**
 * Builds the JSON reports shown to admins for a run: an early "dry run"
 * volume/connectivity summary, and a final outcome summary once the run
 * reaches a terminal state.
 */
class ReportService {
	public function __construct(
		private MigrationFileMapper $fileMapper,
		private UserMapMapper $userMapMapper,
	) {
	}

	public function buildDryRunReport(MigrationRun $run): array {
		$counts = $this->fileMapper->countByState($run->getId());
		$userMaps = $this->userMapMapper->findByRun($run->getId());

		// Run-level totals are refreshed by the caller
		// (RunOrchestrator::onDiscoveryComplete) right before this runs.
		return [
			'generatedAt' => time(),
			'totalUsers' => count($userMaps),
			'totalFiles' => $run->getTotalFiles(),
			'totalBytes' => $run->getTotalBytes(),
			'stateCounts' => $counts,
			'collisionStrategy' => $run->getCollisionStrategy(),
			'perUser' => array_map(static fn ($um) => [
				'sourceUserId' => $um->getSourceUserId(),
				'targetUserId' => $um->getTargetUserId(),
				'totalFiles' => $um->getTotalFiles(),
			], $userMaps),
			'notes' => [
				'Collision detection (rename/skip/overwrite) is resolved per-file during transfer, not during this dry run, to avoid an extra network round trip per file against the target instance.',
				'v1 scope migrates file content, folder structure, and modification time only. Shares, tags, comments, favorites, versions, and encrypted files are not migrated.',
			],
		];
	}

	public function buildFinalReport(MigrationRun $run): array {
		$counts = $this->fileMapper->countByState($run->getId());
		$userMaps = $this->userMapMapper->findByRun($run->getId());

		$failedStates = [MigrationFile::STATE_TRANSFER_FAILED, MigrationFile::STATE_VERIFICATION_FAILED, MigrationFile::STATE_MAPPING_FAILED];
		$failedCount = 0;
		foreach ($failedStates as $s) {
			$failedCount += $counts[$s] ?? 0;
		}

		$failedFiles = [];
		foreach ($failedStates as $s) {
			foreach ($this->fileMapper->findByRun($run->getId(), $s, 200, 0) as $f) {
				$failedFiles[] = [
					'sourcePath' => $f->getSourcePath(),
					'state' => $f->getState(),
					'lastError' => $f->getLastError(),
				];
			}
		}

		return [
			'generatedAt' => time(),
			'stateCounts' => $counts,
			'totalFiles' => $run->getTotalFiles(),
			'verifiedFiles' => $counts[MigrationFile::STATE_VERIFIED] ?? 0,
			'skippedFiles' => $counts[MigrationFile::STATE_SKIPPED] ?? 0,
			'failedFiles' => $failedCount,
			'failedFileSample' => $failedFiles,
			'perUser' => array_map(static fn ($um) => [
				'sourceUserId' => $um->getSourceUserId(),
				'targetUserId' => $um->getTargetUserId(),
				'state' => $um->getState(),
				'totalFiles' => $um->getTotalFiles(),
				'transferredFiles' => $um->getTransferredFiles(),
				'failedFiles' => $um->getFailedFiles(),
			], $userMaps),
		];
	}
}
