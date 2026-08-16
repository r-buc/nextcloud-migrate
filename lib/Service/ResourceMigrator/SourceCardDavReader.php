<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Service\ResourceMigrator;

/**
 * Reads a SOURCE (local) user's own address books/contacts directly via
 * Nextcloud's CardDAV Sabre backend, in-process - not over HTTP. This app
 * runs ON the source instance, and there is no admin-bypass over CardDAV
 * (verified empirically: Basic-Auth as an admin against another user's
 * addressbook principal returns a SabreDAV 500, same as Files WebDAV has
 * no bypass), so the only user this app could otherwise authenticate as
 * over HTTP would need that user's own live credential, which this app
 * never collects for source users. Reading directly via the `dav` app's
 * own backend class - the same one Nextcloud's own CardDAV server uses -
 * sidesteps that entirely, the same way DiscoveryService reads source
 * files via OCP\Files rather than WebDAV.
 *
 * `\OCA\DAV\CardDAV\CardDavBackend` is an OCA-internal (not OCP) class,
 * but `dav` is a mandatory, always-enabled shipped core app, so depending
 * on it is about as safe as depending on OCP in practice. Resolved lazily
 * via `\OCP\Server::get()` (rather than constructor-injected) so this
 * class - and anything depending on it - stays trivially mockable in unit
 * tests without needing stub files for OCA\DAV's large internal API.
 */
class SourceCardDavReader {
	// Real personal address books are always created via
	// CardDavBackend::createAddressBook(), which never produces these
	// prefixes - reserved for Nextcloud's own auto-generated system
	// collections (the federated-contacts "Accounts" book and the
	// contactsinteraction app's "Recently contacted" book, verified
	// against a live instance). Applied defensively in case
	// getUsersOwnAddressBooks() ever starts including them.
	private const SYSTEM_URI_PREFIXES = ['z-server-generated--', 'z-app-generated--'];

	/**
	 * @return array<int, array{id:int, uri:string, displayName:string}>
	 */
	public function listOwnedAddressBooks(string $sourceUserId): array {
		$principal = 'principals/users/' . $sourceUserId;
		$books = $this->backend()->getUsersOwnAddressBooks($principal);

		$result = [];
		foreach ($books as $book) {
			$uri = (string)$book['uri'];
			if ($this->isSystemUri($uri)) {
				continue;
			}
			$result[] = [
				'id' => (int)$book['id'],
				'uri' => $uri,
				'displayName' => (string)($book['{DAV:}displayname'] ?? $uri),
			];
		}

		return $result;
	}

	/**
	 * @return array<int, array{uri:string, cardData:string}>
	 */
	public function listCards(int $addressBookId): array {
		$cards = $this->backend()->getCards($addressBookId);

		return array_map(static fn (array $card): array => [
			'uri' => (string)$card['uri'],
			'cardData' => is_resource($card['carddata']) ? stream_get_contents($card['carddata']) : (string)$card['carddata'],
		], $cards);
	}

	private function isSystemUri(string $uri): bool {
		foreach (self::SYSTEM_URI_PREFIXES as $prefix) {
			if (str_starts_with($uri, $prefix)) {
				return true;
			}
		}

		return false;
	}

	private function backend(): \OCA\DAV\CardDAV\CardDavBackend {
		return \OCP\Server::get(\OCA\DAV\CardDAV\CardDavBackend::class);
	}
}
