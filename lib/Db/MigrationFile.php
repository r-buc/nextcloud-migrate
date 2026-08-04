<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getRunId()
 * @method void setRunId(int $runId)
 * @method int getUserMapId()
 * @method void setUserMapId(int $userMapId)
 * @method string getSourcePath()
 * @method void setSourcePath(string $sourcePath)
 * @method string getSourcePathHash()
 * @method void setSourcePathHash(string $sourcePathHash)
 * @method string|null getTargetPath()
 * @method void setTargetPath(?string $targetPath)
 * @method int|null getSourceFileid()
 * @method void setSourceFileid(?int $sourceFileid)
 * @method bool getIsDirectory()
 * @method void setIsDirectory(bool $isDirectory)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method int|null getMtime()
 * @method void setMtime(?int $mtime)
 * @method string|null getMimetype()
 * @method void setMimetype(?string $mimetype)
 * @method string|null getSourceChecksum()
 * @method void setSourceChecksum(?string $sourceChecksum)
 * @method string|null getTargetChecksum()
 * @method void setTargetChecksum(?string $targetChecksum)
 * @method string getState()
 * @method void setState(string $state)
 * @method int getTransferAttempts()
 * @method void setTransferAttempts(int $transferAttempts)
 * @method int getVerifyAttempts()
 * @method void setVerifyAttempts(int $verifyAttempts)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method int getBytesTransferred()
 * @method void setBytesTransferred(int $bytesTransferred)
 * @method string|null getTransferId()
 * @method void setTransferId(?string $transferId)
 * @method int getNextChunkIndex()
 * @method void setNextChunkIndex(int $nextChunkIndex)
 * @method string|null getLockOwner()
 * @method void setLockOwner(?string $lockOwner)
 * @method int|null getLockExpiresAt()
 * @method void setLockExpiresAt(?int $lockExpiresAt)
 * @method int|null getNextRetryAt()
 * @method void setNextRetryAt(?int $nextRetryAt)
 * @method int|null getTransferredAt()
 * @method void setTransferredAt(?int $transferredAt)
 * @method int|null getVerifiedAt()
 * @method void setVerifiedAt(?int $verifiedAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class MigrationFile extends Entity {
	// File-level state machine (see architecture notes: DISCOVERED -> MAPPED ->
	// TRANSFERRING (+retries) -> TRANSFERRED -> VERIFYING (+retries) -> VERIFIED)
	public const STATE_DISCOVERED = 'discovered';
	public const STATE_MAPPED = 'mapped';
	public const STATE_MAPPING_FAILED = 'mapping_failed';
	public const STATE_SKIPPED = 'skipped';
	public const STATE_TRANSFERRING = 'transferring';
	public const STATE_TRANSFERRED = 'transferred';
	public const STATE_TRANSFER_FAILED = 'transfer_failed';
	public const STATE_VERIFYING = 'verifying';
	public const STATE_VERIFIED = 'verified';
	public const STATE_VERIFICATION_FAILED = 'verification_failed';
	public const STATE_COMPLETED = 'completed';

	public const MAX_TRANSFER_ATTEMPTS = 3;
	public const MAX_VERIFY_ATTEMPTS = 2;

	// Files at or above this size use the NG chunked upload protocol (same
	// wire protocol as the official Nextcloud desktop/mobile clients) so a
	// worker crash mid-upload can resume from the next chunk instead of
	// restarting the whole file.
	public const CHUNKED_UPLOAD_THRESHOLD_BYTES = 50 * 1024 * 1024;
	public const CHUNK_SIZE_BYTES = 10 * 1024 * 1024;

	protected $runId;
	protected $userMapId;
	protected $sourcePath;
	protected $sourcePathHash;
	protected $targetPath;
	protected $sourceFileid;
	protected $isDirectory;
	protected $size;
	protected $mtime;
	protected $mimetype;
	protected $sourceChecksum;
	protected $targetChecksum;
	protected $state;
	protected $transferAttempts;
	protected $verifyAttempts;
	protected $lastError;
	protected $bytesTransferred;
	protected $transferId;
	protected $nextChunkIndex;
	protected $lockOwner;
	protected $lockExpiresAt;
	protected $nextRetryAt;
	protected $transferredAt;
	protected $verifiedAt;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('runId', 'integer');
		$this->addType('userMapId', 'integer');
		$this->addType('sourceFileid', 'integer');
		$this->addType('isDirectory', 'boolean');
		$this->addType('size', 'integer');
		$this->addType('mtime', 'integer');
		$this->addType('transferAttempts', 'integer');
		$this->addType('verifyAttempts', 'integer');
		$this->addType('bytesTransferred', 'integer');
		$this->addType('nextChunkIndex', 'integer');
		$this->addType('lockExpiresAt', 'integer');
		$this->addType('nextRetryAt', 'integer');
		$this->addType('transferredAt', 'integer');
		$this->addType('verifiedAt', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}
}
