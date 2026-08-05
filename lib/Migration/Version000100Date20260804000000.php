<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial schema for the Nextcloud Migrate app.
 *
 * Tables:
 *  - migrate_instances : connection settings (URL, TLS policy) for a remote
 *                        (target) instance, plus a remote ADMIN credential
 *                        used ONLY for the OCS Provisioning API (listing
 *                        users, resetting a user's password as part of the
 *                        default "auto" mapping mode). It is never used for
 *                        WebDAV file writes: Nextcloud's DAV backend has no
 *                        admin-bypass for other users' files, so all actual
 *                        file transfer authenticates as the specific user
 *                        in migrate_user_map.
 *  - migrate_runs      : one migration run (source instance -> one target instance)
 *  - migrate_user_map  : source user -> target user mapping within a run,
 *                        including that target user's own encrypted app
 *                        password (either admin-reset or manually supplied)
 *  - migrate_files     : per-file discovery/transfer/verification state
 *  - migrate_events    : append-only audit log for a run
 */
class Version000100Date20260804000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('migrate_instances')) {
			$table = $schema->createTable('migrate_instances');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('label', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('url', Types::STRING, ['notnull' => true, 'length' => 1024]);
			$table->addColumn('admin_user_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('admin_app_password_encrypted', Types::TEXT, ['notnull' => true]);
			// Not notnull+default: Nextcloud's schema validator rejects a
			// literal boolean default combined with notnull (Oracle
			// portability guard). The app always calls setAllowSelfSigned()
			// explicitly before insert, so no DB-level default is needed.
			$table->addColumn('allow_self_signed', Types::BOOLEAN, ['notnull' => false]);
			$table->addColumn('last_tested_at', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('last_test_error', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['uuid'], 'migrate_inst_uuid_idx');
			$table->addIndex(['created_by'], 'migrate_inst_owner_idx');
		}

		if (!$schema->hasTable('migrate_runs')) {
			$table = $schema->createTable('migrate_runs');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('instance_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'created']);
			$table->addColumn('collision_strategy', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'rename']);
			$table->addColumn('total_users', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('total_files', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('transferred_files', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('verified_files', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('failed_files', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('total_bytes', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('transferred_bytes', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('dry_run_report', Types::TEXT, ['notnull' => false]);
			$table->addColumn('final_report', Types::TEXT, ['notnull' => false]);
			$table->addColumn('error_message', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('approved_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('approved_at', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('started_at', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('finished_at', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['uuid'], 'migrate_run_uuid_idx');
			$table->addIndex(['state'], 'migrate_run_state_idx');
			$table->addIndex(['instance_id'], 'migrate_run_instance_idx');
		}

		if (!$schema->hasTable('migrate_user_map')) {
			$table = $schema->createTable('migrate_user_map');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('run_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('source_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('target_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			// Each mapped user has their OWN target app password: Nextcloud's
			// WebDAV auth backend rewrites the DAV principal to whichever user
			// the request authenticates as, so a single shared/admin
			// credential cannot write into a different user's files - there is
			// no admin bypass over WebDAV (verified against server source).
			$table->addColumn('target_app_password_encrypted', Types::TEXT, ['notnull' => false]);
			$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'pending']);
			$table->addColumn('total_files', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('transferred_files', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('failed_files', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['run_id'], 'migrate_umap_run_idx');
			$table->addUniqueIndex(['run_id', 'source_user_id'], 'migrate_umap_unique_idx');
		}

		if (!$schema->hasTable('migrate_files')) {
			$table = $schema->createTable('migrate_files');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('run_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('user_map_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('source_path', Types::TEXT, ['notnull' => true]);
			$table->addColumn('source_path_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('target_path', Types::TEXT, ['notnull' => false]);
			$table->addColumn('source_fileid', Types::BIGINT, ['notnull' => false]);
			// See allow_self_signed comment above re: notnull+default=false.
			// DiscoveryService always calls setIsDirectory() explicitly.
			$table->addColumn('is_directory', Types::BOOLEAN, ['notnull' => false]);
			$table->addColumn('size', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('mtime', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('mimetype', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('source_checksum', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('target_checksum', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'discovered']);
			$table->addColumn('transfer_attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('verify_attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('last_error', Types::TEXT, ['notnull' => false]);
			$table->addColumn('bytes_transferred', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('transfer_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('next_chunk_index', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('lock_owner', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('lock_expires_at', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('next_retry_at', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('transferred_at', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('verified_at', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['run_id', 'state'], 'migrate_file_run_state_idx');
			$table->addIndex(['user_map_id'], 'migrate_file_umap_idx');
			$table->addIndex(['next_retry_at'], 'migrate_file_retry_idx');
			// Scoped per user_map, not just run: different source users in the
			// same run commonly share identical relative paths (e.g. everyone
			// has a "Documents" folder), which would otherwise collide.
			$table->addUniqueIndex(['run_id', 'user_map_id', 'source_path_hash'], 'migrate_file_unique_idx');
		}

		if (!$schema->hasTable('migrate_events')) {
			$table = $schema->createTable('migrate_events');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('run_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('event_type', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('severity', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'info']);
			$table->addColumn('message', Types::TEXT, ['notnull' => true]);
			$table->addColumn('context', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['run_id', 'created_at'], 'migrate_event_run_idx');
		}

		return $schema;
	}
}
