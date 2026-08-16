<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service\ResourceMigrator;

/**
 * Reads a SOURCE (local) user's own calendars/events directly via
 * Nextcloud's CalDAV Sabre backend, in-process - not over HTTP. Mirrors
 * SourceCardDavReader's reasoning exactly (see its docblock): no admin
 * bypass exists over CalDAV either, and this app never collects a live
 * credential for source users, so reading via `dav`'s own backend class
 * (resolved lazily via `\OCP\Server::get()`) is the only viable approach -
 * the same way DiscoveryService reads source files via OCP\Files.
 */
class SourceCalendarReader {
	// CalDavBackend::getUsersOwnCalendars() already excludes shared-to-them
	// calendars (unlike getCalendarsForUser()) and inbox/outbox/trashbin
	// (those are virtual Sabre nodes, never real `calendars` table rows).
	// The auto-generated birthday calendar and soft-deleted (trashed)
	// calendars ARE real rows though, so they're filtered out explicitly
	// below (verified against a live instance).
	private const BIRTHDAY_CALENDAR_URI = 'contact_birthdays';
	private const DELETED_AT_PROPERTY = '{http://nextcloud.com/ns}deleted-at';

	/**
	 * @return array<int, array{id:int, uri:string, displayName:string}>
	 */
	public function listOwnedCalendars(string $sourceUserId): array {
		$principal = 'principals/users/' . $sourceUserId;
		$calendars = $this->backend()->getUsersOwnCalendars($principal);

		$result = [];
		foreach ($calendars as $calendar) {
			$uri = (string)$calendar['uri'];
			if ($uri === self::BIRTHDAY_CALENDAR_URI) {
				continue;
			}
			if (($calendar[self::DELETED_AT_PROPERTY] ?? null) !== null) {
				continue;
			}
			$result[] = [
				'id' => (int)$calendar['id'],
				'uri' => $uri,
				'displayName' => (string)($calendar['{DAV:}displayname'] ?? $uri),
			];
		}

		return $result;
	}

	/**
	 * @return array<int, array{uri:string, calendarData:string}>
	 */
	public function listCalendarObjects(int $calendarId): array {
		$backend = $this->backend();
		$result = [];
		foreach ($backend->getCalendarObjects($calendarId) as $object) {
			$full = $backend->getCalendarObject($calendarId, (string)$object['uri']);
			if ($full === null) {
				continue;
			}
			$result[] = [
				'uri' => (string)$full['uri'],
				'calendarData' => is_resource($full['calendardata']) ? stream_get_contents($full['calendardata']) : (string)$full['calendardata'],
			];
		}

		return $result;
	}

	private function backend(): \OCA\DAV\CalDAV\CalDavBackend {
		return \OCP\Server::get(\OCA\DAV\CalDAV\CalDavBackend::class);
	}
}
