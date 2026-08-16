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
 * @method bool getSkipVerification()
 * @method void setSkipVerification(bool $skipVerification)
 * @method bool getMigrateUserInfo()
 * @method void setMigrateUserInfo(bool $migrateUserInfo)
 * @method bool getMigrateContacts()
 * @method void setMigrateContacts(bool $migrateContacts)
 * @method bool getMigrateCalendars()
 * @method void setMigrateCalendars(bool $migrateCalendars)
 * @method bool getMigrateShares()
 * @method void setMigrateShares(bool $migrateShares)
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
 * @method int|null getLastSyncAt()
 * @method void setLastSyncAt(?int $lastSyncAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class MigrationRun extends Entity implements \JsonSerializable {
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
	// Opt-in steady state entered from COMPLETED/COMPLETED_WITH_ERRORS via
	// RunOrchestrator::startSyncing() once the initial transfer+verification
	// pass has finished, so the source and target can be kept up to date
	// while users are gradually switched over to the new instance. Only
	// available for runs using the 'overwrite_newer' collision strategy -
	// see MappingService::STRATEGY_OVERWRITE_IF_NEWER - since that's the
	// only strategy where re-syncing a changed, already-migrated file does
	// something sensible (skip/rename would either never update it or pile
	// up duplicates every cycle). Left via RunOrchestrator::stopSyncing(),
	// which re-evaluates failures and settles back into COMPLETED or
	// COMPLETED_WITH_ERRORS, same as finalizeRun() does for the initial pass.
	public const STATE_SYNCING = 'syncing';

	protected $uuid;
	protected $instanceId;
	protected $state;
	protected $collisionStrategy;
	protected $skipVerification;
	protected $migrateUserInfo;
	protected $migrateContacts;
	protected $migrateCalendars;
	protected $migrateShares;
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
	protected $lastSyncAt;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('instanceId', 'integer');
		$this->addType('skipVerification', 'boolean');
		$this->addType('migrateUserInfo', 'boolean');
		$this->addType('migrateContacts', 'boolean');
		$this->addType('migrateCalendars', 'boolean');
		$this->addType('migrateShares', 'boolean');
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
		$this->addType('lastSyncAt', 'integer');
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
			'skipVerification' => $this->getSkipVerification(),
			'migrateUserInfo' => $this->getMigrateUserInfo(),
			'migrateContacts' => $this->getMigrateContacts(),
			'migrateCalendars' => $this->getMigrateCalendars(),
			'migrateShares' => $this->getMigrateShares(),
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
			'lastSyncAt' => $this->getLastSyncAt(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
