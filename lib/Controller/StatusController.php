<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Controller;

use OCA\NextcloudMigrate\Db\MigrationEventMapper;
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

		$totalTerminal = ($run->getTotalFiles() > 0)
			? round((($counts['verified'] ?? 0) + ($counts['skipped'] ?? 0)) / $run->getTotalFiles() * 100, 1)
			: 0.0;

		return new JSONResponse([
			'run' => $run,
			'stateCounts' => $counts,
			'progressPercent' => $totalTerminal,
			'userMaps' => $userMaps,
		]);
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
