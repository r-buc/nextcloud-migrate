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
	// indexed by (attempt number - 1).
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
		while (!feof($source)) {
			$chunk = fread($source, 8 * 1024 * 1024);
			if ($chunk === false || $chunk === '') {
				break;
			}
			hash_update($ctx, $chunk);
			fwrite($buffer, $chunk);
		}
		fclose($source);
		$sha256 = hash_final($ctx);
		rewind($buffer);

		$this->assertNotChangedDuringRead($file, $instance, $targetUserId, $appPassword, $sourceUserId, $preMtime, $preSize);

		$this->webDavClient->putFile(
			$instance,
			$targetUserId,
			$appPassword,
			$file->getTargetPath() ?? $sourcePath,
			$buffer,
			$preSize,
			$preMtime,
			$sha256,
		);
		fclose($buffer);

		$file->setSourceChecksum($sha256);
		$file->setBytesTransferred($preSize);
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

		$this->assertNotChangedDuringRead($file, $instance, $targetUserId, $appPassword, $sourceUserId, $preMtime, $preSize);

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
	 * Chunk-level resume assumes byte-stable content across attempts, so on
	 * drift we also invalidate any in-progress chunked-upload session
	 * (abort the staging collection + clear transferId/nextChunkIndex)
	 * rather than letting a future retry "resume" over content that no
	 * longer matches what was already uploaded.
	 *
	 * @throws TransferException retryable - the next attempt will read
	 *         whatever the (hopefully now stable) latest version is
	 */
	private function assertNotChangedDuringRead(
		MigrationFile $file,
		RemoteInstance $instance,
		string $targetUserId,
		string $appPassword,
		string $sourceUserId,
		int $preMtime,
		int $preSize,
	): void {
		$fresh = $this->rootFolder->getUserFolder($sourceUserId)->get($file->getSourcePath());
		if ($fresh->getMTime() === $preMtime && $fresh->getSize() === $preSize) {
			return;
		}

		$this->eventLogger->log(
			$file->getRunId(),
			'source_drift_detected',
			"Source file '{$file->getSourcePath()}' changed while being read; retrying with latest content",
			'warning',
			$file->getId(),
			['preMtime' => $preMtime, 'preSize' => $preSize, 'postMtime' => $fresh->getMTime(), 'postSize' => $fresh->getSize()],
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
