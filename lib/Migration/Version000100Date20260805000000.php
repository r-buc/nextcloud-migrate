<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds migrate_runs.skip_verification: an opt-in per-run flag letting an
 * admin skip the separate post-transfer verification phase (re-downloading
 * every file from the target and comparing checksums) and rely solely on
 * the target's upload-time OC-Checksum validation instead (Nextcloud's DAV
 * server already rejects a PUT/chunked-upload MOVE if the received bytes
 * don't match the OC-Checksum header this app always sends - see
 * WebDavClient::putFile()/assembleChunkedUpload()). Verification remains
 * the default (column defaults to not-set/false) since it also catches
 * narrower cases upload-time validation cannot, such as post-write storage
 * corruption on the target or the file becoming unreadable afterwards.
 */
class Version000100Date20260805000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('migrate_runs');
		if (!$table->hasColumn('skip_verification')) {
			// Not notnull+default: Nextcloud's schema validator rejects a
			// literal boolean default combined with notnull (Oracle
			// portability guard) - same pattern as allow_self_signed/
			// is_directory elsewhere in this app. RunOrchestrator::createRun()
			// always calls setSkipVerification() explicitly before insert.
			$table->addColumn('skip_verification', Types::BOOLEAN, ['notnull' => false]);
		}

		return $schema;
	}
}
