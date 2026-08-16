<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the schema groundwork for migrating non-file resource types (user
 * info, contacts, calendars, shares - see lib/Service/ResourceMigrator/):
 *
 *  - migrate_runs gains 4 boolean opt-in toggles (one per resource type),
 *    set at run creation (RunOrchestrator::createRun()) and never implied
 *    by collision_strategy or anything else.
 *  - migrate_resource_items is a single generic table shared by all 4
 *    resource types (discriminated by resource_type), rather than one
 *    bespoke table per type: unlike migrate_files, these items don't need
 *    chunked-transfer/lock-based-concurrent-worker columns, so a single
 *    table with a JSON-ish `payload` blob is enough to cover a vCard, an
 *    iCalendar object, a share's metadata, or a user's profile fields.
 */
class Version000300Date20260815000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$runs = $schema->getTable('migrate_runs');
		foreach (['migrate_user_info', 'migrate_contacts', 'migrate_calendars', 'migrate_shares'] as $column) {
			if (!$runs->hasColumn($column)) {
				// Not notnull+default: see allow_self_signed comment in
				// Version000100Date20260804000000 (Oracle boolean-default
				// portability guard) - RunOrchestrator::createRun() always
				// sets all 4 explicitly before insert.
				$runs->addColumn($column, Types::BOOLEAN, ['notnull' => false]);
			}
		}

		if (!$schema->hasTable('migrate_resource_items')) {
			$table = $schema->createTable('migrate_resource_items');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('run_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('user_map_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('resource_type', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('external_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
			$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('last_error', Types::TEXT, ['notnull' => false]);
			$table->addColumn('payload', Types::TEXT, ['notnull' => false]);
			$table->addColumn('target_ref', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['run_id', 'resource_type', 'state'], 'migrate_res_state_idx');
			$table->addUniqueIndex(['run_id', 'user_map_id', 'resource_type', 'external_id'], 'migrate_res_unique_idx');
		}

		return $schema;
	}
}
