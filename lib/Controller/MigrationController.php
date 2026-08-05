<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Controller;

use OCA\NextcloudMigrate\Db\MigrationRun;
use OCA\NextcloudMigrate\Db\MigrationRunMapper;
use OCA\NextcloudMigrate\Db\RemoteInstance;
use OCA\NextcloudMigrate\Db\RemoteInstanceMapper;
use OCA\NextcloudMigrate\Exception\RemoteConnectionException;
use OCA\NextcloudMigrate\Service\CredentialService;
use OCA\NextcloudMigrate\Service\RunOrchestrator;
use OCA\NextcloudMigrate\Service\WebDavClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\NextcloudMigrate\Util\UuidGenerator;

/**
 * Admin-only REST endpoints (no #[NoAdminRequired] attribute is used, so
 * Nextcloud's default admin-only enforcement applies) for managing target
 * instance credentials and the lifecycle of migration runs.
 *
 * Ownership model (v1): each admin only sees/manages the instances and runs
 * they personally created (matched on created_by). This is a simple,
 * predictable model for the initial admin-driven release; broadening to
 * "any admin can see any run" is a straightforward follow-up if needed.
 */
class MigrationController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private RemoteInstanceMapper $instanceMapper,
		private MigrationRunMapper $runMapper,
		private CredentialService $credentialService,
		private WebDavClient $webDavClient,
		private RunOrchestrator $runOrchestrator,
	) {
		parent::__construct('nextcloud_migrate', $request);
	}

	public function listInstances(): JSONResponse {
		$instances = $this->instanceMapper->findAllForOwner($this->currentUserId());

		return new JSONResponse($instances);
	}

	public function createInstance(string $label, string $url, string $targetUserId, string $appPassword, bool $allowSelfSigned = false): JSONResponse {
		if (!preg_match('#^https://#i', $url) && !($allowSelfSigned && preg_match('#^http://#i', $url))) {
			return new JSONResponse(['error' => 'Target URL must use HTTPS'], Http::STATUS_BAD_REQUEST);
		}

		$instance = new RemoteInstance();
		$instance->setUuid(UuidGenerator::v4());
		$instance->setLabel($label);
		$instance->setUrl(rtrim($url, '/'));
		$instance->setTargetUserId($targetUserId);
		$instance->setAppPasswordEncrypted($this->credentialService->encrypt($appPassword));
		$instance->setAllowSelfSigned($allowSelfSigned);
		$instance->setCreatedBy($this->currentUserId());
		$instance->setCreatedAt(time());
		$instance = $this->instanceMapper->insert($instance);

		return new JSONResponse($instance, Http::STATUS_CREATED);
	}

	public function testInstance(int $instanceId): JSONResponse {
		try {
			$instance = $this->ownedInstance($instanceId);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'Instance not found'], Http::STATUS_NOT_FOUND);
		}
		if ($instance === null) {
			return new JSONResponse(['error' => 'Instance not found'], Http::STATUS_NOT_FOUND);
		}

		$now = time();
		try {
			$appPassword = $this->credentialService->decrypt($instance->getAppPasswordEncrypted());
			$this->webDavClient->testConnection($instance, $appPassword);
			$instance->setLastTestedAt($now);
			$instance->setLastTestError(null);
			$this->instanceMapper->update($instance);

			return new JSONResponse(['success' => true, 'testedAt' => $now]);
		} catch (RemoteConnectionException $e) {
			$instance->setLastTestedAt($now);
			$instance->setLastTestError($e->getMessage());
			$this->instanceMapper->update($instance);

			return new JSONResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	public function deleteInstance(int $instanceId): JSONResponse {
		try {
			$instance = $this->ownedInstance($instanceId);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'Instance not found'], Http::STATUS_NOT_FOUND);
		}
		if ($instance === null) {
			return new JSONResponse(['error' => 'Instance not found'], Http::STATUS_NOT_FOUND);
		}

		$this->instanceMapper->delete($instance);

		return new JSONResponse([], Http::STATUS_NO_CONTENT);
	}

	public function listRuns(): JSONResponse {
		return new JSONResponse($this->runMapper->findAllForOwner($this->currentUserId()));
	}

	/**
	 * @param array<string,string> $userMappings sourceUserId => targetUserId
	 */
	public function createRun(int $instanceId, string $collisionStrategy, array $userMappings): JSONResponse {
		try {
			$instance = $this->ownedInstance($instanceId);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'Target instance not found'], Http::STATUS_NOT_FOUND);
		}
		if ($instance === null) {
			return new JSONResponse(['error' => 'Target instance not found'], Http::STATUS_NOT_FOUND);
		}

		if ($userMappings === []) {
			return new JSONResponse(['error' => 'At least one source->target user mapping is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$run = $this->runOrchestrator->createRun($this->currentUserId(), $instanceId, $collisionStrategy, $userMappings);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($run, Http::STATUS_CREATED);
	}

	public function getRun(int $runId): JSONResponse {
		$run = $this->ownedRun($runId);
		if ($run instanceof JSONResponse) {
			return $run;
		}

		return new JSONResponse($run);
	}

	/**
	 * Kicks off (or retries, after a VALIDATION_FAILED) connectivity
	 * validation and discovery. The run reaches DRY_RUN_READY asynchronously
	 * once DiscoveryJob finishes; poll GET /runs/{id} or the status endpoint.
	 */
	public function dryRun(int $runId): JSONResponse {
		$run = $this->ownedRun($runId);
		if ($run instanceof JSONResponse) {
			return $run;
		}

		if (!in_array($run->getState(), [MigrationRun::STATE_CREATED, MigrationRun::STATE_VALIDATION_FAILED], true)) {
			return new JSONResponse(['error' => "Run cannot start a dry run from state '{$run->getState()}'"], Http::STATUS_CONFLICT);
		}

		$run = $this->runOrchestrator->startValidationAndDiscovery($runId);

		return new JSONResponse($run);
	}

	public function approveRun(int $runId): JSONResponse {
		$run = $this->ownedRun($runId);
		if ($run instanceof JSONResponse) {
			return $run;
		}

		try {
			$run = $this->runOrchestrator->approveRun($runId, $this->currentUserId());
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}

		return new JSONResponse($run);
	}

	public function pauseRun(int $runId): JSONResponse {
		$run = $this->ownedRun($runId);
		if ($run instanceof JSONResponse) {
			return $run;
		}

		try {
			$run = $this->runOrchestrator->pauseRun($runId);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}

		return new JSONResponse($run);
	}

	public function resumeRun(int $runId): JSONResponse {
		$run = $this->ownedRun($runId);
		if ($run instanceof JSONResponse) {
			return $run;
		}

		try {
			$run = $this->runOrchestrator->resumeRun($runId);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}

		return new JSONResponse($run);
	}

	public function cancelRun(int $runId): JSONResponse {
		$run = $this->ownedRun($runId);
		if ($run instanceof JSONResponse) {
			return $run;
		}

		try {
			$run = $this->runOrchestrator->cancelRun($runId);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}

		return new JSONResponse($run);
	}

	private function currentUserId(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new \RuntimeException('No authenticated user');
	}

	/**
	 * @throws DoesNotExistException
	 */
	private function ownedInstance(int $instanceId): ?RemoteInstance {
		$instance = $this->instanceMapper->find($instanceId);
		if ($instance->getCreatedBy() !== $this->currentUserId()) {
			return null;
		}

		return $instance;
	}

	/**
	 * @return MigrationRun|JSONResponse MigrationRun on success, or a ready-to-return 404/403 JSONResponse
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
