<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use OCA\NextcloudMigrate\Db\FilecacheReader;
use OCA\NextcloudMigrate\Db\MigrationFile;
use OCA\NextcloudMigrate\Db\MigrationFileMapper;
use OCA\NextcloudMigrate\Db\UserMap;
use OCA\NextcloudMigrate\Service\DiscoveryService;
use OCA\NextcloudMigrate\Service\EventLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers DiscoveryService's discovery/re-sync decision logic (what counts
 * as "new" vs "changed", which states are safe to reset) against a mocked
 * FilecacheReader - the reader's own real SQL isn't exercised here (see
 * FilecacheReader's class docblock: like this app's other Db\*Mapper
 * classes, it's validated via the e2e integration test instead).
 */
final class DiscoveryServiceTest extends TestCase {
	private FilecacheReader $filecacheReader;
	private MigrationFileMapper $fileMapper;
	private EventLogger $eventLogger;
	private DiscoveryService $discoveryService;
	private UserMap $userMap;

	protected function setUp(): void {
		$this->filecacheReader = $this->createMock(FilecacheReader::class);
		$this->fileMapper = $this->createMock(MigrationFileMapper::class);
		$this->eventLogger = $this->createMock(EventLogger::class);
		$this->discoveryService = new DiscoveryService($this->filecacheReader, $this->fileMapper, $this->eventLogger, $this->createMock(LoggerInterface::class));

		$this->userMap = new UserMap();
		$this->userMap->setId(7);
		$this->userMap->setRunId(1);
		$this->userMap->setSourceUserId('alice');
		$this->userMap->setTargetUserId('alice');

		$this->filecacheReader->method('resolveHomeStorageId')->with('alice')->willReturn(99);
	}

	/**
	 * @return array{path:string,fileid:int,size:int,mtime:int,mimetype:string,isDirectory:bool}
	 */
	private function fileRow(string $path, int $fileid, int $size, int $mtime): array {
		return ['path' => $path, 'fileid' => $fileid, 'size' => $size, 'mtime' => $mtime, 'mimetype' => 'text/plain', 'isDirectory' => false];
	}

	private function makeExistingFile(string $state, int $size, int $mtime): MigrationFile {
		$existing = new MigrationFile();
		$existing->setId(99);
		$existing->setRunId(1);
		$existing->setUserMapId(7);
		$existing->setSourcePath('report.pdf');
		$existing->setSourcePathHash(hash('sha256', 'report.pdf'));
		$existing->setIsDirectory(false);
		$existing->setSize($size);
		$existing->setMtime($mtime);
		$existing->setState($state);
		$existing->setTargetPath('report.pdf');
		$existing->setTransferAttempts(0);
		$existing->setVerifyAttempts(0);
		$existing->setBytesTransferred($size);
		$existing->setVerifiedAt(500);

		return $existing;
	}

	public function testDiscoverUserThrowsWhenNoHomeStorageFound(): void {
		$this->filecacheReader = $this->createMock(FilecacheReader::class);
		$this->filecacheReader->method('resolveHomeStorageId')->willReturn(null);
		$this->discoveryService = new DiscoveryService($this->filecacheReader, $this->fileMapper, $this->eventLogger, $this->createMock(LoggerInterface::class));

		$this->expectException(\RuntimeException::class);
		$this->discoveryService->discoverUser(1, $this->userMap, 'alice');
	}

	public function testDiscoverUserInsertsEveryRowReturnedByFilecacheReader(): void {
		$this->filecacheReader->method('walk')->with(99)->willReturn((function () {
			yield $this->fileRow('report.pdf', 42, 100, 1000);
			yield ['path' => 'Documents', 'fileid' => 43, 'size' => 0, 'mtime' => 900, 'mimetype' => 'httpd/unix-directory', 'isDirectory' => true];
		})());
		$this->filecacheReader->method('countEncrypted')->willReturn(0);

		$captured = null;
		$this->fileMapper->expects($this->once())->method('insertBatch')->with($this->callback(function (array $batch) use (&$captured) {
			$captured = $batch;
			return true;
		}));

		$stats = $this->discoveryService->discoverUser(1, $this->userMap, 'alice');

		self::assertSame(['files' => 1, 'folders' => 1, 'bytes' => 100], $stats);
		self::assertCount(2, $captured);
		self::assertSame('report.pdf', $captured[0]->getSourcePath());
		self::assertSame(MigrationFile::STATE_DISCOVERED, $captured[0]->getState());
		self::assertTrue($captured[1]->getIsDirectory());
	}

	public function testDiscoverUserLogsEventWhenEncryptedFilesWereExcluded(): void {
		$this->filecacheReader->method('walk')->willReturn((function () {
			yield from [];
		})());
		$this->filecacheReader->method('countEncrypted')->with(99)->willReturn(3);

		$this->eventLogger->expects($this->once())
			->method('log')
			->with(1, 'source_files_excluded_encrypted', self::stringContains('3 file(s)'), 'warning');

		$this->discoveryService->discoverUser(1, $this->userMap, 'alice');
	}

	public function testDiscoverUserDoesNotLogWhenNoEncryptedFilesExcluded(): void {
		$this->filecacheReader->method('walk')->willReturn((function () {
			yield from [];
		})());
		$this->filecacheReader->method('countEncrypted')->willReturn(0);

		$this->eventLogger->expects($this->never())->method('log');

		$this->discoveryService->discoverUser(1, $this->userMap, 'alice');
	}

	public function testDiscoverIncrementalPassesSinceAndMaxFileIdToFilecacheReader(): void {
		$this->fileMapper->method('maxSourceFileId')->with(1, 7)->willReturn(555);
		$this->filecacheReader->expects($this->once())
			->method('walk')
			->with(99, 2000, 555)
			->willReturn((function () {
				yield from [];
			})());

		$this->discoveryService->discoverIncremental(1, $this->userMap, 'alice', 2000);
	}

	public function testDiscoverIncrementalDoesNotQueryMaxFileIdForFullScan(): void {
		$this->fileMapper->expects($this->never())->method('maxSourceFileId');
		$this->filecacheReader->expects($this->once())
			->method('walk')
			->with(99, null, null)
			->willReturn((function () {
				yield from [];
			})());

		$this->discoveryService->discoverIncremental(1, $this->userMap, 'alice');
	}

	public function testBrandNewFileIsInsertedAsDiscovered(): void {
		$this->filecacheReader->method('walk')->willReturn((function () {
			yield $this->fileRow('report.pdf', 42, 100, 1000);
		})());
		$this->fileMapper->method('findByRunAndPathHash')->willReturn(null);

		$captured = null;
		$this->fileMapper->expects($this->once())->method('insertBatch')->with($this->callback(function (array $batch) use (&$captured) {
			$captured = $batch;
			return true;
		}));
		$this->fileMapper->expects($this->never())->method('update');

		$counts = $this->discoveryService->discoverIncremental(1, $this->userMap, 'alice');

		self::assertSame(['new' => 1, 'changed' => 0], $counts);
		self::assertCount(1, $captured);
		self::assertSame('report.pdf', $captured[0]->getSourcePath());
		self::assertSame(MigrationFile::STATE_DISCOVERED, $captured[0]->getState());
	}

	public function testVerifiedFileWithChangedMtimeIsResetForResync(): void {
		$this->filecacheReader->method('walk')->willReturn((function () {
			yield $this->fileRow('report.pdf', 42, 200, 2000);
		})());
		$existing = $this->makeExistingFile(MigrationFile::STATE_VERIFIED, 100, 1000);
		$this->fileMapper->method('findByRunAndPathHash')->willReturn($existing);

		$this->fileMapper->expects($this->never())->method('insertBatch');
		$this->fileMapper->expects($this->once())->method('update')->with($existing);

		$counts = $this->discoveryService->discoverIncremental(1, $this->userMap, 'alice');

		self::assertSame(['new' => 0, 'changed' => 1], $counts);
		self::assertSame(MigrationFile::STATE_DISCOVERED, $existing->getState());
		self::assertSame(200, $existing->getSize());
		self::assertSame(2000, $existing->getMtime());
		self::assertNull($existing->getVerifiedAt());
		self::assertNull($existing->getSourceChecksum());
		self::assertNull($existing->getTargetChecksum());
		// The path already resolved on the target is trusted as-is, not
		// re-mapped via a fresh collision check.
		self::assertSame('report.pdf', $existing->getTargetPath());
	}

	public function testCompletedFileWithChangedSizeIsAlsoResetForResync(): void {
		$this->filecacheReader->method('walk')->willReturn((function () {
			yield $this->fileRow('report.pdf', 42, 999, 1000);
		})());
		$existing = $this->makeExistingFile(MigrationFile::STATE_COMPLETED, 100, 1000);
		$this->fileMapper->method('findByRunAndPathHash')->willReturn($existing);

		$this->fileMapper->expects($this->once())->method('update');

		$counts = $this->discoveryService->discoverIncremental(1, $this->userMap, 'alice');

		self::assertSame(['new' => 0, 'changed' => 1], $counts);
	}

	public function testUnchangedVerifiedFileIsLeftAlone(): void {
		$this->filecacheReader->method('walk')->willReturn((function () {
			yield $this->fileRow('report.pdf', 42, 100, 1000);
		})());
		$existing = $this->makeExistingFile(MigrationFile::STATE_VERIFIED, 100, 1000);
		$this->fileMapper->method('findByRunAndPathHash')->willReturn($existing);

		$this->fileMapper->expects($this->never())->method('update');
		$this->fileMapper->expects($this->never())->method('insertBatch');

		$counts = $this->discoveryService->discoverIncremental(1, $this->userMap, 'alice');

		self::assertSame(['new' => 0, 'changed' => 0], $counts);
		self::assertSame(MigrationFile::STATE_VERIFIED, $existing->getState());
	}

	public function testFileStillMidPipelineIsNotResetEvenIfChanged(): void {
		$this->filecacheReader->method('walk')->willReturn((function () {
			yield $this->fileRow('report.pdf', 42, 999, 9999);
		})());
		$existing = $this->makeExistingFile(MigrationFile::STATE_TRANSFERRING, 100, 1000);
		$this->fileMapper->method('findByRunAndPathHash')->willReturn($existing);

		$this->fileMapper->expects($this->never())->method('update');

		$counts = $this->discoveryService->discoverIncremental(1, $this->userMap, 'alice');

		self::assertSame(['new' => 0, 'changed' => 0], $counts);
		self::assertSame(MigrationFile::STATE_TRANSFERRING, $existing->getState());
	}

	public function testFailedFileIsNotResetByBackgroundScan(): void {
		$this->filecacheReader->method('walk')->willReturn((function () {
			yield $this->fileRow('report.pdf', 42, 999, 9999);
		})());
		$existing = $this->makeExistingFile(MigrationFile::STATE_TRANSFER_FAILED, 100, 1000);
		$this->fileMapper->method('findByRunAndPathHash')->willReturn($existing);

		$this->fileMapper->expects($this->never())->method('update');

		$counts = $this->discoveryService->discoverIncremental(1, $this->userMap, 'alice');

		self::assertSame(['new' => 0, 'changed' => 0], $counts);
	}

	public function testExistingDirectoryIsNeverResetEvenIfListedAgain(): void {
		$this->filecacheReader->method('walk')->willReturn((function () {
			yield ['path' => 'Documents', 'fileid' => 5, 'size' => 0, 'mtime' => 1, 'mimetype' => 'httpd/unix-directory', 'isDirectory' => true];
		})());

		$existing = new MigrationFile();
		$existing->setId(50);
		$existing->setIsDirectory(true);
		$existing->setState(MigrationFile::STATE_VERIFIED);
		$existing->setSize(0);
		$existing->setMtime(1);
		$this->fileMapper->method('findByRunAndPathHash')->willReturn($existing);

		$this->fileMapper->expects($this->never())->method('update');
		$this->fileMapper->expects($this->never())->method('insertBatch');

		$counts = $this->discoveryService->discoverIncremental(1, $this->userMap, 'alice');

		self::assertSame(['new' => 0, 'changed' => 0], $counts);
	}
}
