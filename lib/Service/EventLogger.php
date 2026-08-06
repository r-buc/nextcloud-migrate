<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service;

use OCA\NextcloudMigrate\Db\MigrationEvent;
use OCA\NextcloudMigrate\Db\MigrationEventMapper;
use Psr\Log\LoggerInterface;

/**
 * Writes to the append-only migrate_events audit trail (queryable per-run
 * via GET .../events, and per-file via the file's own lastError column) and
 * additionally mirrors RUN-LEVEL events to the Nextcloud server log for
 * operators tailing logs directly. Per-FILE events (fileId set) are NOT
 * mirrored - at migration scale there can be very many of them, they're
 * already fully durable/queryable in the app's own DB, and duplicating each
 * one into the general server log would just add noise there for no
 * additional benefit.
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

		if ($fileId !== null) {
			// File-scoped: already durable via this row + the file's own
			// lastError column, no need to also write to the server log.
			return;
		}

		$context = [
			'app' => 'nextcloud_migrate',
			'runId' => $runId,
			'fileId' => $fileId,
			'context' => $context,
		];
		$logMessage = "[nextcloud_migrate] {$eventType}: {$message}";
		match ($severity) {
			MigrationEvent::SEVERITY_DEBUG => $this->logger->debug($logMessage, $context),
			MigrationEvent::SEVERITY_WARNING => $this->logger->warning($logMessage, $context),
			MigrationEvent::SEVERITY_ERROR, MigrationEvent::SEVERITY_CRITICAL => $this->logger->error($logMessage, $context),
			default => $this->logger->info($logMessage, $context),
		};
	}
}
