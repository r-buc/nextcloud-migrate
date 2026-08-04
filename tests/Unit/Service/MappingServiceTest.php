<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use OCA\NextcloudMigrate\Db\MigrationFile;
use OCA\NextcloudMigrate\Db\MigrationFileMapper;
use OCA\NextcloudMigrate\Db\RemoteInstance;
use OCA\NextcloudMigrate\Exception\RemoteConnectionException;
use OCA\NextcloudMigrate\Service\MappingService;
use OCA\NextcloudMigrate\Service\WebDavClient;
use PHPUnit\Framework\TestCase;

final class MappingServiceTest extends TestCase {
	private WebDavClient $webDavClient;
	private MigrationFileMapper $fileMapper;
	private MappingService $mappingService;

	protected function setUp(): void {
		$this->webDavClient = $this->createMock(WebDavClient::class);
		$this->fileMapper = $this->createMock(MigrationFileMapper::class);
		$this->mappingService = new MappingService($this->webDavClient, $this->fileMapper);
	}

	private function makeFile(string $sourcePath, bool $isDirectory = false): MigrationFile {
		$file = new MigrationFile();
		$file->setSourcePath($sourcePath);
		$file->setSourcePathHash(hash('sha256', $sourcePath));
		$file->setIsDirectory($isDirectory);
		$file->setSize(100);
		$file->setState(MigrationFile::STATE_DISCOVERED);
		$file->setTransferAttempts(0);
		$file->setVerifyAttempts(0);
		$file->setBytesTransferred(0);
		$file->setNextChunkIndex(0);
		$file->setCreatedAt(1000);
		$file->setUpdatedAt(1000);

		return $file;
	}

	public function testDirectoriesAlwaysMap1to1WithoutCollisionCheck(): void {
		$file = $this->makeFile('Documents/Reports', isDirectory: true);
		$this->webDavClient->expects($this->never())->method('stat');

		$this->mappingService->mapFile($file, new RemoteInstance(), 'secret', MappingService::STRATEGY_RENAME);

		self::assertSame(MigrationFile::STATE_MAPPED, $file->getState());
		self::assertSame('Documents/Reports', $file->getTargetPath());
	}

	public function testFileWithNoCollisionMapsToSamePath(): void {
		$file = $this->makeFile('Documents/report.pdf');
		$this->webDavClient->method('stat')->willReturn(null);

		$this->mappingService->mapFile($file, new RemoteInstance(), 'secret', MappingService::STRATEGY_RENAME);

		self::assertSame(MigrationFile::STATE_MAPPED, $file->getState());
		self::assertSame('Documents/report.pdf', $file->getTargetPath());
	}

	public function testRenameStrategyAppendsSuffixBeforeExtensionOnCollision(): void {
		$file = $this->makeFile('Documents/report.pdf');
		$this->webDavClient->method('stat')->willReturn(['size' => 1, 'etag' => 'x', 'checksum' => null]);

		$this->mappingService->mapFile($file, new RemoteInstance(), 'secret', MappingService::STRATEGY_RENAME);

		self::assertSame(MigrationFile::STATE_MAPPED, $file->getState());
		self::assertMatchesRegularExpression('#^Documents/report_migrated_\d+\.pdf$#', $file->getTargetPath());
	}

	public function testRenameStrategyWithoutExtensionAppendsSuffixAtEnd(): void {
		$file = $this->makeFile('Documents/README');
		$this->webDavClient->method('stat')->willReturn(['size' => 1, 'etag' => 'x', 'checksum' => null]);

		$this->mappingService->mapFile($file, new RemoteInstance(), 'secret', MappingService::STRATEGY_RENAME);

		self::assertMatchesRegularExpression('#^Documents/README_migrated_\d+$#', $file->getTargetPath());
	}

	public function testSkipStrategyMarksFileSkippedOnCollision(): void {
		$file = $this->makeFile('Documents/report.pdf');
		$this->webDavClient->method('stat')->willReturn(['size' => 1, 'etag' => 'x', 'checksum' => null]);

		$this->mappingService->mapFile($file, new RemoteInstance(), 'secret', MappingService::STRATEGY_SKIP);

		self::assertSame(MigrationFile::STATE_SKIPPED, $file->getState());
		self::assertNull($file->getTargetPath());
	}

	public function testOverwriteStrategyMapsToSamePathOnCollision(): void {
		$file = $this->makeFile('Documents/report.pdf');
		$this->webDavClient->method('stat')->willReturn(['size' => 1, 'etag' => 'x', 'checksum' => null]);

		$this->mappingService->mapFile($file, new RemoteInstance(), 'secret', MappingService::STRATEGY_OVERWRITE);

		self::assertSame(MigrationFile::STATE_MAPPED, $file->getState());
		self::assertSame('Documents/report.pdf', $file->getTargetPath());
	}

	public function testUnknownCollisionStrategyThrows(): void {
		$file = $this->makeFile('Documents/report.pdf');

		$this->expectException(\InvalidArgumentException::class);
		$this->mappingService->mapFile($file, new RemoteInstance(), 'secret', 'not-a-real-strategy');
	}

	public function testConnectionFailureDuringCollisionCheckMarksMappingFailed(): void {
		$file = $this->makeFile('Documents/report.pdf');
		$this->webDavClient->method('stat')->willThrowException(new RemoteConnectionException('boom', 500));

		$this->mappingService->mapFile($file, new RemoteInstance(), 'secret', MappingService::STRATEGY_RENAME);

		self::assertSame(MigrationFile::STATE_MAPPING_FAILED, $file->getState());
		self::assertStringContainsString('boom', (string)$file->getLastError());
	}
}
