<?php
/**
 * Unit test bootstrap. No WordPress is booted here — only Composer autoload,
 * which lives at the dev-env repo root (this plugin has no composer.json
 * or vendor/ of its own; see README.md's Development section).
 *
 * @package ifthenpay-payments-for-latepoint
 */

// phpcs:ignore Squiz.Commenting.FileComment.Missing -- docblock above is the file comment; the sniff misclassifies it when a require is the first statement.
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Lib files guard on ABSPATH; unit tests never boot WordPress, so satisfy the
// guard without pulling in the real thing.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// WP core time constants some lib/ classes use in cache-TTL calculations.
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
