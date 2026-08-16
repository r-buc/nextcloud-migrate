<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Controller;

use OCA\NextcloudMigrate\Db\MigrationRun;
use OCA\NextcloudMigrate\Db\MigrationRunMapper;
use OCA\NextcloudMigrate\Db\RemoteInstance;
use OCA\NextcloudMigrate\Db\RemoteInstanceMapper;
use OCA\NextcloudMigrate\Exception\RemoteConnectionException;
use OCA\NextcloudMigrate\Service\CredentialService;
use OCA\NextcloudMigrate\Service\ProvisioningClient;
use OCA\NextcloudMigrate\Service\RunOrchestrator;
use OCA\NextcloudMigrate\Service\WebDavClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
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
		private IUserManager $userManager,
		private RemoteInstanceMapper $instanceMapper,
		private MigrationRunMapper $runMapper,
		private CredentialService $credentialService,
		private WebDavClient $webDavClient,
		private ProvisioningClient $provisioningClient,
		private RunOrchestrator $runOrchestrator,
	) {
		parent::__construct('nextcloud_migrate', $request);
	}

	public function listInstances(): JSONResponse {
		$instances = $this->instanceMapper->findAllForOwner($this->currentUserId());

		return new JSONResponse($instances);
	}

	/**
	 * Lists local (source) users, for populating the migration run's user
	 * mapping picker.
	 *
	 * @return JSONResponse array of {id, displayName}
	 */
	public function listLocalUsers(): JSONResponse {
		$users = [];
		foreach ($this->userManager->search('') as $user) {
			$users[] = ['id' => $user->getUID(), 'displayName' => $user->getDisplayName()];
		}

		return new JSONResponse($users);
	}

	/**
	 * Lists remote (target) users via the OCS Provisioning API, using the
	 * instance's admin credential - for populating the 'auto' mapping mode's
	 * default target username suggestion.
	 */
	public function listRemoteUsers(int $instanceId): JSONResponse {
		try {
			$instance = $this->ownedInstance($instanceId);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => 'Instance not found'], Http::STATUS_NOT_FOUND);
		}
		if ($instance === null) {
			return new JSONResponse(['error' => 'Instance not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$adminPassword = $this->credentialService->decrypt($instance->getAdminAppPasswordEncrypted());
			$users = $this->provisioningClient->listUsers($instance, $instance->getAdminUserId(), $adminPassword);

			return new JSONResponse($users);
		} catch (RemoteConnectionException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	/**
	 * Creates or updates the admin's single target instance (v1 only
	 * supports one target per admin - re-submitting this form replaces the
	 * existing configuration rather than adding another one).
	 *
	 * adminUserId/adminAppPassword must belong to an account with admin
	 * privileges on the target instance: they are used only for the OCS
	 * Provisioning API (listing/creating/resetting target users), never for
	 * WebDAV file writes (see RemoteInstance docblock).
	 */
	public function createInstance(string $url, string $adminUserId, string $adminAppPassword, bool $allowSelfSigned = false): JSONResponse {
		if (!preg_match('#^https://#i', $url) && !($allowSelfSigned && preg_match('#^http://#i', $url))) {
			return new JSONResponse(['error' => 'Target URL must use HTTPS'], Http::STATUS_BAD_REQUEST);
		}

		$existing = $this->instanceMapper->findAllForOwner($this->currentUserId());
		$instance = $existing[0] ?? new RemoteInstance();
		$isNew = $instance->getId() === null;
		if ($isNew) {
			$instance->setUuid(UuidGenerator::v4());
			$instance->setCreatedBy($this->currentUserId());
			$instance->setCreatedAt(time());
		}
		$instance->setUrl(rtrim($url, '/'));
		$instance->setAdminUserId($adminUserId);
		$instance->setAdminAppPasswordEncrypted($this->credentialService->encrypt($adminAppPassword));
		$instance->setAllowSelfSigned($allowSelfSigned);
		$instance = $isNew ? $this->instanceMapper->insert($instance) : $this->instanceMapper->update($instance);

		return new JSONResponse($instance, $isNew ? Http::STATUS_CREATED : Http::STATUS_OK);
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
			$this->webDavClient->testConnection($instance);
			$adminPassword = $this->credentialService->decrypt($instance->getAdminAppPasswordEncrypted());
			$this->provisioningClient->listUsers($instance, $instance->getAdminUserId(), $adminPassword);
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
	 * Creates a migration run against the admin's single configured target
	 * instance (v1 only supports one target, so there is no instanceId
	 * parameter to pick from a list).
	 *
	 * @param array<array{sourceUserId: string, targetUserId: string, mode?: string, appPassword?: string}> $userMappings
	 * @param bool $skipVerification skip the post-transfer verification
	 *        phase and rely solely on the target's upload-time OC-Checksum
	 *        validation. Defaults to false (verification on).
	 * @param bool $migrateUserInfo also migrate each mapped user's core
	 *        account profile (displayname, email, quota, language, groups)
	 *        via the OCS Provisioning API, independently of file transfer.
	 *        Defaults to false.
	 * @param bool $migrateContacts also migrate each mapped user's own
	 *        address books and contacts via CardDAV, independently of file
	 *        transfer. Defaults to false.
	 * @param bool $migrateCalendars also migrate each mapped user's own
	 *        calendars via CalDAV, independently of file transfer. Defaults
	 *        to false.
	 * @param bool $migrateShares also migrate each mapped user's own shares
	 *        (user/group/link) once their files have finished transferring.
	 *        Defaults to false.
	 */
	public function createRun(string $collisionStrategy, array $userMappings, bool $skipVerification = false, bool $migrateUserInfo = false, bool $migrateContacts = false, bool $migrateCalendars = false, bool $migrateShares = false): JSONResponse {
		$instances = $this->instanceMapper->findAllForOwner($this->currentUserId());
		if ($instances === []) {
			return new JSONResponse(['error' => 'Configure a target instance before starting a migration'], Http::STATUS_BAD_REQUEST);
		}

		if ($userMappings === []) {
			return new JSONResponse(['error' => 'At least one source->target user mapping is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$run = $this->runOrchestrator->createRun($this->currentUserId(), $instances[0]->getId(), $collisionStrategy, $userMappings, $skipVerification, $migrateUserInfo, $migrateContacts, $migrateCalendars, $migrateShares);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (RemoteConnectionException $e) {
			return new JSONResponse(['error' => 'Failed to prepare target user account(s): ' . $e->getMessage()], Http::STATUS_BAD_GATEWAY);
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

	/**
	 * Retries every currently-failed file on a finished (completed with
	 * errors) run, resetting even permanently-exhausted failures for a
	 * fresh attempt (see RunOrchestrator::retryFailures()).
	 */
	public function retryFailures(int $runId): JSONResponse {
		$run = $this->ownedRun($runId);
		if ($run instanceof JSONResponse) {
			return $run;
		}

		try {
			$run = $this->runOrchestrator->retryFailures($runId);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}

		return new JSONResponse($run);
	}

	/**
	 * Permanently removes a finished run and everything recorded for it.
	 * Only allowed once the run has reached a state where nothing more will
	 * happen to it on its own (see RunOrchestrator::deleteRun()).
	 */
	public function deleteRun(int $runId): JSONResponse {
		$run = $this->ownedRun($runId);
		if ($run instanceof JSONResponse) {
			return $run;
		}

		try {
			$this->runOrchestrator->deleteRun($runId);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}

		return new JSONResponse([], Http::STATUS_NO_CONTENT);
	}

	/**
	 * Enables continuous sync on a finished run (see
	 * RunOrchestrator::startSyncing()): the source is periodically
	 * re-scanned for new/changed files so the target stays up to date
	 * while users are gradually switched over. Only available for the
	 * 'overwrite_newer' collision strategy.
	 */
	public function keepSyncing(int $runId): JSONResponse {
		$run = $this->ownedRun($runId);
		if ($run instanceof JSONResponse) {
			return $run;
		}

		try {
			$run = $this->runOrchestrator->startSyncing($runId);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}

		return new JSONResponse($run);
	}

	/**
	 * Stops continuous sync, settling the run into a terminal state (see
	 * RunOrchestrator::stopSyncing()).
	 */
	public function stopSyncing(int $runId): JSONResponse {
		$run = $this->ownedRun($runId);
		if ($run instanceof JSONResponse) {
			return $run;
		}

		try {
			$run = $this->runOrchestrator->stopSyncing($runId);
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
