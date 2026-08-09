<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<MigrationFile>
 */
class MigrationFileMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'migrate_files', MigrationFile::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws Exception
	 */
	public function find(int $id): MigrationFile {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity($qb);
	}

	/**
	 * @throws Exception
	 */
	public function findByRunAndPathHash(int $runId, int $userMapId, string $pathHash): ?MigrationFile {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->eq('user_map_id', $qb->createNamedParameter($userMapId)))
			->andWhere($qb->expr()->eq('source_path_hash', $qb->createNamedParameter($pathHash)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return MigrationFile[]
	 * @throws Exception
	 */
	public function findByRun(int $runId, ?string $state = null, int $limit = 500, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->setMaxResults($limit)
			->setFirstResult($offset)
			->orderBy('id', 'ASC');

		if ($state !== null) {
			$qb->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($state)));
		}

		return $this->findEntities($qb);
	}

	/**
	 * Files currently sitting in any terminal-or-transient failure state
	 * (mapping_failed, transfer_failed, verification_failed), most
	 * recently updated first - used to surface failure reasons (lastError)
	 * to the admin UI. Includes rows that are still within their retry
	 * budget as well as permanently-exhausted ones; the caller can use
	 * transferAttempts/verifyAttempts (and MAX_TRANSFER_ATTEMPTS/
	 * MAX_VERIFY_ATTEMPTS) to tell them apart if needed.
	 *
	 * @return MigrationFile[]
	 * @throws Exception
	 */
	public function findFailed(int $runId, int $limit = 100, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->in('state', $qb->createNamedParameter(
				[MigrationFile::STATE_MAPPING_FAILED, MigrationFile::STATE_TRANSFER_FAILED, MigrationFile::STATE_VERIFICATION_FAILED],
				IQueryBuilder::PARAM_STR_ARRAY
			)))
			->setMaxResults($limit)
			->setFirstResult($offset)
			->orderBy('updated_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Highest source_fileid already recorded for a user within a run (0 if
	 * none yet) - used by DiscoveryService::discoverIncremental() to catch
	 * brand new source files during a re-scan even when their mtime was
	 * preserved from elsewhere (e.g. copied in from a backup) and so isn't
	 * actually recent by clock time; a fileid is assigned once, in a
	 * strictly increasing global sequence, when a filecache row is first
	 * created, so anything higher is guaranteed to be new regardless of
	 * its mtime.
	 *
	 * @throws Exception
	 */
	public function maxSourceFileId(int $runId, int $userMapId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('MAX(source_fileid)'), 'max_fileid')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->eq('user_map_id', $qb->createNamedParameter($userMapId)));

		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();

		return $max !== false && $max !== null ? (int)$max : 0;
	}

	/**
	 * Resets every currently-failed file (mapping_failed, transfer_failed,
	 * verification_failed) for a run back to DISCOVERED with attempt
	 * counters and retry/lock state cleared, so an admin-triggered retry
	 * starts each one over from scratch - including files that had already
	 * exhausted their retry budget, which would otherwise never be picked
	 * up again by findTransferable()/findVerifiable(). A full re-transfer
	 * (not just re-verification) is used even for verification_failed
	 * rows, mirroring VerificationService::recordMismatch()'s own
	 * reasoning: a checksum mismatch means the bytes already on the target
	 * are suspect, so the safest recovery is a full re-upload rather than
	 * re-checking the same (possibly corrupt) remote content.
	 *
	 * @return int number of rows reset
	 * @throws Exception
	 */
	public function resetFailuresForRetry(int $runId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('state', $qb->createNamedParameter(MigrationFile::STATE_DISCOVERED))
			->set('transfer_attempts', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('verify_attempts', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('last_error', $qb->createNamedParameter(null))
			->set('next_retry_at', $qb->createNamedParameter(null))
			->set('lock_owner', $qb->createNamedParameter(null))
			->set('lock_expires_at', $qb->createNamedParameter(null))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->in('state', $qb->createNamedParameter(
				[MigrationFile::STATE_MAPPING_FAILED, MigrationFile::STATE_TRANSFER_FAILED, MigrationFile::STATE_VERIFICATION_FAILED],
				IQueryBuilder::PARAM_STR_ARRAY
			)));

		return $qb->executeStatement();
	}

	/**
	 * Find files eligible for a transfer worker to pick up: not currently
	 * locked (or lock expired) and, if previously failed, past their retry
	 * backoff deadline.
	 *
	 * DISCOVERED rows are included because path mapping/collision-detection
	 * runs inline at the start of the worker's transfer step rather than as
	 * a separate bulk pre-pass (see MappingService + TransferWorkerJob).
	 *
	 * @return MigrationFile[]
	 * @throws Exception
	 */
	public function findTransferable(int $runId, int $now, int $limit, ?int $userMapId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->in('state', $qb->createNamedParameter(
				[MigrationFile::STATE_DISCOVERED, MigrationFile::STATE_TRANSFER_FAILED],
				IQueryBuilder::PARAM_STR_ARRAY
			)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('lock_expires_at'),
				$qb->expr()->lt('lock_expires_at', $qb->createNamedParameter($now))
			))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('next_retry_at'),
				$qb->expr()->lte('next_retry_at', $qb->createNamedParameter($now))
			))
			->andWhere($qb->expr()->lt('transfer_attempts', $qb->createNamedParameter(MigrationFile::MAX_TRANSFER_ATTEMPTS)));
		if ($userMapId !== null) {
			$qb->andWhere($qb->expr()->eq('user_map_id', $qb->createNamedParameter($userMapId)));
		}
		$qb->setMaxResults($limit)
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @return MigrationFile[]
	 * @throws Exception
	 */
	public function findVerifiable(int $runId, int $now, int $limit, ?int $userMapId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->in('state', $qb->createNamedParameter(
				[MigrationFile::STATE_TRANSFERRED, MigrationFile::STATE_VERIFICATION_FAILED],
				IQueryBuilder::PARAM_STR_ARRAY
			)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('lock_expires_at'),
				$qb->expr()->lt('lock_expires_at', $qb->createNamedParameter($now))
			))
			->andWhere($qb->expr()->lt('verify_attempts', $qb->createNamedParameter(MigrationFile::MAX_VERIFY_ATTEMPTS)));
		if ($userMapId !== null) {
			$qb->andWhere($qb->expr()->eq('user_map_id', $qb->createNamedParameter($userMapId)));
		}
		$qb->setMaxResults($limit)
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @return array<string,int> counts keyed by state
	 * @throws Exception
	 */
	public function countByState(int $runId, ?int $userMapId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('state')
			->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)));
		if ($userMapId !== null) {
			$qb->andWhere($qb->expr()->eq('user_map_id', $qb->createNamedParameter($userMapId)));
		}
		$qb->groupBy('state');

		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$counts[$row['state']] = (int)$row['cnt'];
		}
		$result->closeCursor();

		return $counts;
	}

	/**
	 * Live per-user file/byte counts for a run, grouped by user_map_id -
	 * unlike UserMap's own totalFiles/transferredFiles/failedFiles columns
	 * (only ever set once at discovery time and never updated afterwards),
	 * this reflects current state on every call. Mirrors
	 * RunOrchestrator::refreshRunCounters()'s "transferred-or-later"
	 * definition of "transferred". Used to populate the admin UI's
	 * per-user progress table.
	 *
	 * @return array<int, array{totalFiles:int, totalBytes:int, transferredFiles:int, transferredBytes:int, failedFiles:int}>
	 * @throws Exception
	 */
	public function statsByUser(int $runId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_map_id', 'state')
			->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->selectAlias($qb->createFunction('SUM(size)'), 'bytes')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->groupBy('user_map_id', 'state');

		$transferredOrLater = [
			MigrationFile::STATE_TRANSFERRED,
			MigrationFile::STATE_VERIFYING,
			MigrationFile::STATE_VERIFIED,
			MigrationFile::STATE_VERIFICATION_FAILED,
		];
		$failedStates = [
			MigrationFile::STATE_TRANSFER_FAILED,
			MigrationFile::STATE_VERIFICATION_FAILED,
			MigrationFile::STATE_MAPPING_FAILED,
		];

		$result = $qb->executeQuery();
		$stats = [];
		while ($row = $result->fetch()) {
			$userMapId = (int)$row['user_map_id'];
			$state = $row['state'];
			$cnt = (int)$row['cnt'];
			$bytes = $row['bytes'] !== null ? (int)$row['bytes'] : 0;

			$stats[$userMapId] ??= ['totalFiles' => 0, 'totalBytes' => 0, 'transferredFiles' => 0, 'transferredBytes' => 0, 'failedFiles' => 0];
			$stats[$userMapId]['totalFiles'] += $cnt;
			$stats[$userMapId]['totalBytes'] += $bytes;
			if (in_array($state, $transferredOrLater, true)) {
				$stats[$userMapId]['transferredFiles'] += $cnt;
				$stats[$userMapId]['transferredBytes'] += $bytes;
			}
			if (in_array($state, $failedStates, true)) {
				$stats[$userMapId]['failedFiles'] += $cnt;
			}
		}
		$result->closeCursor();

		return $stats;
	}

	/**
	 * Counts of TRANSFER_FAILED/VERIFICATION_FAILED rows that are still
	 * within their retry budget (transfer_attempts < MAX_TRANSFER_ATTEMPTS /
	 * verify_attempts < MAX_VERIFY_ATTEMPTS) - i.e. still eligible to be
	 * picked up again by findTransferable()/findVerifiable(). Rows that
	 * have exhausted their retry budget are permanently stuck in these
	 * states and must NOT be treated as "still pending work" (see
	 * RunOrchestrator::anyUserStillTransferring()/anyUserStillVerifying()/
	 * resumeRun()) or "still in progress" (see
	 * StatusController::calculateProgressPercent()) - they will never
	 * change state again on their own.
	 *
	 * @return array{transferRetryable:int, verificationRetryable:int}
	 * @throws Exception
	 */
	public function countRetryableFailures(int $runId, ?int $userMapId = null): array {
		$transferQb = $this->db->getQueryBuilder();
		$transferQb->selectAlias($transferQb->createFunction('COUNT(*)'), 'cnt')
			->from($this->getTableName())
			->where($transferQb->expr()->eq('run_id', $transferQb->createNamedParameter($runId)))
			->andWhere($transferQb->expr()->eq('state', $transferQb->createNamedParameter(MigrationFile::STATE_TRANSFER_FAILED)))
			->andWhere($transferQb->expr()->lt('transfer_attempts', $transferQb->createNamedParameter(MigrationFile::MAX_TRANSFER_ATTEMPTS)));
		if ($userMapId !== null) {
			$transferQb->andWhere($transferQb->expr()->eq('user_map_id', $transferQb->createNamedParameter($userMapId)));
		}
		$transferResult = $transferQb->executeQuery();
		$transferRetryable = (int)$transferResult->fetchOne();
		$transferResult->closeCursor();

		$verifyQb = $this->db->getQueryBuilder();
		$verifyQb->selectAlias($verifyQb->createFunction('COUNT(*)'), 'cnt')
			->from($this->getTableName())
			->where($verifyQb->expr()->eq('run_id', $verifyQb->createNamedParameter($runId)))
			->andWhere($verifyQb->expr()->eq('state', $verifyQb->createNamedParameter(MigrationFile::STATE_VERIFICATION_FAILED)))
			->andWhere($verifyQb->expr()->lt('verify_attempts', $verifyQb->createNamedParameter(MigrationFile::MAX_VERIFY_ATTEMPTS)));
		if ($userMapId !== null) {
			$verifyQb->andWhere($verifyQb->expr()->eq('user_map_id', $verifyQb->createNamedParameter($userMapId)));
		}
		$verifyResult = $verifyQb->executeQuery();
		$verificationRetryable = (int)$verifyResult->fetchOne();
		$verifyResult->closeCursor();

		return ['transferRetryable' => $transferRetryable, 'verificationRetryable' => $verificationRetryable];
	}

	/**
	 * Sums the size of every non-directory row for a run, regardless of
	 * state. Used to populate migration_runs.total_bytes once discovery
	 * completes.
	 *
	 * @throws Exception
	 */
	public function sumDiscoveredBytes(int $runId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('SUM(size)'), 'total')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->eq('is_directory', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));

		$result = $qb->executeQuery();
		$total = $result->fetchOne();
		$result->closeCursor();

		return $total !== false ? (int)$total : 0;
	}

	/**
	 * Atomically claim a file for processing by a worker, guarding against
	 * concurrent workers picking up the same row. Also flips the row into
	 * the given "in progress" state so it becomes invisible to
	 * findTransferable()/findVerifiable() until the worker finishes (or the
	 * lock expires and CleanupLocksJob reclaims it).
	 *
	 * @throws Exception
	 */
	public function tryAcquireLock(int $fileId, string $ownerToken, int $lockTtlSeconds, string $inProgressState): bool {
		$now = time();
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('lock_owner', $qb->createNamedParameter($ownerToken))
			->set('lock_expires_at', $qb->createNamedParameter($now + $lockTtlSeconds))
			->set('state', $qb->createNamedParameter($inProgressState))
			->set('updated_at', $qb->createNamedParameter($now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($fileId)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('lock_expires_at'),
				$qb->expr()->lt('lock_expires_at', $qb->createNamedParameter($now))
			));

		return $qb->executeStatement() === 1;
	}

	/**
	 * Reclaims rows left in an "in progress" state by a worker that crashed
	 * or was killed before it could persist a terminal state, so they become
	 * eligible for retry again. Called periodically by CleanupLocksJob.
	 *
	 * @throws Exception
	 */
	public function reclaimStaleLocks(int $now): int {
		$reclaimed = 0;

		$map = [
			MigrationFile::STATE_TRANSFERRING => MigrationFile::STATE_TRANSFER_FAILED,
			MigrationFile::STATE_VERIFYING => MigrationFile::STATE_VERIFICATION_FAILED,
		];

		foreach ($map as $staleState => $resetState) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('state', $qb->createNamedParameter($resetState))
				->set('lock_owner', $qb->createNamedParameter(null))
				->set('next_retry_at', $qb->createNamedParameter($now))
				->set('updated_at', $qb->createNamedParameter($now))
				->where($qb->expr()->eq('state', $qb->createNamedParameter($staleState)))
				->andWhere($qb->expr()->lt('lock_expires_at', $qb->createNamedParameter($now)));

			$reclaimed += $qb->executeStatement();
		}

		return $reclaimed;
	}

	/**
	 * Inserts many discovered file rows within a single transaction for
	 * throughput on large trees (target scale: up to ~100k files/run).
	 *
	 * @param MigrationFile[] $files
	 * @throws Exception
	 */
	public function insertBatch(array $files): void {
		if (empty($files)) {
			return;
		}

		$this->db->beginTransaction();
		try {
			foreach ($files as $file) {
				$this->insert($file);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * @throws Exception
	 */
	public function deleteByRun(int $runId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)));
		$qb->executeStatement();
	}
}
