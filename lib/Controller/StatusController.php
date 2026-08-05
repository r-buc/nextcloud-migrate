<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Controller;

use OCA\NextcloudMigrate\Db\MigrationEventMapper;
use OCA\NextcloudMigrate\Db\MigrationFile;
use OCA\NextcloudMigrate\Db\MigrationFileMapper;
use OCA\NextcloudMigrate\Db\MigrationRun;
use OCA\NextcloudMigrate\Db\UserMapMapper;
use OCA\NextcloudMigrate\Service\RunOrchestrator;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Read-only progress/reporting endpoints for a migration run. Admin-only by
 * default (no #[NoAdminRequired]); ownership is checked the same way as
 * MigrationController (created_by match).
 */
class StatusController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private RunOrchestrator $runOrchestrator,
		private MigrationFileMapper $fileMapper,
		private MigrationEventMapper $eventMapper,
		private UserMapMapper $userMapMapper,
	) {
		parent::__construct('nextcloud_migrate', $request);
	}

	public function runStatus(int $runId): JSONResponse {
		$run = $this->ownedRun($runId);
		if ($run instanceof JSONResponse) {
			return $run;
		}

		$counts = $this->fileMapper->countByState($runId);
		$userMaps = $this->userMapMapper->findByRun($runId);

		// run.transferredFiles/verifiedFiles/failedFiles are only persisted at
		// phase-transition boundaries (RunOrchestrator::onTransferPoolIdle()/
		// finalizeRun()), not continuously as workers process files, so they
		// go stale mid-phase (e.g. still showing 2 verified while stateCounts
		// already shows 150). Refresh them in-memory from the live per-file
		// counts for this response only - not persisted, to avoid an extra
		// write on every status poll.
		$this->runOrchestrator->refreshRunCounters($run, $counts);

		return new JSONResponse([
			'run' => $run,
			'stateCounts' => $counts,
			'progressPercent' => $this->calculateProgressPercent($run->getTotalFiles(), $counts),
			'userMaps' => $userMaps,
		]);
	}

	/**
	 * Weights each file's contribution by how far it has progressed through
	 * the two-phase (transfer, then verify) pipeline, so the reported
	 * percentage reflects real progress throughout the whole run instead of
	 * staying at 0% for the entire (often much longer) transfer phase and
	 * only starting to move once verification begins.
	 *
	 * @param array<string,int> $counts
	 */
	private function calculateProgressPercent(int $totalFiles, array $counts): float {
		if ($totalFiles <= 0) {
			return 0.0;
		}

		static $weights = [
			MigrationFile::STATE_DISCOVERED => 0.0,
			MigrationFile::STATE_MAPPED => 0.0,
			MigrationFile::STATE_TRANSFERRING => 0.25,
			MigrationFile::STATE_TRANSFER_FAILED => 0.25,
			// Terminal - will never be transferred, so counts as settled.
			MigrationFile::STATE_MAPPING_FAILED => 1.0,
			MigrationFile::STATE_TRANSFERRED => 0.5,
			MigrationFile::STATE_VERIFYING => 0.75,
			MigrationFile::STATE_VERIFICATION_FAILED => 0.75,
			MigrationFile::STATE_VERIFIED => 1.0,
			MigrationFile::STATE_SKIPPED => 1.0,
			MigrationFile::STATE_COMPLETED => 1.0,
		];

		$weighted = 0.0;
		foreach ($counts as $state => $count) {
			$weighted += ($weights[$state] ?? 0.0) * $count;
		}

		return round($weighted / $totalFiles * 100, 1);
	}

	public function runFiles(int $runId, ?string $state = null, int $limit = 100, int $offset = 0): JSONResponse {
		$run = $this->ownedRun($runId);
		if ($run instanceof JSONResponse) {
			return $run;
		}

		$limit = max(1, min($limit, 500));
		$files = $this->fileMapper->findByRun($runId, $state, $limit, $offset);

		return new JSONResponse($files);
	}

	public function runReport(int $runId): JSONResponse {
		$run = $this->ownedRun($runId);
		if ($run instanceof JSONResponse) {
			return $run;
		}

		return new JSONResponse([
			'state' => $run->getState(),
			'dryRunReport' => $run->getDryRunReport() !== null ? json_decode($run->getDryRunReport(), true) : null,
			'finalReport' => $run->getFinalReport() !== null ? json_decode($run->getFinalReport(), true) : null,
		]);
	}

	public function runEvents(int $runId, int $limit = 200, int $offset = 0): JSONResponse {
		$run = $this->ownedRun($runId);
		if ($run instanceof JSONResponse) {
			return $run;
		}

		$limit = max(1, min($limit, 500));

		return new JSONResponse($this->eventMapper->findByRun($runId, $limit, $offset));
	}

	private function currentUserId(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new \RuntimeException('No authenticated user');
	}

	/**
	 * @return MigrationRun|JSONResponse MigrationRun on success, or a ready-to-return 404 JSONResponse
	 */
	private function ownedRun(int $runId): MigrationRun|JSONResponse {
		try {
			$run = $this->runOrchestrator->getRun($runId);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'Run not found'], Http::STATUS_NOT_FOUND);
		}

		if ($run->getCreatedBy() !== $this->currentUserId()) {
			return new JSONResponse(['error' => 'Run not found'], Http::STATUS_NOT_FOUND);
		}

		return $run;
	}
}
