<?php
/**
 * WP test-suite config. Local dev-only credentials, matching the defaults
 * post-create.sh already provisions — not secrets, overridable via env vars
 * for CI.
 *
 * @package ifthenpay-payments-for-latepoint
 */

define( 'ABSPATH', __DIR__ . '/.wp-core/' );

/**
 * Reads an env var, falling back to a default when unset or empty.
 *
 * @param string $key      Environment variable name.
 * @param string $fallback Value to use when unset or empty.
 * @return string
 */
function ifthenpay_lp_tests_env( string $key, string $fallback ): string {
	$value = getenv( $key );
	return ( false === $value || '' === $value ) ? $fallback : $value;
}

define( 'DB_NAME', ifthenpay_lp_tests_env( 'WP_TEST_DB_NAME', 'wordpress_test' ) );
define( 'DB_USER', ifthenpay_lp_tests_env( 'WP_TEST_DB_USER', 'wpuser' ) );
define( 'DB_PASSWORD', ifthenpay_lp_tests_env( 'WP_TEST_DB_PASS', 'wppass' ) );
define( 'DB_HOST', ifthenpay_lp_tests_env( 'DB_CONTAINER', 'iftp-db' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- this is a wp-config-style test-suite config file; $table_prefix belongs here, matching WP core's own wp-tests-config-sample.php.
$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'localhost' );
define( 'WP_TESTS_EMAIL', 'admin@localhost.test' );
define( 'WP_TESTS_TITLE', 'ifthenpay-payments-for-latepoint test suite' );

define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

define( 'WP_DEBUG', true );
