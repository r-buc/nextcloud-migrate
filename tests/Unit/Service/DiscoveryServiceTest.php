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
 * Covers DiscoveryService's discovery/re-sync decision logic (what counts
 * as "new" vs "changed", which states are safe to reset) against a mocked
 * Folder::search() - real Nextcloud search-query/DB behavior isn't
 * exercised here, only validated via the e2e integration test.
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

	/**
	 * @param \OCP\Files\Node[] $searchResults returned for every search()
	 *        call (single page) unless $consecutivePages is given
	 */
	private function makeUserFolder(array $searchResults, ?array $consecutivePages = null): Folder {
		$root = $this->createMock(Folder::class);
		$root->method('getPath')->willReturn('/alice/files');
		if ($consecutivePages !== null) {
			$root->method('search')->willReturnOnConsecutiveCalls(...$consecutivePages);
		} else {
			$root->method('search')->willReturn($searchResults);
		}

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

	private function makeFolderNode(string $path, int $id): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn($path);
		$folder->method('getId')->willReturn($id);
		$folder->method('getSize')->willReturn(0);
		$folder->method('getMTime')->willReturn(900);
		$folder->method('getMimetype')->willReturn('httpd/unix-directory');

		return $folder;
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

	public function testDiscoverUserInsertsEveryNodeReturnedBySearch(): void {
		$file = $this->makeFile('/alice/files/report.pdf', 42, 100, 1000);
		$folder = $this->makeFolderNode('/alice/files/Documents', 43);
		$this->rootFolder->method('getUserFolder')->willReturn($this->makeUserFolder([$file, $folder]));

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
		self::assertSame('Documents', $captured[1]->getSourcePath());
	}

	public function testDiscoverUserSkipsTheRootFolderItself(): void {
		$rootNode = $this->makeFolderNode('/alice/files', 1);
		$file = $this->makeFile('/alice/files/report.pdf', 42, 100, 1000);
		$this->rootFolder->method('getUserFolder')->willReturn($this->makeUserFolder([$rootNode, $file]));

		$captured = null;
		$this->fileMapper->expects($this->once())->method('insertBatch')->with($this->callback(function (array $batch) use (&$captured) {
			$captured = $batch;
			return true;
		}));

		$this->discoveryService->discoverUser(1, $this->userMap, 'alice');

		self::assertCount(1, $captured);
		self::assertSame('report.pdf', $captured[0]->getSourcePath());
	}

	public function testDiscoverUserPaginatesUntilAShortPage(): void {
		// Page size is 500; a first page returning exactly 500 nodes must
		// trigger a second search() call, which returning fewer than 500
		// (here: 1) ends pagination.
		$fullPage = [];
		for ($i = 0; $i < 500; $i++) {
			$fullPage[] = $this->makeFile("/alice/files/file{$i}.txt", $i, 10, 1000);
		}
		$secondPage = [$this->makeFile('/alice/files/last.txt', 999, 10, 1000)];

		$root = $this->createMock(Folder::class);
		$root->method('getPath')->willReturn('/alice/files');
		$root->expects($this->exactly(2))->method('search')->willReturnOnConsecutiveCalls($fullPage, $secondPage);
		$this->rootFolder->method('getUserFolder')->willReturn($root);

		$stats = $this->discoveryService->discoverUser(1, $this->userMap, 'alice');

		self::assertSame(501, $stats['files']);
	}

	public function testDiscoverIncrementalPassesSinceMtimeIntoTheSearchQuery(): void {
		$root = $this->createMock(Folder::class);
		$root->method('getPath')->willReturn('/alice/files');
		$root->expects($this->once())->method('search')->with($this->callback(function ($query) {
			$operation = $query->getSearchOperation();
			return $operation->getField() === 'mtime' && $operation->getValue() === 2000;
		}))->willReturn([]);
		$this->rootFolder->method('getUserFolder')->willReturn($root);

		$this->discoveryService->discoverIncremental(1, $this->userMap, 'alice', 2000);
	}

	public function testBrandNewFileIsInsertedAsDiscovered(): void {
		$file = $this->makeFile('/alice/files/report.pdf', 42, 100, 1000);
		$this->rootFolder->method('getUserFolder')->willReturn($this->makeUserFolder([$file]));
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
		$file = $this->makeFile('/alice/files/report.pdf', 42, 200, 2000);
		$this->rootFolder->method('getUserFolder')->willReturn($this->makeUserFolder([$file]));
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
		$file = $this->makeFile('/alice/files/report.pdf', 42, 999, 1000);
		$this->rootFolder->method('getUserFolder')->willReturn($this->makeUserFolder([$file]));
		$existing = $this->makeExistingFile(MigrationFile::STATE_COMPLETED, 100, 1000);
		$this->fileMapper->method('findByRunAndPathHash')->willReturn($existing);

		$this->fileMapper->expects($this->once())->method('update');

		$counts = $this->discoveryService->discoverIncremental(1, $this->userMap, 'alice');

		self::assertSame(['new' => 0, 'changed' => 1], $counts);
	}

	public function testUnchangedVerifiedFileIsLeftAlone(): void {
		$file = $this->makeFile('/alice/files/report.pdf', 42, 100, 1000);
		$this->rootFolder->method('getUserFolder')->willReturn($this->makeUserFolder([$file]));
		$existing = $this->makeExistingFile(MigrationFile::STATE_VERIFIED, 100, 1000);
		$this->fileMapper->method('findByRunAndPathHash')->willReturn($existing);

		$this->fileMapper->expects($this->never())->method('update');
		$this->fileMapper->expects($this->never())->method('insertBatch');

		$counts = $this->discoveryService->discoverIncremental(1, $this->userMap, 'alice');

		self::assertSame(['new' => 0, 'changed' => 0], $counts);
		self::assertSame(MigrationFile::STATE_VERIFIED, $existing->getState());
	}

	public function testFileStillMidPipelineIsNotResetEvenIfChanged(): void {
		$file = $this->makeFile('/alice/files/report.pdf', 42, 999, 9999);
		$this->rootFolder->method('getUserFolder')->willReturn($this->makeUserFolder([$file]));
		$existing = $this->makeExistingFile(MigrationFile::STATE_TRANSFERRING, 100, 1000);
		$this->fileMapper->method('findByRunAndPathHash')->willReturn($existing);

		$this->fileMapper->expects($this->never())->method('update');

		$counts = $this->discoveryService->discoverIncremental(1, $this->userMap, 'alice');

		self::assertSame(['new' => 0, 'changed' => 0], $counts);
		self::assertSame(MigrationFile::STATE_TRANSFERRING, $existing->getState());
	}

	public function testFailedFileIsNotResetByBackgroundScan(): void {
		$file = $this->makeFile('/alice/files/report.pdf', 42, 999, 9999);
		$this->rootFolder->method('getUserFolder')->willReturn($this->makeUserFolder([$file]));
		$existing = $this->makeExistingFile(MigrationFile::STATE_TRANSFER_FAILED, 100, 1000);
		$this->fileMapper->method('findByRunAndPathHash')->willReturn($existing);

		$this->fileMapper->expects($this->never())->method('update');

		$counts = $this->discoveryService->discoverIncremental(1, $this->userMap, 'alice');

		self::assertSame(['new' => 0, 'changed' => 0], $counts);
	}

	public function testExistingDirectoryIsNeverResetEvenIfListedAgain(): void {
		$folder = $this->makeFolderNode('/alice/files/Documents', 5);
		$this->rootFolder->method('getUserFolder')->willReturn($this->makeUserFolder([$folder]));

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
