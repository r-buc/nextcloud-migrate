<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds migrate_runs.last_sync_at: informational timestamp of the most
 * recent continuous-sync discovery pass (see RunOrchestrator::runSyncPass(),
 * MigrationRun::STATE_SYNCING). Purely for admin UI display ("last synced
 * at ...") - SyncDiscoveryJob itself re-scans every SYNCING run on every
 * tick regardless of this value, so nothing depends on it for correctness.
 */
class Version000100Date20260808000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('migrate_runs');
		if (!$table->hasColumn('last_sync_at')) {
			$table->addColumn('last_sync_at', Types::BIGINT, ['notnull' => false]);
		}

		return $schema;
	}
}
