<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service;

use OCA\NextcloudMigrate\Db\RemoteInstance;
use OCA\NextcloudMigrate\Exception\RemoteConnectionException;

/**
 * Talks to the TARGET instance's OCS Provisioning API using the admin
 * credential stored on RemoteInstance (adminUserId + encrypted app
 * password) for user creation/password reset/listing - never for WebDAV
 * file transfer (see RemoteInstance docblock for why). `generateAppPassword()`
 * is the one exception: it authenticates as the TARGET user's own
 * (temporary) account credential instead of the admin's, to mint that
 * user's dedicated app password token.
 *
 * Backs the default "auto" user-mapping mode: create the target user
 * account if it doesn't exist yet, or reset its password if it does, then
 * immediately exchange that (temporary) account password for a dedicated
 * app password via `generateAppPassword()` - so a per-file-transfer app
 * password is always available without the admin having to obtain one
 * manually from each target user (see "expert mode" for that manual
 * path), and so the account password can be changed again afterwards
 * without invalidating an in-progress migration or continuous sync.
 */
class ProvisioningClient {
	private const REQUEST_TIMEOUT = 30;
	// How much of a failed request's response body to include in exception
	// messages (see summarizeErrorBody()) when it's not the usual OCS JSON
	// envelope - just enough to be useful without dumping an entire error
	// page into logs/lastError.
	private const ERROR_BODY_SNIPPET_LENGTH = 300;

	/**
	 * Confirms the admin credential is valid and has provisioning rights by
	 * listing remote users.
	 *
	 * @return string[] remote user ids
	 * @throws RemoteConnectionException
	 */
	public function listUsers(RemoteInstance $instance, string $adminUserId, string $adminAppPassword): array {
		$data = $this->request('GET', $instance, $adminUserId, $adminAppPassword, 'cloud/users', []);

		$users = $data['ocs']['data']['users'] ?? null;
		if (!is_array($users)) {
			throw new RemoteConnectionException('Unexpected response listing remote users', 0);
		}

		return array_values(array_map('strval', $users));
	}

	/**
	 * Creates a new user account on the target instance with the given
	 * initial password.
	 *
	 * @throws RemoteConnectionException
	 */
	public function createUser(RemoteInstance $instance, string $adminUserId, string $adminAppPassword, string $targetUserId, string $password): void {
		$this->request('POST', $instance, $adminUserId, $adminAppPassword, 'cloud/users', [
			'userid' => $targetUserId,
			'password' => $password,
		]);
	}

	/**
	 * Resets an existing target user's password to a freshly generated
	 * value, so it can be used as that user's WebDAV credential for the
	 * migration.
	 *
	 * @throws RemoteConnectionException
	 */
	public function resetUserPassword(RemoteInstance $instance, string $adminUserId, string $adminAppPassword, string $targetUserId, string $newPassword): void {
		$this->editUserField($instance, $adminUserId, $adminAppPassword, $targetUserId, 'password', $newPassword);
	}

	/**
	 * Fetches a target user's current profile fields - used by
	 * UserInfoMigrationService to both re-check what's already on the
	 * target and to verify a just-applied edit actually took effect.
	 *
	 * @return array{displayname:?string, email:?string, quota:?string, language:?string, groups:string[]}
	 * @throws RemoteConnectionException
	 */
	public function getUser(RemoteInstance $instance, string $adminUserId, string $adminAppPassword, string $targetUserId): array {
		$data = $this->request('GET', $instance, $adminUserId, $adminAppPassword, 'cloud/users/' . rawurlencode($targetUserId), []);

		$userData = $data['ocs']['data'] ?? null;
		if (!is_array($userData)) {
			throw new RemoteConnectionException('Unexpected response fetching remote user details', 0);
		}

		// The provisioning API returns quota as a structure (free/used/
		// total/relative/quota), not the human-readable string editUserField()
		// accepts - not meaningfully comparable to the source's IUser::getQuota()
		// format, so UserInfoMigrationService doesn't attempt to verify it.
		return [
			'displayname' => isset($userData['displayname']) ? (string)$userData['displayname'] : null,
			'email' => isset($userData['email']) ? (string)$userData['email'] : null,
			'quota' => isset($userData['quota']['quota']) ? (string)$userData['quota']['quota'] : null,
			'language' => isset($userData['language']) ? (string)$userData['language'] : null,
			'groups' => isset($userData['groups']) && is_array($userData['groups']) ? array_values(array_map('strval', $userData['groups'])) : [],
		];
	}

	/**
	 * Edits a single profile field of a target user (key ∈ displayname/
	 * email/quota/language/password/phone/... - see the OCS Provisioning
	 * API's editUser endpoint). Generalizes what resetUserPassword() does
	 * for the 'password' key specifically.
	 *
	 * @throws RemoteConnectionException
	 */
	public function editUserField(RemoteInstance $instance, string $adminUserId, string $adminAppPassword, string $targetUserId, string $key, string $value): void {
		$this->request('PUT', $instance, $adminUserId, $adminAppPassword, 'cloud/users/' . rawurlencode($targetUserId), [
			'key' => $key,
			'value' => $value,
		]);
	}

	/**
	 * Creates a group on the target instance if it doesn't already exist.
	 * Idempotent: the provisioning API's "group already exists" statuscode
	 * (102) is treated as success rather than an error, since
	 * UserInfoMigrationService calls this unconditionally before adding a
	 * user to a group it may not be the first mapped user to reference.
	 *
	 * @throws RemoteConnectionException
	 */
	public function ensureGroupExists(RemoteInstance $instance, string $adminUserId, string $adminAppPassword, string $groupId): void {
		try {
			$this->request('POST', $instance, $adminUserId, $adminAppPassword, 'cloud/groups', ['groupid' => $groupId]);
		} catch (RemoteConnectionException $e) {
			if ($e->getCode() !== 102) {
				throw $e;
			}
		}
	}

	/**
	 * @throws RemoteConnectionException
	 */
	public function addUserToGroup(RemoteInstance $instance, string $adminUserId, string $adminAppPassword, string $targetUserId, string $groupId): void {
		$this->request('POST', $instance, $adminUserId, $adminAppPassword, 'cloud/users/' . rawurlencode($targetUserId) . '/groups', ['groupid' => $groupId]);
	}

	/**
	 * Exchanges a target user's own current account credential (typically
	 * a temporary password just set via createUser()/resetUserPassword())
	 * for a dedicated, independent app password token
	 * (`core/getapppassword` - the same mechanism a real desktop/mobile
	 * client login flow ultimately mints, and the one exposed in that
	 * user's own "Devices & sessions" settings). This call authenticates
	 * as the TARGET user, not the admin - unlike every other method here.
	 *
	 * Used so ongoing transfer/verification/continuous-sync only ever
	 * depends on this dedicated token, never on the account password
	 * itself: app password tokens are independent auth entries
	 * (`oc_authtoken` rows), not derived from the account password used to
	 * create them, so the target user's account password can be changed
	 * afterwards (by them, or by a later re-run of the auto flow for a
	 * different run) without invalidating an in-progress migration or an
	 * active continuous sync.
	 *
	 * @throws RemoteConnectionException
	 */
	public function generateAppPassword(RemoteInstance $instance, string $targetUserId, string $accountPassword): string {
		$data = $this->request('GET', $instance, $targetUserId, $accountPassword, 'core/getapppassword', []);

		$appPassword = $data['ocs']['data']['apppassword'] ?? null;
		if (!is_string($appPassword) || $appPassword === '') {
			throw new RemoteConnectionException('Unexpected response generating an app password', 0);
		}

		return $appPassword;
	}

	/**
	 * @param array<string,string> $formFields
	 * @return array<string,mixed> decoded JSON response body
	 * @throws RemoteConnectionException
	 */
	private function request(string $method, RemoteInstance $instance, string $adminUserId, string $adminAppPassword, string $ocsPath, array $formFields): array {
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
		curl_setopt($ch, CURLOPT_USERPWD, $adminUserId . ':' . $adminAppPassword);
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
			throw new RemoteConnectionException("Provisioning API request failed ({$method} {$ocsPath}): {$error}", 0);
		}

		$httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($httpStatus >= 400) {
			// The OCS API usually still returns its normal JSON envelope
			// (ocs.meta.message) even on a 4xx/5xx HTTP status - previously
			// the body was discarded entirely here, so a failure only ever
			// surfaced as a bare "HTTP 400" with no indication of why
			// (e.g. invalid username, policy violation) anywhere in the
			// logs. Fall back to a raw snippet if it's not that shape.
			throw new RemoteConnectionException("Provisioning API returned HTTP {$httpStatus} ({$method} {$ocsPath}): " . $this->summarizeErrorBody((string)$body), $httpStatus);
		}

		$data = json_decode((string)$body, true);
		if (!is_array($data)) {
			throw new RemoteConnectionException("Provisioning API returned an invalid response ({$method} {$ocsPath})", 0);
		}

		$statusCode = $data['ocs']['meta']['statuscode'] ?? null;
		// 100 = OK. Provisioning API-specific statuscodes (not HTTP status)
		// signal failures such as "user already exists" (102) even on HTTP 200/201.
		if ($statusCode !== 100 && $statusCode !== 200) {
			$message = $data['ocs']['meta']['message'] ?? 'Unknown provisioning API error';
			throw new RemoteConnectionException("Provisioning API error ({$method} {$ocsPath}): {$message}", (int)($statusCode ?? 0));
		}

		return $data;
	}

	/**
	 * Extracts a short, actionable message from a failed OCS API response
	 * body: prefers the standard ocs.meta.message field, falling back to a
	 * truncated raw snippet if the body isn't valid JSON in that shape
	 * (e.g. an HTML error page from a proxy/load balancer in front of the
	 * target instance).
	 */
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
