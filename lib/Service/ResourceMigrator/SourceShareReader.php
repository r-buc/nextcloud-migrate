<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service\ResourceMigrator;

use OCP\Share\IManager;
use OCP\Share\IShare;

/**
 * Reads a SOURCE (local) user's own shares directly via Nextcloud's public
 * OCP\Share\IManager - unlike contacts/calendars, the Share API IS a
 * proper OCP interface (no need to reach into an OCA-internal backend
 * class), so this is constructor-injected normally rather than resolved
 * lazily via \OCP\Server::get().
 *
 * Only user/group/link shares are covered (v1 scope) - federated/remote
 * shares are explicitly out of scope. Link-share passwords can never be
 * read back in plaintext (Nextcloud only ever stores/exposes a hash), so
 * a migrated password-protected link share is recreated WITHOUT a
 * password - a known, documented limitation (see SharesMigrationService).
 */
class SourceShareReader {
	private const SHARE_TYPES = [IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_LINK];
	private const PAGE_SIZE = 50;

	public function __construct(
		private IManager $shareManager,
	) {
	}

	/**
	 * @return array<int, array{id:string, shareType:int, path:string, sharedWith:?string, permissions:int, expiration:?int, label:?string}>
	 */
	public function listOwnedShares(string $sourceUserId): array {
		$result = [];
		foreach (self::SHARE_TYPES as $shareType) {
			$offset = 0;
			do {
				$shares = $this->shareManager->getSharesBy($sourceUserId, $shareType, null, false, self::PAGE_SIZE, $offset);
				foreach ($shares as $share) {
					$result[] = $this->toArray($sourceUserId, $share);
				}
				$offset += self::PAGE_SIZE;
			} while (count($shares) === self::PAGE_SIZE);
		}

		return $result;
	}

	/**
	 * @return array{id:string, shareType:int, path:string, sharedWith:?string, permissions:int, expiration:?int, label:?string}
	 */
	private function toArray(string $sourceUserId, IShare $share): array {
		$rawPath = $share->getNode()->getPath();
		$prefix = '/' . $sourceUserId . '/files/';
		$relativePath = str_starts_with($rawPath, $prefix) ? substr($rawPath, strlen($prefix)) : ltrim($rawPath, '/');

		return [
			'id' => (string)$share->getId(),
			'shareType' => $share->getShareType(),
			'path' => $relativePath,
			'sharedWith' => $share->getSharedWith(),
			'permissions' => $share->getPermissions(),
			'expiration' => $share->getExpirationDate()?->getTimestamp(),
			'label' => $share->getLabel(),
		];
	}
}
