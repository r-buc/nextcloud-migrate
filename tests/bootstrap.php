<?php

declare(strict_types=1);

/**
 * Test bootstrap for the Nextcloud Migrate app.
 *
 * This app's classes are normally autoloaded inside a real Nextcloud server,
 * which provides the OCP\* (and OC\*) namespaces itself. Since these unit
 * tests run standalone (no Nextcloud server available), we register a
 * small autoloader for a handful of minimal stubs under tests/stubs/OCP
 * and tests/stubs/OC - just enough for the classes that our production
 * classes `extends`/`implements`/`new` at load time, plus the interfaces we
 * need to create real PHPUnit mocks of (createMock(X::class) requires X to
 * be a loadable class/interface). OC\* stubs are only for concrete, stable
 * value classes with no OCP-namespaced equivalent (e.g. OC\Files\Search\*)
 * that production code has no choice but to construct directly - this is
 * the standard, documented way apps build a Files API search query.
 *
 * This is intentionally NOT wired through composer.json's autoload-dev to
 * avoid any chance of these stubs ever shadowing the real OCP/OC classes if
 * this app's vendor/ were ever merged into a real Nextcloud install.
 */
require __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
	foreach (['OCP\\', 'OC\\'] as $prefix) {
		if (!str_starts_with($class, $prefix)) {
			continue;
		}

		$relative = substr($class, strlen($prefix));
		$path = __DIR__ . '/stubs/' . rtrim($prefix, '\\') . '/' . str_replace('\\', '/', $relative) . '.php';

		if (is_file($path)) {
			require $path;
		}

		return;
	}
});
