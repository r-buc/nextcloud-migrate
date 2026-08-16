<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service;

use OCA\NextcloudMigrate\Db\RemoteInstance;
use OCA\NextcloudMigrate\Exception\RemoteConnectionException;

/**
 * Talks to the TARGET instance's OCS Share API
 * (apps/files_sharing/api/v1/shares), authenticated as the mapped user's
 * OWN app password (from UserMap) - unlike ProvisioningClient, which uses
 * the instance's admin credential. A share is always owned by whichever
 * credential creates it via this endpoint, so recreating a share as the
 * correct target owner requires authenticating as that specific user;
 * there is no admin-bypass to create a share "on behalf of" someone else.
 *
 * Structurally mirrors ProvisioningClient's curl-based request() (this
 * app has no shared HTTP base class - see WebDavClient/ProvisioningClient,
 * which are likewise independent).
 */
class SharingClient {
	private const REQUEST_TIMEOUT = 30;
	private const ERROR_BODY_SNIPPET_LENGTH = 300;

	/**
	 * @return array<string,mixed> the created share's OCS data (includes 'id')
	 * @throws RemoteConnectionException
	 */
	public function createShare(
		RemoteInstance $instance,
		string $targetUserId,
		string $appPassword,
		string $path,
		int $shareType,
		?string $shareWith,
		int $permissions,
		?int $expiration = null,
		?string $label = null,
	): array {
		$form = [
			'path' => $path,
			'shareType' => (string)$shareType,
			'permissions' => (string)$permissions,
		];
		if ($shareWith !== null && $shareWith !== '') {
			$form['shareWith'] = $shareWith;
		}
		if ($expiration !== null) {
			$form['expireDate'] = gmdate('Y-m-d', $expiration);
		}
		if ($label !== null && $label !== '') {
			$form['label'] = $label;
		}

		$data = $this->request('POST', $instance, $targetUserId, $appPassword, 'apps/files_sharing/api/v1/shares', $form);

		$share = $data['ocs']['data'] ?? null;
		if (!is_array($share)) {
			throw new RemoteConnectionException('Unexpected response creating share', 0);
		}

		return $share;
	}

	/**
	 * @param array<string,string> $formFields
	 * @return array<string,mixed> decoded JSON response body
	 * @throws RemoteConnectionException
	 */
	private function request(string $method, RemoteInstance $instance, string $userId, string $appPassword, string $ocsPath, array $formFields): array {
		$base = rtrim($instance->getUrl(), '/');
		$uri = "{$base}/ocs/v1.php/{$ocsPath}?format=json";

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $uri);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$instance->getAllowSelfSigned());
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $instance->getAllowSelfSigned() ? 0 : 2);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD, $userId . ':' . $appPassword);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'OCS-APIRequest: true',
			'Accept: application/json',
		]);
		if ($formFields !== []) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($formFields));
		}

		$body = curl_exec($ch);
		if ($body === false) {
			$error = curl_error($ch);
			curl_close($ch);
			throw new RemoteConnectionException("Share API request failed ({$method} {$ocsPath}): {$error}", 0);
		}

		$httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($httpStatus >= 400) {
			throw new RemoteConnectionException("Share API returned HTTP {$httpStatus} ({$method} {$ocsPath}): " . $this->summarizeErrorBody((string)$body), $httpStatus);
		}

		$data = json_decode((string)$body, true);
		if (!is_array($data)) {
			throw new RemoteConnectionException("Share API returned an invalid response ({$method} {$ocsPath})", 0);
		}

		$statusCode = $data['ocs']['meta']['statuscode'] ?? null;
		if ($statusCode !== 100 && $statusCode !== 200) {
			$message = $data['ocs']['meta']['message'] ?? 'Unknown Share API error';
			throw new RemoteConnectionException("Share API error ({$method} {$ocsPath}): {$message}", (int)($statusCode ?? 0));
		}

		return $data;
	}

	private function summarizeErrorBody(string $body): string {
		$trimmed = trim($body);
		if ($trimmed === '') {
			return '(empty response body)';
		}

		$data = json_decode($trimmed, true);
		$message = $data['ocs']['meta']['message'] ?? null;
		if (is_string($message) && $message !== '') {
			return $message;
		}

		$snippet = trim(preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed);

		return mb_strlen($snippet) > self::ERROR_BODY_SNIPPET_LENGTH
			? mb_substr($snippet, 0, self::ERROR_BODY_SNIPPET_LENGTH) . '…'
			: $snippet;
	}
}
