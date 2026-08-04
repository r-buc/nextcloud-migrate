<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service;

use OCA\NextcloudMigrate\Db\MigrationEvent;
use OCA\NextcloudMigrate\Db\MigrationEventMapper;
use Psr\Log\LoggerInterface;

/**
 * Writes to the append-only migrate_events audit trail and mirrors to the
 * Nextcloud server log for operators tailing logs directly.
 */
class EventLogger {
	public function __construct(
		private MigrationEventMapper $eventMapper,
		private LoggerInterface $logger,
	) {
	}

	public function log(
		?int $runId,
		string $eventType,
		string $message,
		string $severity = MigrationEvent::SEVERITY_INFO,
		?int $fileId = null,
		?array $context = null,
	): void {
		$event = new MigrationEvent();
		$event->setRunId($runId);
		$event->setFileId($fileId);
		$event->setEventType($eventType);
		$event->setSeverity($severity);
		$event->setMessage($message);
		$event->setContext($context !== null ? json_encode($context) : null);
		$event->setCreatedAt(time());
		$this->eventMapper->insert($event);

		$logLevel = match ($severity) {
			MigrationEvent::SEVERITY_DEBUG => 'debug',
			MigrationEvent::SEVERITY_WARNING => 'warning',
			MigrationEvent::SEVERITY_ERROR, MigrationEvent::SEVERITY_CRITICAL => 'error',
			default => 'info',
		};
		$this->logger->log($logLevel, "[nextcloud_migrate] {$eventType}: {$message}", [
			'app' => 'nextcloud_migrate',
			'runId' => $runId,
			'fileId' => $fileId,
			'context' => $context,
		]);
	}
}
