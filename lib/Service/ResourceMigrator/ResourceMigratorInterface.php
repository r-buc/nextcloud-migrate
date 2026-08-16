<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service\ResourceMigrator;

use OCA\NextcloudMigrate\Db\MigrationRun;
use OCA\NextcloudMigrate\Db\RemoteInstance;

/**
 * Contract for a pluggable non-file resource type migrator (user info,
 * contacts, calendars, shares - one implementation per type, each backing
 * its own dedicated per-run BackgroundJob). Loosely inspired by
 * nextcloud/user_migration's IMigrator, adapted for this app's live
 * server-to-server model: source data is read directly via local OCP
 * services (this app runs ON the source instance), while target writes go
 * over HTTP to the configured RemoteInstance (OCS/WebDAV/CardDAV/CalDAV/
 * Share API, depending on the resource type) - there is no offline
 * export/import archive.
 *
 * Each implementation is responsible for its own per-item persistence via
 * MigrationResourceItemMapper (see UserInfoMigrationService for the
 * reference implementation): discovering source items, syncing them to the
 * target, and recording per-item state so work already done is never
 * redone and progress survives across multiple job executions.
 */
interface ResourceMigratorInterface {
	/**
	 * The resource_type discriminator this migrator owns in
	 * migrate_resource_items (e.g. 'user_info', 'contact', 'calendar',
	 * 'share').
	 */
	public function getType(): string;

	/**
	 * Processes as much pending work for $run as possible without crossing
	 * $deadline (a unix timestamp - see RunOrchestrator::getBatchSeconds()),
	 * so a caller job can re-enqueue itself and pick up where this left off
	 * if the whole run's work doesn't fit in one execution.
	 */
	public function syncRun(MigrationRun $run, RemoteInstance $instance, int $deadline): void;

	/**
	 * Whether every mapped (non-failed) user in $run has reached a
	 * terminal state (synced or failed) for this resource type.
	 */
	public function isRunComplete(int $runId): bool;
}
