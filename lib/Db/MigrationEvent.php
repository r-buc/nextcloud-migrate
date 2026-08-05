<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int|null getRunId()
 * @method void setRunId(?int $runId)
 * @method int|null getFileId()
 * @method void setFileId(?int $fileId)
 * @method string getEventType()
 * @method void setEventType(string $eventType)
 * @method string getSeverity()
 * @method void setSeverity(string $severity)
 * @method string getMessage()
 * @method void setMessage(string $message)
 * @method string|null getContext()
 * @method void setContext(?string $context)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class MigrationEvent extends Entity implements \JsonSerializable {
	public const SEVERITY_DEBUG = 'debug';
	public const SEVERITY_INFO = 'info';
	public const SEVERITY_WARNING = 'warning';
	public const SEVERITY_ERROR = 'error';
	public const SEVERITY_CRITICAL = 'critical';

	protected $runId;
	protected $fileId;
	protected $eventType;
	protected $severity;
	protected $message;
	protected $context;
	protected $createdAt;

	public function __construct() {
		$this->addType('runId', 'integer');
		$this->addType('fileId', 'integer');
		$this->addType('createdAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'runId' => $this->getRunId(),
			'fileId' => $this->getFileId(),
			'eventType' => $this->getEventType(),
			'severity' => $this->getSeverity(),
			'message' => $this->getMessage(),
			'context' => $this->getContext() !== null ? json_decode($this->getContext(), true) : null,
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
