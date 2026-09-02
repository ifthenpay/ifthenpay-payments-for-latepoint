<?php
/**
 * Integration test bootstrap (wp-phpunit). Boots a real WordPress core
 * (tests/.wp-core, staged by post-create.sh) against the wordpress_test DB,
 * then loads LatePoint and this plugin the same way WordPress would.
 *
 * @package ifthenpay-payments-for-latepoint
 */

// phpcs:ignore Squiz.Commenting.FileComment.Missing -- docblock above is the file comment; the sniff misclassifies it when a require is the first statement.
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );

$_wp_phpunit_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $_wp_phpunit_dir ) {
	$_wp_phpunit_dir = dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit';
}

require $_wp_phpunit_dir . '/includes/functions.php';

/**
 * Loads this addon, then LatePoint, via muplugins_loaded — before plugins_loaded, so both files'
 * top-level hook registration runs exactly once.
 *
 * Order matters, and it is the reverse of what "parent, then addon" would suggest: LatePoint's own
 * `do_action( 'latepoint_includes' )` fires synchronously from *its* constructor, at the bottom of
 * its main file — not from a later hook. This addon's constructor is what calls
 * `add_action( 'latepoint_includes', ... )`, so the addon's file has to load, and construct, first,
 * or the action fires into a listener that does not exist yet and `includes()` never runs.
 *
 * This addon's own file also only instantiates itself when `latepoint/latepoint.php` is in the
 * `active_plugins` option (see the guard at the bottom of the main plugin file) — a real site
 * satisfies that through normal plugin activation, so the test DB needs the same option set first.
 *
 * `global` here is what makes `$LATEPOINT_ADDON_PAYMENTS_IFTHENPAY` (assigned inside the required
 * file, at what is normally its own top-level/global scope) actually land in the real global
 * scope instead of staying local to this function — without it, a test reaching for
 * `global $LATEPOINT_ADDON_PAYMENTS_IFTHENPAY;` to call the plugin's own filter callbacks
 * directly (the same way LatePoint's real filter chain would) gets null.
 */
function ifthenpay_lp_tests_load_plugins(): void {
	global $LATEPOINT_ADDON_PAYMENTS_IFTHENPAY;

	update_option( 'active_plugins', array( 'latepoint/latepoint.php' ) );

	require dirname( __DIR__ ) . '/ifthenpay-payments-for-latepoint.php';
	require '/workspace/wp-core/wp-content/plugins/latepoint/latepoint.php';
}
tests_add_filter( 'muplugins_loaded', 'ifthenpay_lp_tests_load_plugins' );

require $_wp_phpunit_dir . '/includes/bootstrap.php';
