<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method int getInstanceId()
 * @method void setInstanceId(int $instanceId)
 * @method string getState()
 * @method void setState(string $state)
 * @method string getCollisionStrategy()
 * @method void setCollisionStrategy(string $collisionStrategy)
 * @method int getTotalUsers()
 * @method void setTotalUsers(int $totalUsers)
 * @method int getTotalFiles()
 * @method void setTotalFiles(int $totalFiles)
 * @method int getTransferredFiles()
 * @method void setTransferredFiles(int $transferredFiles)
 * @method int getVerifiedFiles()
 * @method void setVerifiedFiles(int $verifiedFiles)
 * @method int getFailedFiles()
 * @method void setFailedFiles(int $failedFiles)
 * @method int getTotalBytes()
 * @method void setTotalBytes(int $totalBytes)
 * @method int getTransferredBytes()
 * @method void setTransferredBytes(int $transferredBytes)
 * @method string|null getDryRunReport()
 * @method void setDryRunReport(?string $dryRunReport)
 * @method string|null getFinalReport()
 * @method void setFinalReport(?string $finalReport)
 * @method string|null getErrorMessage()
 * @method void setErrorMessage(?string $errorMessage)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method string|null getApprovedBy()
 * @method void setApprovedBy(?string $approvedBy)
 * @method int|null getApprovedAt()
 * @method void setApprovedAt(?int $approvedAt)
 * @method int|null getStartedAt()
 * @method void setStartedAt(?int $startedAt)
 * @method int|null getFinishedAt()
 * @method void setFinishedAt(?int $finishedAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class MigrationRun extends Entity {
	// Run lifecycle states (see RunOrchestrator::TRANSITIONS for the graph)
	public const STATE_CREATED = 'created';
	public const STATE_VALIDATING = 'validating';
	public const STATE_VALIDATION_FAILED = 'validation_failed';
	public const STATE_DRY_RUN_READY = 'dry_run_ready';
	public const STATE_APPROVED = 'approved';
	public const STATE_DISCOVERING = 'discovering';
	public const STATE_TRANSFERRING = 'transferring';
	public const STATE_VERIFYING = 'verifying';
	public const STATE_FINALIZING = 'finalizing';
	public const STATE_COMPLETED = 'completed';
	public const STATE_COMPLETED_WITH_ERRORS = 'completed_with_errors';
	public const STATE_FAILED = 'failed';
	public const STATE_PAUSED = 'paused';
	public const STATE_CANCELLED = 'cancelled';

	protected $uuid;
	protected $instanceId;
	protected $state;
	protected $collisionStrategy;
	protected $totalUsers;
	protected $totalFiles;
	protected $transferredFiles;
	protected $verifiedFiles;
	protected $failedFiles;
	protected $totalBytes;
	protected $transferredBytes;
	protected $dryRunReport;
	protected $finalReport;
	protected $errorMessage;
	protected $createdBy;
	protected $approvedBy;
	protected $approvedAt;
	protected $startedAt;
	protected $finishedAt;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('instanceId', 'integer');
		$this->addType('totalUsers', 'integer');
		$this->addType('totalFiles', 'integer');
		$this->addType('transferredFiles', 'integer');
		$this->addType('verifiedFiles', 'integer');
		$this->addType('failedFiles', 'integer');
		$this->addType('totalBytes', 'integer');
		$this->addType('transferredBytes', 'integer');
		$this->addType('approvedAt', 'integer');
		$this->addType('startedAt', 'integer');
		$this->addType('finishedAt', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'uuid' => $this->getUuid(),
			'instanceId' => $this->getInstanceId(),
			'state' => $this->getState(),
			'collisionStrategy' => $this->getCollisionStrategy(),
			'totalUsers' => $this->getTotalUsers(),
			'totalFiles' => $this->getTotalFiles(),
			'transferredFiles' => $this->getTransferredFiles(),
			'verifiedFiles' => $this->getVerifiedFiles(),
			'failedFiles' => $this->getFailedFiles(),
			'totalBytes' => $this->getTotalBytes(),
			'transferredBytes' => $this->getTransferredBytes(),
			'errorMessage' => $this->getErrorMessage(),
			'createdBy' => $this->getCreatedBy(),
			'approvedBy' => $this->getApprovedBy(),
			'approvedAt' => $this->getApprovedAt(),
			'startedAt' => $this->getStartedAt(),
			'finishedAt' => $this->getFinishedAt(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
