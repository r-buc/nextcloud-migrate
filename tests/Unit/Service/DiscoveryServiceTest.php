<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use OCA\NextcloudMigrate\Db\MigrationFile;
use OCA\NextcloudMigrate\Db\MigrationFileMapper;
use OCA\NextcloudMigrate\Db\UserMap;
use OCA\NextcloudMigrate\Service\DiscoveryService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers DiscoveryService::discoverIncremental() - the re-scan used by
 * continuous sync (MigrationRun::STATE_SYNCING) to find files that
 * appeared or changed on the source since the initial discovery pass.
 */
final class DiscoveryServiceTest extends TestCase {
	private IRootFolder $rootFolder;
	private MigrationFileMapper $fileMapper;
	private DiscoveryService $discoveryService;
	private UserMap $userMap;

	protected function setUp(): void {
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->fileMapper = $this->createMock(MigrationFileMapper::class);
		$this->discoveryService = new DiscoveryService($this->rootFolder, $this->fileMapper, $this->createMock(LoggerInterface::class));

		$this->userMap = new UserMap();
		$this->userMap->setId(7);
		$this->userMap->setRunId(1);
		$this->userMap->setSourceUserId('alice');
		$this->userMap->setTargetUserId('alice');
	}

	private function makeUserFolder(array $children): Folder {
		$root = $this->createMock(Folder::class);
		$root->method('getPath')->willReturn('/alice/files');
		$root->method('getDirectoryListing')->willReturn($children);

		return $root;
	}

	private function makeFile(string $path, int $id, int $size, int $mtime, string $mimetype = 'text/plain'): File {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn($path);
		$file->method('getId')->willReturn($id);
		$file->method('getSize')->willReturn($size);
		$file->method('getMTime')->willReturn($mtime);
		$file->method('getMimetype')->willReturn($mimetype);

		return $file;
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

	public function testBrandNewFileIsInsertedAsDiscovered(): void {
		$node = $this->makeFile('/alice/files/report.pdf', 42, 100, 1000);
		$this->rootFolder->method('getUserFolder')->willReturn($this->makeUserFolder([$node]));
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
		$node = $this->makeFile('/alice/files/report.pdf', 42, 200, 2000);
		$this->rootFolder->method('getUserFolder')->willReturn($this->makeUserFolder([$node]));
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
		$node = $this->makeFile('/alice/files/report.pdf', 42, 999, 1000);
		$this->rootFolder->method('getUserFolder')->willReturn($this->makeUserFolder([$node]));
		$existing = $this->makeExistingFile(MigrationFile::STATE_COMPLETED, 100, 1000);
		$this->fileMapper->method('findByRunAndPathHash')->willReturn($existing);

		$this->fileMapper->expects($this->once())->method('update');

		$counts = $this->discoveryService->discoverIncremental(1, $this->userMap, 'alice');

		self::assertSame(['new' => 0, 'changed' => 1], $counts);
	}

	public function testUnchangedVerifiedFileIsLeftAlone(): void {
		$node = $this->makeFile('/alice/files/report.pdf', 42, 100, 1000);
		$this->rootFolder->method('getUserFolder')->willReturn($this->makeUserFolder([$node]));
		$existing = $this->makeExistingFile(MigrationFile::STATE_VERIFIED, 100, 1000);
		$this->fileMapper->method('findByRunAndPathHash')->willReturn($existing);

		$this->fileMapper->expects($this->never())->method('update');
		$this->fileMapper->expects($this->never())->method('insertBatch');

		$counts = $this->discoveryService->discoverIncremental(1, $this->userMap, 'alice');

		self::assertSame(['new' => 0, 'changed' => 0], $counts);
		self::assertSame(MigrationFile::STATE_VERIFIED, $existing->getState());
	}

	public function testFileStillMidPipelineIsNotResetEvenIfChanged(): void {
		$node = $this->makeFile('/alice/files/report.pdf', 42, 999, 9999);
		$this->rootFolder->method('getUserFolder')->willReturn($this->makeUserFolder([$node]));
		$existing = $this->makeExistingFile(MigrationFile::STATE_TRANSFERRING, 100, 1000);
		$this->fileMapper->method('findByRunAndPathHash')->willReturn($existing);

		$this->fileMapper->expects($this->never())->method('update');

		$counts = $this->discoveryService->discoverIncremental(1, $this->userMap, 'alice');

		self::assertSame(['new' => 0, 'changed' => 0], $counts);
		self::assertSame(MigrationFile::STATE_TRANSFERRING, $existing->getState());
	}

	public function testFailedFileIsNotResetByBackgroundScan(): void {
		$node = $this->makeFile('/alice/files/report.pdf', 42, 999, 9999);
		$this->rootFolder->method('getUserFolder')->willReturn($this->makeUserFolder([$node]));
		$existing = $this->makeExistingFile(MigrationFile::STATE_TRANSFER_FAILED, 100, 1000);
		$this->fileMapper->method('findByRunAndPathHash')->willReturn($existing);

		$this->fileMapper->expects($this->never())->method('update');

		$counts = $this->discoveryService->discoverIncremental(1, $this->userMap, 'alice');

		self::assertSame(['new' => 0, 'changed' => 0], $counts);
	}

	public function testExistingDirectoryIsNeverResetEvenIfListedAgain(): void {
		$folderNode = $this->createMock(Folder::class);
		$folderNode->method('getPath')->willReturn('/alice/files/Documents');
		$folderNode->method('getId')->willReturn(5);
		$folderNode->method('getDirectoryListing')->willReturn([]);
		$this->rootFolder->method('getUserFolder')->willReturn($this->makeUserFolder([$folderNode]));

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
