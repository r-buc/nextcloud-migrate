<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service;

use OCA\NextcloudMigrate\Db\MigrationEvent;
use OCA\NextcloudMigrate\Db\MigrationFile;
use OCA\NextcloudMigrate\Db\MigrationFileMapper;
use OCA\NextcloudMigrate\Db\RemoteInstance;
use OCA\NextcloudMigrate\Exception\TransferException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;
use OCA\NextcloudMigrate\Util\UuidGenerator;

/**
 * Streams a single discovered file/folder from local storage to the target
 * instance over WebDAV.
 *
 * Resume model (v1):
 *  - Small files (< MigrationFile::CHUNKED_UPLOAD_THRESHOLD_BYTES) use a
 *    single PUT and are retried from scratch on failure (file-level resume).
 *  - Large files use the NG Chunking v2 protocol - the same wire protocol
 *    the official Nextcloud desktop/mobile clients use - so an interrupted
 *    upload resumes from the next missing chunk rather than restarting the
 *    whole file. Chunk presence is reconciled against the target via
 *    WebDavClient::listUploadedChunks() so resume is correct even if a
 *    worker crashed mid-chunk.
 * Already-VERIFIED files are never re-transferred when a run is resumed.
 *
 * Live-source-change handling (v1), mirroring the desktop sync client:
 *  - Before reading, the source node's mtime+size is snapshotted. After the
 *    read completes, the node is re-fetched and compared; a mismatch means
 *    the file was edited while being read (a "torn read" risk), so the
 *    attempt is aborted and retried rather than uploading inconsistent
 *    bytes - the retry will pick up the file's latest stable content.
 *  - This is a point-in-time migration, not a continuous two-way sync: a
 *    file edited only *after* it has already been verified is not detected
 *    or re-migrated automatically. Admins should avoid heavy source writes
 *    during a run (see README "Live source changes" section).
 */
class TransferService {
	// Exponential backoff schedule applied after each failed attempt,
	// indexed by (attempt number - 1). Currently dormant in practice since
	// MigrationFile::MAX_TRANSFER_ATTEMPTS = 1 means the very first failure
	// is already exhausted (see recordFailure() below) - kept in case that
	// limit is ever raised again.
	private const BACKOFF_SECONDS = [1, 5, 30, 300];

	public function __construct(
		private IRootFolder $rootFolder,
		private WebDavClient $webDavClient,
		private MigrationFileMapper $fileMapper,
		private EventLogger $eventLogger,
	) {
	}

	public function transferDirectory(MigrationFile $file, RemoteInstance $instance, string $targetUserId, string $appPassword): void {
		$now = time();

		try {
			$this->webDavClient->makeCollection($instance, $targetUserId, $appPassword, $file->getTargetPath() ?? $file->getSourcePath());
			// Folders have no content to verify; mark verified immediately
			// so VerifyWorkerJob can skip them entirely.
			$file->setState(MigrationFile::STATE_VERIFIED);
			$file->setTransferredAt($now);
			$file->setVerifiedAt($now);
		} catch (\Throwable $e) {
			$this->recordFailure($file, $instance, $targetUserId, $appPassword, $e->getMessage());
		}

		$file->setLockOwner(null);
		$file->setLockExpiresAt(null);
		$file->setUpdatedAt($now);
		$this->fileMapper->update($file);
	}

	public function transferFile(MigrationFile $file, RemoteInstance $instance, string $targetUserId, string $appPassword, string $sourceUserId): void {
		$now = time();

		try {
			$userFolder = $this->rootFolder->getUserFolder($sourceUserId);
			$node = $userFolder->get($file->getSourcePath());
			if (!$node instanceof \OCP\Files\File) {
				throw new TransferException("Source path '{$file->getSourcePath()}' is not a regular file anymore", false);
			}

			if ($node->getSize() >= MigrationFile::CHUNKED_UPLOAD_THRESHOLD_BYTES) {
				$this->transferFileChunked($file, $instance, $targetUserId, $appPassword, $node, $sourceUserId);
			} else {
				$this->transferFileSimple($file, $instance, $targetUserId, $appPassword, $node, $sourceUserId);
			}

			$file->setState(MigrationFile::STATE_TRANSFERRED);
			$file->setTransferredAt($now);
			$file->setLastError(null);
		} catch (NotFoundException $e) {
			// Source file vanished during migration; not retryable.
			$file->setTransferAttempts($file->getTransferAttempts() + 1);
			$file->setState(MigrationFile::STATE_TRANSFER_FAILED);
			$file->setLastError('Source file no longer exists: ' . $e->getMessage());
			$this->eventLogger->log($file->getRunId(), 'source_missing', "Source file '{$file->getSourcePath()}' no longer exists", 'warning', $file->getId());
		} catch (TransferException $e) {
			$this->recordFailure($file, $instance, $targetUserId, $appPassword, $e->getMessage(), $e->isRetryable());
		} catch (\Throwable $e) {
			$this->recordFailure($file, $instance, $targetUserId, $appPassword, $e->getMessage());
		}

		$file->setLockOwner(null);
		$file->setLockExpiresAt(null);
		$file->setUpdatedAt($now);
		$this->fileMapper->update($file);
	}

	/**
	 * @throws TransferException
	 */
	private function transferFileSimple(MigrationFile $file, RemoteInstance $instance, string $targetUserId, string $appPassword, \OCP\Files\File $node, string $sourceUserId): void {
		$sourcePath = $file->getSourcePath();
		$preMtime = $node->getMTime();
		$preSize = $node->getSize();

		$source = $node->fopen('r');
		if ($source === false) {
			throw new TransferException("Could not open source file '{$sourcePath}' for reading", true);
		}

		// Buffer through a spill-to-disk temp stream while hashing, so we
		// upload from a seekable stream and know the exact SHA-256 without
		// reading the source twice (source reads are local/cheap; the
		// buffer avoids holding large files fully in PHP memory).
		$buffer = fopen('php://temp/maxmemory:2097152', 'r+');
		$ctx = hash_init('sha256');
		$bytesRead = 0;
		while (!feof($source)) {
			$chunk = fread($source, 8 * 1024 * 1024);
			if ($chunk === false || $chunk === '') {
				break;
			}
			hash_update($ctx, $chunk);
			fwrite($buffer, $chunk);
			$bytesRead += strlen($chunk);
		}
		fclose($source);
		$sha256 = hash_final($ctx);
		rewind($buffer);

		$this->assertNotChangedDuringRead($file, $instance, $targetUserId, $appPassword, $sourceUserId, $preMtime, $preSize, $bytesRead, true);

		$this->webDavClient->putFile(
			$instance,
			$targetUserId,
			$appPassword,
			$file->getTargetPath() ?? $sourcePath,
			$buffer,
			$bytesRead,
			$preMtime,
			$sha256,
		);
		fclose($buffer);

		$file->setSourceChecksum($sha256);
		$file->setBytesTransferred($bytesRead);
	}

	/**
	 * Uploads a large file using NG Chunking v2: split into fixed-size
	 * chunks, upload each to a staging collection, then MOVE the assembled
	 * result onto the destination path. Reconciles against the server's
	 * view of already-uploaded chunks first, so resuming after a crash only
	 * re-uploads what is actually missing.
	 *
	 * @throws TransferException
	 */
	private function transferFileChunked(MigrationFile $file, RemoteInstance $instance, string $targetUserId, string $appPassword, \OCP\Files\File $node, string $sourceUserId): void {
		$preMtime = $node->getMTime();
		$preSize = $node->getSize();

		if ($file->getTransferId() === null) {
			$file->setTransferId(UuidGenerator::v4());
			$file->setNextChunkIndex(0);
			// Persist immediately so a crash right after this point still
			// lets a resume reconcile against the same staging collection.
			$this->fileMapper->update($file);
		}

		$transferId = $file->getTransferId();
		$sourcePath = $file->getSourcePath();
		$this->webDavClient->startChunkedUpload($instance, $targetUserId, $appPassword, $transferId);
		$uploadedChunks = array_flip($this->webDavClient->listUploadedChunks($instance, $targetUserId, $appPassword, $transferId));

		$size = $node->getSize();
		$chunkSize = MigrationFile::CHUNK_SIZE_BYTES;
		$totalChunks = (int)ceil($size / $chunkSize);

		$source = $node->fopen('r');
		if ($source === false) {
			throw new TransferException("Could not open source file '{$sourcePath}' for reading", true);
		}

		$ctx = hash_init('sha256');
		$bytesAccounted = 0;

		for ($chunkIndex = 0; $chunkIndex < $totalChunks; $chunkIndex++) {
			$remaining = min($chunkSize, $size - $bytesAccounted);

			if (isset($uploadedChunks[$chunkIndex])) {
				// Already on the server from a prior attempt: skip
				// re-upload but still advance the read position and
				// running hash so the final checksum covers the whole file.
				$this->skipOrHashChunk($source, $ctx, $remaining);
				$bytesAccounted += $remaining;
				continue;
			}

			$buffer = fopen('php://temp/maxmemory:2097152', 'r+');
			$read = 0;
			while ($read < $remaining) {
				$want = (int)min(8 * 1024 * 1024, $remaining - $read);
				$data = fread($source, $want);
				if ($data === false || $data === '') {
					break;
				}
				hash_update($ctx, $data);
				fwrite($buffer, $data);
				$read += strlen($data);
			}
			rewind($buffer);

			$this->webDavClient->uploadChunk($instance, $targetUserId, $appPassword, $transferId, $chunkIndex, $buffer, $read);
			fclose($buffer);

			$bytesAccounted += $read;
			$file->setNextChunkIndex($chunkIndex + 1);
			$file->setBytesTransferred($bytesAccounted);
			// Checkpoint progress after every chunk so a crash mid-file
			// only loses at most one chunk's worth of work on resume.
			$this->fileMapper->update($file);
		}
		fclose($source);

		$this->assertNotChangedDuringRead($file, $instance, $targetUserId, $appPassword, $sourceUserId, $preMtime, $preSize, $bytesAccounted, false);

		$sha256 = hash_final($ctx);
		$this->webDavClient->assembleChunkedUpload(
			$instance,
			$targetUserId,
			$appPassword,
			$transferId,
			$file->getTargetPath() ?? $sourcePath,
			$size,
			$preMtime,
			$sha256,
		);

		$file->setSourceChecksum($sha256);
		$file->setBytesTransferred($size);
	}

	/**
	 * Guards against uploading a "torn read": if the source file was
	 * modified while we were reading it, the bytes we just hashed may not
	 * correspond to any single consistent version of the file. Mirrors the
	 * Nextcloud desktop client's pre/post-upload mtime+size comparison.
	 * Re-fetches the node (rather than reusing the in-memory one, which may
	 * cache stale metadata) so this reflects the true current state.
	 *
	 * Also compares the number of bytes actually read/buffered against the
	 * pre-read reported size. These are handled differently depending on
	 * WHY they disagree:
	 *  - mtime or a fresh stat's size actually changed: a genuine
	 *    concurrent edit, so the bytes we just read may not correspond to
	 *    any single consistent version of the file - abort and retry so
	 *    the next attempt reads a stable version.
	 *  - mtime/stat-size are unchanged (both before AND after the read)
	 *    but the actual byte count still disagrees: the file itself never
	 *    changed, its cached filecache `size` metadata is simply wrong
	 *    (observed in practice on old files, real-on-disk content LARGER
	 *    than the DB's cached size - most commonly a leftover from
	 *    Nextcloud's own server-side encryption having been enabled at
	 *    some point in the past: encrypted files are physically LARGER on
	 *    disk than their logical/decrypted size, since Nextcloud tracks
	 *    that separately as `unencrypted_size`, which can go stale). When
	 *    $fullReadGuaranteed is true (the caller read all the way to the
	 *    stream's real EOF, not just until some pre-computed byte count),
	 *    there's nothing to retry: we already have the file's complete,
	 *    real, current content in hand, and the caller declares the actual
	 *    $bytesRead (not the stale $preSize) to the target - so this is
	 *    just logged for visibility rather than treated as a failure.
	 *    Previously this case was treated the same as a live edit and
	 *    aborted too, even though declaring the wrong (stale) size to the
	 *    target risked a Content-Length/body mismatch that a reverse
	 *    proxy/WAF in front of the target could reject outright with a
	 *    generic, unhelpful error (e.g. a bare "400 Bad Request" page with
	 *    no Nextcloud-side detail at all, identical across every affected
	 *    file). When $fullReadGuaranteed is false (the chunked path, whose
	 *    loop reads only up to a total derived from the SAME possibly-stale
	 *    $preSize rather than the stream's real EOF - so a real file
	 *    LARGER than $preSize would otherwise be silently truncated rather
	 *    than ever producing a mismatch here), a disagreement is still
	 *    treated as risky and retried.
	 *
	 * Chunk-level resume assumes byte-stable content across attempts, so on
	 * a genuine live edit we also invalidate any in-progress chunked-upload
	 * session (abort the staging collection + clear
	 * transferId/nextChunkIndex) rather than letting a future retry
	 * "resume" over content that no longer matches what was already
	 * uploaded.
	 *
	 * @throws TransferException retryable - not thrown for a stale-cached-
	 *         size mismatch when $fullReadGuaranteed is true
	 */
	private function assertNotChangedDuringRead(
		MigrationFile $file,
		RemoteInstance $instance,
		string $targetUserId,
		string $appPassword,
		string $sourceUserId,
		int $preMtime,
		int $preSize,
		int $bytesRead,
		bool $fullReadGuaranteed,
	): void {
		$fresh = $this->rootFolder->getUserFolder($sourceUserId)->get($file->getSourcePath());
		$metadataChanged = $fresh->getMTime() !== $preMtime || $fresh->getSize() !== $preSize;

		if (!$metadataChanged) {
			if ($bytesRead === $preSize) {
				return;
			}

			if ($fullReadGuaranteed) {
				// Stale cached size on an otherwise-unchanged file: not an
				// error, just worth a record. We already have the file's
				// complete, current content (read to real EOF) and the
				// caller declares $bytesRead (not $preSize) to the target,
				// so there's nothing more to do here. (Not auto-corrected
				// in the filecache: for an ENCRYPTED file the correct
				// column for this would be `unencrypted_size`, not `size`
				// - `size` is meant to reflect the larger, physical
				// on-disk/ciphertext footprint - and we can't reliably
				// tell which case we're in from here, so it's safer to
				// just log it than risk writing to the wrong column.)
				$this->eventLogger->log(
					$file->getRunId(),
					'source_size_metadata_stale',
					"Source file '{$file->getSourcePath()}' has stale cached size metadata (reported {$preSize}, actually {$bytesRead} byte(s)); uploading the actual content",
					'info',
					$file->getId(),
					['preSize' => $preSize, 'bytesRead' => $bytesRead],
				);

				return;
			}
		}

		$this->eventLogger->log(
			$file->getRunId(),
			'source_drift_detected',
			"Source file '{$file->getSourcePath()}' changed while being read; retrying",
			'warning',
			$file->getId(),
			['preMtime' => $preMtime, 'preSize' => $preSize, 'postMtime' => $fresh->getMTime(), 'postSize' => $fresh->getSize(), 'bytesRead' => $bytesRead],
		);

		if ($file->getTransferId() !== null) {
			$this->webDavClient->abortChunkedUpload($instance, $targetUserId, $appPassword, $file->getTransferId());
			$file->setTransferId(null);
			$file->setNextChunkIndex(0);
			$file->setBytesTransferred(0);
		}

		throw new TransferException("Source file '{$file->getSourcePath()}' changed while being read", true);
	}

	/**
	 * @param resource $source
	 */
	private function skipOrHashChunk($source, \HashContext $ctx, int $length): void {
		if ($length <= 0) {
			return;
		}

		if (@fseek($source, $length, SEEK_CUR) === 0) {
			return;
		}

		// Stream isn't seekable: fall back to reading (and hashing) the
		// bytes we're skipping so the running checksum still matches the
		// full file.
		$remaining = $length;
		while ($remaining > 0) {
			$chunk = fread($source, (int)min(8 * 1024 * 1024, $remaining));
			if ($chunk === false || $chunk === '') {
				break;
			}
			hash_update($ctx, $chunk);
			$remaining -= strlen($chunk);
		}
	}

	private function recordFailure(MigrationFile $file, RemoteInstance $instance, string $targetUserId, string $appPassword, string $message, bool $retryable = true): void {
		$attempts = $file->getTransferAttempts() + 1;
		$file->setTransferAttempts($attempts);
		$file->setState(MigrationFile::STATE_TRANSFER_FAILED);
		$file->setLastError($message);

		$exhausted = !$retryable || $attempts >= MigrationFile::MAX_TRANSFER_ATTEMPTS;

		if (!$exhausted) {
			// Transient (still-retryable) failure: logged at 'debug' so it
			// doesn't clutter the run's event trail with routine retries,
			// but is still visible when tailing the server log.
			$this->eventLogger->log(
				$file->getRunId(),
				'transfer_retry',
				"Transfer of '{$file->getSourcePath()}' failed (attempt {$attempts}/" . MigrationFile::MAX_TRANSFER_ATTEMPTS . "), will retry: {$message}",
				MigrationEvent::SEVERITY_DEBUG,
				$file->getId(),
			);
			$delay = self::BACKOFF_SECONDS[min($attempts - 1, count(self::BACKOFF_SECONDS) - 1)];
			$file->setNextRetryAt(time() + $delay);
			return;
		}

		// Exhausted retries (or a non-retryable error): leave next_retry_at
		// unset. findTransferable() also filters on transfer_attempts < MAX,
		// so this becomes terminal. Clean up any orphaned chunked-upload
		// staging collection on the target so we don't leak storage there.
		$this->eventLogger->log(
			$file->getRunId(),
			'transfer_failed',
			"Transfer of '{$file->getSourcePath()}' permanently failed after {$attempts} attempt(s): {$message}",
			MigrationEvent::SEVERITY_ERROR,
			$file->getId(),
		);
		$file->setNextRetryAt(null);
		if ($file->getTransferId() !== null) {
			$this->webDavClient->abortChunkedUpload($instance, $targetUserId, $appPassword, $file->getTransferId());
		}
	}
}
