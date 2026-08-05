<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Connection settings for the remote (target) instance: URL, TLS policy,
 * and a remote ADMIN credential (adminUserId + encrypted app password).
 *
 * That admin credential is used ONLY for the OCS Provisioning API - listing
 * remote users, creating a target user account, or resetting a target
 * user's password (the default "auto" mapping mode; see RunOrchestrator).
 * It is NEVER used for WebDAV file writes: Nextcloud's WebDAV auth backend
 * rewrites the DAV principal to whichever user actually authenticates, so
 * there is no admin-bypass for writing into a different user's files -
 * every file transfer authenticates as that specific mapped user's own app
 * password, stored on UserMap instead.
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method string|null getLabel()
 * @method void setLabel(?string $label)
 * @method string getUrl()
 * @method void setUrl(string $url)
 * @method string getAdminUserId()
 * @method void setAdminUserId(string $adminUserId)
 * @method string getAdminAppPasswordEncrypted()
 * @method void setAdminAppPasswordEncrypted(string $adminAppPasswordEncrypted)
 * @method bool getAllowSelfSigned()
 * @method void setAllowSelfSigned(bool $allowSelfSigned)
 * @method int|null getLastTestedAt()
 * @method void setLastTestedAt(?int $lastTestedAt)
 * @method string|null getLastTestError()
 * @method void setLastTestError(?string $lastTestError)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class RemoteInstance extends Entity implements \JsonSerializable {
	protected $uuid;
	protected $label;
	protected $url;
	protected $adminUserId;
	protected $adminAppPasswordEncrypted;
	protected $allowSelfSigned;
	protected $lastTestedAt;
	protected $lastTestError;
	protected $createdBy;
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('allowSelfSigned', 'boolean');
		$this->addType('lastTestedAt', 'integer');
		$this->addType('createdAt', 'integer');
	}

	/**
	 * Redacted array for API responses; never includes the encrypted secret.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'uuid' => $this->getUuid(),
			'label' => $this->getLabel(),
			'url' => $this->getUrl(),
			'adminUserId' => $this->getAdminUserId(),
			'allowSelfSigned' => $this->getAllowSelfSigned(),
			'lastTestedAt' => $this->getLastTestedAt(),
			'lastTestError' => $this->getLastTestError(),
			'createdBy' => $this->getCreatedBy(),
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
