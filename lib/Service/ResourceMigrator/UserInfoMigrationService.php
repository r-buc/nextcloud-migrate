<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service\ResourceMigrator;

use OCA\NextcloudMigrate\Db\MigrationResourceItem;
use OCA\NextcloudMigrate\Db\MigrationResourceItemMapper;
use OCA\NextcloudMigrate\Db\MigrationRun;
use OCA\NextcloudMigrate\Db\RemoteInstance;
use OCA\NextcloudMigrate\Db\UserMap;
use OCA\NextcloudMigrate\Db\UserMapMapper;
use OCA\NextcloudMigrate\Service\CredentialService;
use OCA\NextcloudMigrate\Service\EventLogger;
use OCA\NextcloudMigrate\Service\ProvisioningClient;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Migrates a mapped user's core account profile (displayname, email,
 * quota, preferred language, group memberships) to the target instance via
 * the OCS Provisioning API, using the same admin credential already stored
 * on RemoteInstance for user creation/password reset (see ProvisioningClient).
 *
 * Source data is read directly from THIS (source) instance's local OCP
 * services - never over HTTP - since this app runs on the source instance
 * itself (same reasoning as DiscoveryService reading local files via
 * OCP\Files\Folder::search() rather than any remote call).
 *
 * Out of scope for this first pass: phone number and other extended
 * profile fields (would require OCP\Accounts\IAccountManager), and the
 * account's enabled/disabled state - core identity/profile fields only.
 */
class UserInfoMigrationService implements ResourceMigratorInterface {
	public const TYPE = 'user_info';
	private const EXTERNAL_ID_PROFILE = 'profile';

	public function __construct(
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IConfig $config,
		private ProvisioningClient $provisioningClient,
		private CredentialService $credentialService,
		private MigrationResourceItemMapper $resourceItemMapper,
		private UserMapMapper $userMapMapper,
		private EventLogger $eventLogger,
	) {
	}

	public function getType(): string {
		return self::TYPE;
	}

	public function syncRun(MigrationRun $run, RemoteInstance $instance, int $deadline): void {
		foreach ($this->userMapMapper->findByRun($run->getId()) as $userMap) {
			if ($userMap->getState() === UserMap::STATE_FAILED) {
				continue;
			}
			if (time() >= $deadline) {
				return;
			}

			$existing = $this->resourceItemMapper->findOne($run->getId(), $userMap->getId(), self::TYPE, self::EXTERNAL_ID_PROFILE);
			if ($existing !== null && $existing->getState() !== MigrationResourceItem::STATE_PENDING) {
				// Already synced or permanently failed for this user.
				continue;
			}

			$this->processUser($run, $instance, $userMap, $existing);
		}
	}

	public function isRunComplete(int $runId): bool {
		foreach ($this->userMapMapper->findByRun($runId) as $userMap) {
			if ($userMap->getState() === UserMap::STATE_FAILED) {
				continue;
			}
			$item = $this->resourceItemMapper->findOne($runId, $userMap->getId(), self::TYPE, self::EXTERNAL_ID_PROFILE);
			if ($item === null || $item->getState() === MigrationResourceItem::STATE_PENDING) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return array{displayname:string, email:string, quota:string, language:string, groups:string[]}|null
	 *         null if the source user no longer exists.
	 */
	private function discoverProfile(string $sourceUserId): ?array {
		$user = $this->userManager->get($sourceUserId);
		if ($user === null) {
			return null;
		}

		return [
			'displayname' => $user->getDisplayName(),
			'email' => (string)($user->getEMailAddress() ?? ''),
			'quota' => $user->getQuota(),
			'language' => $this->config->getUserValue($sourceUserId, 'core', 'lang', ''),
			'groups' => array_values($this->groupManager->getUserGroupIds($user)),
		];
	}

	private function processUser(MigrationRun $run, RemoteInstance $instance, UserMap $userMap, ?MigrationResourceItem $item): void {
		$now = time();

		if ($item === null) {
			$profile = $this->discoverProfile($userMap->getSourceUserId());

			$item = new MigrationResourceItem();
			$item->setRunId($run->getId());
			$item->setUserMapId($userMap->getId());
			$item->setResourceType(self::TYPE);
			$item->setExternalId(self::EXTERNAL_ID_PROFILE);
			$item->setAttempts(0);
			$item->setCreatedAt($now);
			$item->setUpdatedAt($now);

			if ($profile === null) {
				// Nothing to sync and nothing that will ever change this on
				// its own - persist a terminal failure so isRunComplete()
				// converges instead of retrying this user forever.
				$item->setState(MigrationResourceItem::STATE_FAILED);
				$item->setAttempts(1);
				$item->setLastError("Source user '{$userMap->getSourceUserId()}' no longer exists");
				$item->setPayload(null);
				$this->resourceItemMapper->insert($item);

				$this->eventLogger->log($run->getId(), 'user_info_sync_failed', $item->getLastError(), 'warning');

				return;
			}

			$item->setState(MigrationResourceItem::STATE_PENDING);
			$item->setPayload(json_encode($profile));
			$item = $this->resourceItemMapper->insert($item);
		}

		$payload = json_decode($item->getPayload() ?? '[]', true);
		if (!is_array($payload)) {
			$payload = [];
		}

		$targetUserId = $userMap->getTargetUserId();

		try {
			$adminPassword = $this->credentialService->decrypt($instance->getAdminAppPasswordEncrypted());

			foreach (['displayname', 'email', 'language'] as $field) {
				if (!empty($payload[$field])) {
					$this->provisioningClient->editUserField($instance, $instance->getAdminUserId(), $adminPassword, $targetUserId, $field, (string)$payload[$field]);
				}
			}
			if (!empty($payload['quota'])) {
				$this->provisioningClient->editUserField($instance, $instance->getAdminUserId(), $adminPassword, $targetUserId, 'quota', (string)$payload['quota']);
			}
			foreach ($payload['groups'] ?? [] as $groupId) {
				$this->provisioningClient->ensureGroupExists($instance, $instance->getAdminUserId(), $adminPassword, (string)$groupId);
				$this->provisioningClient->addUserToGroup($instance, $instance->getAdminUserId(), $adminPassword, $targetUserId, (string)$groupId);
			}

			$verified = $this->verifyProfile($instance, $adminPassword, $targetUserId, $payload);

			$item->setState($verified ? MigrationResourceItem::STATE_SYNCED : MigrationResourceItem::STATE_FAILED);
			$item->setTargetRef($targetUserId);
			$item->setLastError($verified ? null : 'Post-sync verification found mismatched profile fields on the target');
			$item->setAttempts($item->getAttempts() + 1);
			$item->setUpdatedAt(time());
			$this->resourceItemMapper->update($item);

			$this->eventLogger->log(
				$run->getId(),
				$verified ? 'user_info_synced' : 'user_info_verify_failed',
				"User info sync for '{$userMap->getSourceUserId()}' -> '{$targetUserId}'" . ($verified ? ' completed' : ' completed but verification found mismatches'),
				$verified ? 'info' : 'warning',
			);
		} catch (\Throwable $e) {
			$item->setState(MigrationResourceItem::STATE_FAILED);
			$item->setAttempts($item->getAttempts() + 1);
			$item->setLastError($e->getMessage());
			$item->setUpdatedAt(time());
			$this->resourceItemMapper->update($item);

			$this->eventLogger->log($run->getId(), 'user_info_sync_failed', "User info sync failed for '{$userMap->getSourceUserId()}': {$e->getMessage()}", 'error');
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function verifyProfile(RemoteInstance $instance, string $adminPassword, string $targetUserId, array $payload): bool {
		$remote = $this->provisioningClient->getUser($instance, $instance->getAdminUserId(), $adminPassword, $targetUserId);

		foreach (['displayname', 'email', 'language'] as $field) {
			$expected = $payload[$field] ?? '';
			if ($expected !== '' && (string)($remote[$field] ?? '') !== (string)$expected) {
				return false;
			}
		}

		foreach ($payload['groups'] ?? [] as $group) {
			if (!in_array((string)$group, $remote['groups'] ?? [], true)) {
				return false;
			}
		}

		return true;
	}
}
