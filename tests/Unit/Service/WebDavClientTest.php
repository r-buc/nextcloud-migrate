<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use OCA\NextcloudMigrate\Service\WebDavClient;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers WebDavClient::parsePropfindResponse() - the pure XML-parsing logic
 * behind stat(), including the target d:getlastmodified -> mtime handling
 * added for MappingService::STRATEGY_OVERWRITE_IF_NEWER. Invoked via
 * reflection since it's private and has no network/curl dependency worth
 * mocking for what is otherwise a stateless parser.
 */
final class WebDavClientTest extends TestCase {
	private WebDavClient $webDavClient;

	protected function setUp(): void {
		$this->webDavClient = new WebDavClient($this->createMock(LoggerInterface::class));
	}

	/**
	 * @return array{size:int,etag:?string,checksum:?string,mtime:?int}
	 */
	private function parse(string $xml): array {
		$method = new \ReflectionMethod(WebDavClient::class, 'parsePropfindResponse');
		$method->setAccessible(true);

		return $method->invoke($this->webDavClient, $xml);
	}

	private function propfindXml(string $propsXml): string {
		return <<<XML
<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:response>
    <d:href>/remote.php/dav/files/alice/Documents/report.pdf</d:href>
    <d:propstat>
      <d:prop>
        {$propsXml}
      </d:prop>
      <d:status>HTTP/1.1 200 OK</d:status>
    </d:propstat>
  </d:response>
</d:multistatus>
XML;
	}

	public function testParsesSizeEtagChecksumAndMtimeFromFullResponse(): void {
		$xml = $this->propfindXml(<<<PROPS
<d:getcontentlength>12345</d:getcontentlength>
<d:getetag>"abc123etag"</d:getetag>
<d:getlastmodified>Thu, 07 Aug 2026 12:00:00 GMT</d:getlastmodified>
<oc:checksums>
  <oc:checksum>SHA256:deadbeef</oc:checksum>
</oc:checksums>
PROPS);

		$result = $this->parse($xml);

		self::assertSame(12345, $result['size']);
		self::assertSame('abc123etag', $result['etag']);
		self::assertSame('deadbeef', $result['checksum']);
		self::assertSame(strtotime('Thu, 07 Aug 2026 12:00:00 GMT'), $result['mtime']);
	}

	public function testMtimeIsNullWhenGetLastModifiedIsAbsent(): void {
		$xml = $this->propfindXml(<<<PROPS
<d:getcontentlength>1</d:getcontentlength>
<d:getetag>"x"</d:getetag>
PROPS);

		$result = $this->parse($xml);

		self::assertNull($result['mtime']);
		self::assertSame(1, $result['size']);
	}

	public function testMtimeIsNullWhenGetLastModifiedIsUnparsable(): void {
		$xml = $this->propfindXml('<d:getlastmodified>not-a-real-date</d:getlastmodified>');

		$result = $this->parse($xml);

		self::assertNull($result['mtime']);
	}

	public function testChecksumPicksSha256AmongMultipleAlgorithms(): void {
		$xml = $this->propfindXml(<<<PROPS
<oc:checksums>
  <oc:checksum>MD5:ignoreme</oc:checksum>
  <oc:checksum>SHA256:realsha</oc:checksum>
</oc:checksums>
PROPS);

		$result = $this->parse($xml);

		self::assertSame('realsha', $result['checksum']);
	}

	public function testMalformedXmlReturnsSafeDefaultsWithoutThrowing(): void {
		// SimpleXMLElement emits libxml-level PHP warnings (in addition to
		// the \Exception parsePropfindResponse() already catches) for
		// genuinely malformed input - suppress those expected warnings so
		// the test only asserts the behavior we actually care about: no
		// exception escapes, and safe defaults come back.
		$previous = libxml_use_internal_errors(true);
		try {
			$result = $this->parse('not xml at all <<<');
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		self::assertSame(0, $result['size']);
		self::assertNull($result['etag']);
		self::assertNull($result['checksum']);
		self::assertNull($result['mtime']);
	}

	public function testEmptyMultistatusResponseReturnsSafeDefaults(): void {
		$xml = <<<XML
<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" />
XML;

		$result = $this->parse($xml);

		self::assertSame(0, $result['size']);
		self::assertNull($result['etag']);
		self::assertNull($result['checksum']);
		self::assertNull($result['mtime']);
	}
}
