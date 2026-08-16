<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getRunId()
 * @method void setRunId(int $runId)
 * @method int getUserMapId()
 * @method void setUserMapId(int $userMapId)
 * @method string getResourceType()
 * @method void setResourceType(string $resourceType)
 * @method string getExternalId()
 * @method void setExternalId(string $externalId)
 * @method string getState()
 * @method void setState(string $state)
 * @method int getAttempts()
 * @method void setAttempts(int $attempts)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method string|null getPayload()
 * @method void setPayload(?string $payload)
 * @method string|null getTargetRef()
 * @method void setTargetRef(?string $targetRef)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class MigrationResourceItem extends Entity implements \JsonSerializable {
	// Generic per-item state machine shared by every non-file resource type
	// (user_info, contact, calendar, share - see individual
	// *MigrationService classes for the resource_type string each uses).
	// Deliberately simple compared to MigrationFile's state machine (no
	// separate "transferring"/"verifying" in-progress states): each item
	// here is synced and verified in one inline step rather than being
	// picked up asynchronously by a separate lock-based worker, since
	// these items are orders of magnitude smaller/cheaper than a file
	// transfer. If a later resource type needs real concurrent per-item
	// worker locking (like migrate_files' lock_owner/lock_expires_at), add
	// those columns then rather than speculatively now.
	public const STATE_PENDING = 'pending';
	public const STATE_SYNCED = 'synced';
	public const STATE_FAILED = 'failed';

	// Mirrors MigrationFile::MAX_TRANSFER_ATTEMPTS's reasoning: a single
	// attempt per item, with permanent failures surfaced to the admin
	// rather than retried automatically - most failures here (permission
	// issues, an unmappable share recipient, a vanished source user) won't
	// resolve themselves on an automatic retry within the same run.
	public const MAX_ATTEMPTS = 1;

	protected $runId;
	protected $userMapId;
	protected $resourceType;
	protected $externalId;
	protected $state;
	protected $attempts;
	protected $lastError;
	protected $payload;
	protected $targetRef;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('runId', 'integer');
		$this->addType('userMapId', 'integer');
		$this->addType('attempts', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'runId' => $this->getRunId(),
			'userMapId' => $this->getUserMapId(),
			'resourceType' => $this->getResourceType(),
			'externalId' => $this->getExternalId(),
			'state' => $this->getState(),
			'attempts' => $this->getAttempts(),
			'lastError' => $this->getLastError(),
			'targetRef' => $this->getTargetRef(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
