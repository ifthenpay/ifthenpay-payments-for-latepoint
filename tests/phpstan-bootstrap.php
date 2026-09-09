<?php
/**
 * PHPStan-only bootstrap for analysing tests/ in isolation.
 *
 * The plugin has no autoload (no Composer, no PSR-4 — see README.md's Development section), so a
 * class only ever `require`'d at runtime, via the plugin's own includes(), is otherwise invisible
 * to PHPStan when it analyses tests/ on its own. Requiring every lib/ file here — for PHPStan
 * only, never loaded at runtime — makes those classes resolvable without a class.notFound ignore
 * on every line that references one.
 *
 * @package ifthenpay-payments-for-latepoint
 */

// Every lib/ file guards on ABSPATH and calls exit() if it's undefined — which, run in
// PHPStan's own process rather than WordPress, would silently kill the whole analysis.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$lib_files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( __DIR__ . '/../lib', FilesystemIterator::SKIP_DOTS )
);

foreach ( $lib_files as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}

	// A file that extends a LatePoint core class (e.g. the controller, extending
	// OsController) fatals here — LatePoint itself isn't loaded in this PHPStan-only
	// process. Skip it rather than fail the whole bootstrap; the rest of lib/ still loads.
	try {
		require_once $file->getPathname();
	} catch ( \Throwable $e ) {
		continue;
	}
}

// The main plugin class itself (IfthenpayPaymentsForLatepoint) — outside lib/, so the loop above
// never reaches it. Its own bottom-of-file guard calls get_option(), undefined here, and throws;
// by then the class declaration above it has already registered, so the catch below only
// discards that one unreachable-in-this-process side effect, not the class itself.
try {
	require_once __DIR__ . '/../ifthenpay-payments-for-latepoint.php';
} catch ( \Throwable $e ) {
	unset( $e );
}
