<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getRunId()
 * @method void setRunId(int $runId)
 * @method string getSourceUserId()
 * @method void setSourceUserId(string $sourceUserId)
 * @method string getTargetUserId()
 * @method void setTargetUserId(string $targetUserId)
 * @method string getState()
 * @method void setState(string $state)
 * @method int getTotalFiles()
 * @method void setTotalFiles(int $totalFiles)
 * @method int getTransferredFiles()
 * @method void setTransferredFiles(int $transferredFiles)
 * @method int getFailedFiles()
 * @method void setFailedFiles(int $failedFiles)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class UserMap extends Entity implements \JsonSerializable {
	public const STATE_PENDING = 'pending';
	public const STATE_ACTIVE = 'active';
	public const STATE_COMPLETED = 'completed';
	public const STATE_FAILED = 'failed';

	protected $runId;
	protected $sourceUserId;
	protected $targetUserId;
	protected $state;
	protected $totalFiles;
	protected $transferredFiles;
	protected $failedFiles;
	protected $createdAt;

	public function __construct() {
		$this->addType('runId', 'integer');
		$this->addType('totalFiles', 'integer');
		$this->addType('transferredFiles', 'integer');
		$this->addType('failedFiles', 'integer');
		$this->addType('createdAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'runId' => $this->getRunId(),
			'sourceUserId' => $this->getSourceUserId(),
			'targetUserId' => $this->getTargetUserId(),
			'state' => $this->getState(),
			'totalFiles' => $this->getTotalFiles(),
			'transferredFiles' => $this->getTransferredFiles(),
			'failedFiles' => $this->getFailedFiles(),
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
