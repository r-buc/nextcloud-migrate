<?php

declare(strict_types=1);

/**
 * Test bootstrap for the Nextcloud Migrate app.
 *
 * This app's classes are normally autoloaded inside a real Nextcloud server,
 * which provides the OCP\* namespace itself (never via this app's own
 * composer.json). Since these unit tests run standalone (no Nextcloud
 * server available), we register a small autoloader for a handful of
 * minimal OCP stubs under tests/stubs/OCP - just enough for the classes
 * that our production classes `extends`/`implements` at load time, plus the
 * interfaces we need to create real PHPUnit mocks of (createMock(X::class)
 * requires X to be a loadable class/interface).
 *
 * This is intentionally NOT wired through composer.json's autoload-dev to
 * avoid any chance of these stubs ever shadowing the real OCP classes if
 * this app's vendor/ were ever merged into a real Nextcloud install.
 */
require __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
	if (!str_starts_with($class, 'OCP\\')) {
		return;
	}

	$relative = substr($class, strlen('OCP\\'));
	$path = __DIR__ . '/stubs/OCP/' . str_replace('\\', '/', $relative) . '.php';

	if (is_file($path)) {
		require $path;
	}
});
