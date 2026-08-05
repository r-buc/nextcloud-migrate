<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service;

use OCA\NextcloudMigrate\Db\RemoteInstance;
use OCA\NextcloudMigrate\Exception\RemoteConnectionException;
use OCA\NextcloudMigrate\Exception\TransferException;
use Psr\Log\LoggerInterface;

/**
 * Thin WebDAV client for talking to the TARGET Nextcloud instance.
 *
 * This app runs on the SOURCE instance (push mode), so the source side of a
 * migration is read directly off local storage via the Files API
 * (see DiscoveryService/TransferService). The target instance is only ever
 * reachable over the network, hence this WebDAV wrapper.
 *
 * Every method that touches a specific user's files takes an explicit
 * $targetUserId + that user's own $appPassword: Nextcloud's WebDAV auth
 * backend (apps/dav/lib/Connector/Sabre/Auth.php) rewrites the DAV
 * principal to whichever user actually authenticates, so there is no
 * admin-bypass for writing into a different user's files/ collection - a
 * shared/admin credential simply cannot do it. RemoteInstance therefore
 * only carries connection settings (URL, TLS policy), never credentials.
 *
 * Implemented with plain PHP curl rather than OCP\Http\Client: the WebDAV
 * verbs this app needs (PROPFIND, MKCOL, MOVE) can only be sent through
 * OCP\Http\Client\IClient's generic request() method, which was only added
 * in Nextcloud 29 - this app supports 27+ (see appinfo/info.xml).
 */
class WebDavClient {
	private const REQUEST_TIMEOUT = 60;

	// Reused across requests (see execute()) so repeated calls to the same
	// target instance within a single job execution - e.g. TransferWorkerJob
	// processing many files in one batch - keep their TCP/TLS connection
	// alive instead of renegotiating a fresh handshake per request. Scoped
	// to a single target user at a time (see $curlHandleUserKey): reusing a
	// connection across different users' credentials is avoided in case
	// the server-side WebDAV/DAV stack ever mishandles per-connection state
	// tied to whichever principal last authenticated on it.
	/** @var \CurlHandle|resource|null */
	private $curlHandle = null;

	// The target user (or null for unauthenticated requests, e.g.
	// testConnection()'s status.php check) the current $curlHandle's
	// connection is scoped to. execute() closes and reopens the handle
	// whenever this changes.
	private ?string $curlHandleUserKey = null;

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	public function __destruct() {
		if ($this->curlHandle !== null) {
			curl_close($this->curlHandle);
			$this->curlHandle = null;
		}
	}

	/**
	 * Verifies the target URL is reachable and looks like a Nextcloud
	 * instance, using the public, unauthenticated status.php endpoint - no
	 * credentials are involved at the instance level (see class docblock).
	 *
	 * @throws RemoteConnectionException
	 */
	public function testConnection(RemoteInstance $instance): void {
		$uri = rtrim($instance->getUrl(), '/') . '/status.php';

		try {
			$response = $this->execute('GET', $uri, [
				'verify' => !$instance->getAllowSelfSigned(),
				'timeout' => self::REQUEST_TIMEOUT,
			]);
		} catch (\Exception $e) {
			$status = $this->statusFromException($e);
			throw new RemoteConnectionException('Failed to reach target instance: ' . $e->getMessage(), $status ?? 0, $e);
		}

		$data = json_decode($response['body'], true);
		if (!is_array($data) || !isset($data['version'])) {
			throw new RemoteConnectionException('URL did not respond like a Nextcloud instance (status.php)', 0);
		}
	}

	/**
	 * Verifies a specific mapped user's own app password is valid by issuing
	 * a shallow PROPFIND against their own DAV root.
	 *
	 * @throws RemoteConnectionException
	 */
	public function testUserCredentials(RemoteInstance $instance, string $targetUserId, string $appPassword): void {
		$this->propfind($instance, $targetUserId, $appPassword, '', 0);
	}

	/**
	 * @return array{size:int,etag:?string,checksum:?string}|null null if the
	 *         remote path does not exist
	 * @throws RemoteConnectionException
	 */
	public function stat(RemoteInstance $instance, string $targetUserId, string $appPassword, string $path): ?array {
		try {
			$props = $this->propfind($instance, $targetUserId, $appPassword, $path, 0);
		} catch (RemoteConnectionException $e) {
			if ($e->getCode() === 404) {
				return null;
			}
			throw $e;
		}

		return $props;
	}

	/**
	 * Creates a remote collection (folder). Idempotent: an existing folder
	 * (405 Method Not Allowed) is treated as success.
	 *
	 * @throws RemoteConnectionException
	 */
	public function makeCollection(RemoteInstance $instance, string $targetUserId, string $appPassword, string $path): void {
		$uri = $this->buildUri($instance, $targetUserId, $path);

		try {
			$this->execute('MKCOL', $uri, $this->baseOptions($instance, $targetUserId, $appPassword));
		} catch (\Exception $e) {
			$status = $this->statusFromException($e);
			// 405 = already exists, treat as success for idempotent folder creation.
			if ($status === 405) {
				return;
			}
			throw new RemoteConnectionException("Failed to create remote folder '{$path}': " . $e->getMessage(), $status ?? 0, $e);
		}
	}

	/**
	 * Streams a local resource to the target path via PUT. Preserves mtime
	 * via the X-OC-MTime header and, when available, asks the server to
	 * validate content integrity via the OC-Checksum header.
	 *
	 * @param resource $stream
	 * @throws TransferException
	 */
	public function putFile(
		RemoteInstance $instance,
		string $targetUserId,
		string $appPassword,
		string $path,
		$stream,
		int $size,
		int $mtime,
		?string $sha256 = null,
	): void {
		$uri = $this->buildUri($instance, $targetUserId, $path);

		$headers = [
			'X-OC-MTime' => (string)$mtime,
		];
		if ($sha256 !== null) {
			$headers['OC-Checksum'] = 'SHA256:' . $sha256;
		}

		$options = $this->baseOptions($instance, $targetUserId, $appPassword);
		$options['headers'] = array_merge($options['headers'], $headers);
		$options['body'] = $stream;
		$options['bodySize'] = $size;
		// Large-file transfers must not be capped by the default client timeout.
		$options['timeout'] = max(self::REQUEST_TIMEOUT, (int)ceil($size / (1024 * 1024)) * 2);

		try {
			$this->execute('PUT', $uri, $options);
		} catch (\Exception $e) {
			$status = $this->statusFromException($e);
			$retryable = $status === null || $status >= 500 || $status === 429 || $status === 0;
			throw new TransferException("Upload failed for '{$path}': " . $e->getMessage(), $retryable, $e);
		}
	}

	/**
	 * Creates the server-side staging collection for a chunked upload
	 * (NG Chunking v2 - the same protocol used by the official Nextcloud
	 * desktop and mobile clients). Idempotent: an already-existing
	 * collection (405) from a previous, interrupted attempt is reused as-is
	 * so already-uploaded chunks are preserved for resume.
	 *
	 * @throws RemoteConnectionException
	 */
	public function startChunkedUpload(RemoteInstance $instance, string $targetUserId, string $appPassword, string $transferId): void {
		$uri = $this->buildUploadUri($instance, $targetUserId, $transferId, '');

		try {
			$this->execute('MKCOL', $uri, $this->baseOptions($instance, $targetUserId, $appPassword));
		} catch (\Exception $e) {
			$status = $this->statusFromException($e);
			if ($status === 405) {
				return;
			}
			throw new RemoteConnectionException('Failed to start chunked upload: ' . $e->getMessage(), $status ?? 0, $e);
		}
	}

	/**
	 * Uploads a single chunk. Chunk indexes are zero-padded so the server
	 * assembles them in the correct byte order regardless of upload order.
	 *
	 * @param resource $chunkStream
	 * @throws TransferException
	 */
	public function uploadChunk(
		RemoteInstance $instance,
		string $targetUserId,
		string $appPassword,
		string $transferId,
		int $chunkIndex,
		$chunkStream,
		int $chunkSize,
	): void {
		$uri = $this->buildUploadUri($instance, $targetUserId, $transferId, sprintf('%015d', $chunkIndex));

		$options = $this->baseOptions($instance, $targetUserId, $appPassword);
		$options['body'] = $chunkStream;
		$options['bodySize'] = $chunkSize;
		$options['timeout'] = max(self::REQUEST_TIMEOUT, (int)ceil($chunkSize / (1024 * 1024)) * 2);

		try {
			$this->execute('PUT', $uri, $options);
		} catch (\Exception $e) {
			$status = $this->statusFromException($e);
			$retryable = $status === null || $status >= 500 || $status === 429 || $status === 0;
			throw new TransferException("Chunk {$chunkIndex} upload failed: " . $e->getMessage(), $retryable, $e);
		}
	}

	/**
	 * Checks which chunk indexes have already been fully received by the
	 * server for a given transfer, so a resumed transfer can skip them
	 * instead of re-uploading from scratch.
	 *
	 * @return int[] sorted list of chunk indexes present on the server
	 * @throws RemoteConnectionException
	 */
	public function listUploadedChunks(RemoteInstance $instance, string $targetUserId, string $appPassword, string $transferId): array {
		$uri = $this->buildUploadUri($instance, $targetUserId, $transferId, '');

		$options = $this->baseOptions($instance, $targetUserId, $appPassword);
		$options['headers']['Depth'] = '1';
		$options['headers']['Content-Type'] = 'application/xml; charset=utf-8';
		$options['body'] = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getcontentlength/></d:prop></d:propfind>';

		try {
			$response = $this->execute('PROPFIND', $uri, $options);
		} catch (\Exception $e) {
			$status = $this->statusFromException($e);
			if ($status === 404) {
				// Staging collection doesn't exist (yet): nothing uploaded.
				return [];
			}
			throw new RemoteConnectionException('Failed to list uploaded chunks: ' . $e->getMessage(), $status ?? 0, $e);
		}

		$indexes = [];
		try {
			$doc = new \SimpleXMLElement($response['body']);
			$doc->registerXPathNamespace('d', 'DAV:');
			foreach ($doc->xpath('//d:response/d:href') as $href) {
				$segments = explode('/', rtrim((string)$href, '/'));
				$last = end($segments);
				if (ctype_digit($last)) {
					$indexes[] = (int)$last;
				}
			}
		} catch (\Exception $e) {
			$this->logger->warning('Failed to parse chunk listing response', ['exception' => $e]);
		}

		sort($indexes);

		return $indexes;
	}

	/**
	 * Finalizes a chunked upload by MOVEing the assembled staging collection
	 * onto the destination path. The server concatenates chunks by index
	 * order. Preserves mtime and validates integrity the same way a
	 * single-shot PUT does.
	 *
	 * @throws TransferException
	 */
	public function assembleChunkedUpload(
		RemoteInstance $instance,
		string $targetUserId,
		string $appPassword,
		string $transferId,
		string $destinationPath,
		int $totalSize,
		int $mtime,
		?string $sha256 = null,
	): void {
		$sourceUri = $this->buildUploadUri($instance, $targetUserId, $transferId, '.file');
		$destinationUri = $this->buildUri($instance, $targetUserId, $destinationPath);

		$options = $this->baseOptions($instance, $targetUserId, $appPassword);
		$options['headers']['Destination'] = $destinationUri;
		$options['headers']['X-OC-MTime'] = (string)$mtime;
		$options['headers']['OC-Total-Length'] = (string)$totalSize;
		$options['headers']['Overwrite'] = 'T';
		if ($sha256 !== null) {
			$options['headers']['OC-Checksum'] = 'SHA256:' . $sha256;
		}

		try {
			$this->execute('MOVE', $sourceUri, $options);
		} catch (\Exception $e) {
			$status = $this->statusFromException($e);
			$retryable = $status === null || $status >= 500 || $status === 429 || $status === 0;
			throw new TransferException('Failed to assemble chunked upload: ' . $e->getMessage(), $retryable, $e);
		}
	}

	/**
	 * Best-effort cleanup of a chunked upload's staging collection, e.g.
	 * after a file is permanently abandoned (retries exhausted).
	 */
	public function abortChunkedUpload(RemoteInstance $instance, string $targetUserId, string $appPassword, string $transferId): void {
		try {
			$uri = $this->buildUploadUri($instance, $targetUserId, $transferId, '');
			$this->execute('DELETE', $uri, $this->baseOptions($instance, $targetUserId, $appPassword));
		} catch (\Exception $e) {
			$this->logger->debug('Failed to clean up abandoned chunked upload (non-fatal)', ['exception' => $e]);
		}
	}

	private function buildUploadUri(RemoteInstance $instance, string $targetUserId, string $transferId, string $suffix): string {
		$base = rtrim($instance->getUrl(), '/');
		$user = rawurlencode($targetUserId);
		$path = 'remote.php/dav/uploads/' . $user . '/' . rawurlencode($transferId);
		if ($suffix !== '') {
			$path .= '/' . $suffix;
		}

		return "{$base}/{$path}";
	}

	/**
	 * Downloads a remote file's content solely to compute a checksum for
	 * verification (used as a fallback when the server does not echo back an
	 * OC-Checksum header).
	 *
	 * @throws TransferException
	 */
	public function fetchSha256(RemoteInstance $instance, string $targetUserId, string $appPassword, string $path): string {
		$uri = $this->buildUri($instance, $targetUserId, $path);

		try {
			$response = $this->execute('GET', $uri, $this->baseOptions($instance, $targetUserId, $appPassword));
		} catch (\Exception $e) {
			throw new TransferException("Download for verification failed for '{$path}': " . $e->getMessage(), true, $e);
		}

		return hash('sha256', $response['body']);
	}

	/**
	 * @return array{size:int,etag:?string,checksum:?string}
	 * @throws RemoteConnectionException
	 */
	private function propfind(RemoteInstance $instance, string $targetUserId, string $appPassword, string $path, int $depth): array {
		$uri = $this->buildUri($instance, $targetUserId, $path);

		$body = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:prop>
    <d:getcontentlength/>
    <d:getetag/>
    <oc:checksums/>
    <oc:size/>
  </d:prop>
</d:propfind>
XML;

		$options = $this->baseOptions($instance, $targetUserId, $appPassword);
		$options['headers']['Depth'] = (string)$depth;
		$options['headers']['Content-Type'] = 'application/xml; charset=utf-8';
		$options['body'] = $body;

		try {
			$response = $this->execute('PROPFIND', $uri, $options);
		} catch (\Exception $e) {
			$status = $this->statusFromException($e);
			throw new RemoteConnectionException("PROPFIND failed for '{$path}': " . $e->getMessage(), $status ?? 0, $e);
		}

		return $this->parsePropfindResponse($response['body']);
	}

	/**
	 * @return array{size:int,etag:?string,checksum:?string}
	 */
	private function parsePropfindResponse(string $xml): array {
		$size = 0;
		$etag = null;
		$checksum = null;

		try {
			$doc = new \SimpleXMLElement($xml);
			$doc->registerXPathNamespace('d', 'DAV:');
			$doc->registerXPathNamespace('oc', 'http://owncloud.org/ns');

			$sizeNodes = $doc->xpath('//d:prop/d:getcontentlength');
			if (!empty($sizeNodes)) {
				$size = (int)(string)$sizeNodes[0];
			}

			$etagNodes = $doc->xpath('//d:prop/d:getetag');
			if (!empty($etagNodes)) {
				$etag = trim((string)$etagNodes[0], '"');
			}

			$checksumNodes = $doc->xpath('//d:prop/oc:checksums/oc:checksum');
			if (!empty($checksumNodes)) {
				foreach ($checksumNodes as $node) {
					if (str_starts_with((string)$node, 'SHA256:')) {
						$checksum = substr((string)$node, strlen('SHA256:'));
						break;
					}
				}
			}
		} catch (\Exception $e) {
			$this->logger->warning('Failed to parse PROPFIND response', ['exception' => $e]);
		}

		return ['size' => $size, 'etag' => $etag, 'checksum' => $checksum];
	}

	private function buildUri(RemoteInstance $instance, string $targetUserId, string $path): string {
		$base = rtrim($instance->getUrl(), '/');
		$user = rawurlencode($targetUserId);
		$encodedPath = implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));

		return "{$base}/remote.php/dav/files/{$user}/{$encodedPath}";
	}

	/**
	 * @return array{headers: array<string,string>, auth: array{0:string,1:string}, verify: bool}
	 */
	private function baseOptions(RemoteInstance $instance, string $targetUserId, string $appPassword): array {
		return [
			'headers' => [],
			'auth' => [$targetUserId, $appPassword],
			'verify' => !$instance->getAllowSelfSigned(),
			'timeout' => self::REQUEST_TIMEOUT,
		];
	}

	/**
	 * Executes a raw HTTP/WebDAV request via curl. Bypasses OCP\Http\Client
	 * because its generic multi-verb request() method (needed for PROPFIND,
	 * MKCOL, MOVE) was only added in Nextcloud 29; this keeps the app
	 * working on the full declared range (27-31).
	 *
	 * @param array{headers?: array<string,string>, auth?: array{0:string,1:string}, verify?: bool, timeout?: int, body?: mixed, bodySize?: int} $options
	 * @return array{status: int, body: string, headers: array<string,string>}
	 * @throws \RuntimeException on transport failure or HTTP status >= 400 (code = status, or 0 for transport failures)
	 */
	private function execute(string $method, string $uri, array $options): array {
		// Reuse the handle across calls (curl_reset() clears previously set
		// options but preserves the handle's connection cache) so repeated
		// requests to the same target user within one job execution get
		// HTTP keep-alive instead of a fresh TCP+TLS handshake every time -
		// but never share that connection across a *different* user's
		// credentials (close and reopen instead), so a change of target
		// user always starts on a brand-new connection.
		$userKey = $options['auth'][0] ?? null;
		if ($this->curlHandle !== null && $this->curlHandleUserKey !== $userKey) {
			curl_close($this->curlHandle);
			$this->curlHandle = null;
		}

		if ($this->curlHandle === null) {
			$this->curlHandle = curl_init();
		} else {
			curl_reset($this->curlHandle);
		}
		$this->curlHandleUserKey = $userKey;
		$ch = $this->curlHandle;

		curl_setopt($ch, CURLOPT_URL, $uri);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, $options['timeout'] ?? self::REQUEST_TIMEOUT);
		curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
		$verify = $options['verify'] ?? true;
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

		if (isset($options['auth'])) {
			curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
			curl_setopt($ch, CURLOPT_USERPWD, $options['auth'][0] . ':' . $options['auth'][1]);
		}

		$headerLines = [];
		foreach ($options['headers'] ?? [] as $name => $value) {
			$headerLines[] = "{$name}: {$value}";
		}
		if ($headerLines !== []) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
		}

		$body = $options['body'] ?? null;
		if (is_resource($body)) {
			curl_setopt($ch, CURLOPT_UPLOAD, true);
			curl_setopt($ch, CURLOPT_INFILE, $body);
			if (isset($options['bodySize'])) {
				curl_setopt($ch, CURLOPT_INFILESIZE, $options['bodySize']);
			}
		} elseif ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}

		$responseHeaders = [];
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $headerLine) use (&$responseHeaders) {
			$len = strlen($headerLine);
			$parts = explode(':', $headerLine, 2);
			if (count($parts) === 2) {
				$responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
			}

			return $len;
		});

		$responseBody = curl_exec($ch);
		if ($responseBody === false) {
			$error = curl_error($ch);
			throw new \RuntimeException("WebDAV request failed ({$method} {$uri}): {$error}", 0);
		}

		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if ($status >= 400) {
			throw new \RuntimeException("WebDAV request returned HTTP {$status} ({$method} {$uri})", $status);
		}

		return ['status' => $status, 'body' => (string)$responseBody, 'headers' => $responseHeaders];
	}

	private function statusFromException(\Exception $e): ?int {
		$code = $e->getCode();

		return $code > 0 ? $code : null;
	}
}
