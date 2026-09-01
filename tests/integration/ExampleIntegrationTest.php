<?php
/**
 * Proves the integration harness (wp-phpunit + a real wordpress_test DB)
 * runs against real plugin code, and that row locking is reachable — future
 * payment-settlement tests need a real SELECT ... FOR UPDATE, which cannot
 * be faked with mocks.
 *
 * @package ifthenpay-payments-for-latepoint
 */

/**
 * Integration test harness proof.
 */
class ExampleIntegrationTest extends WP_UnitTestCase {

	/**
	 * Asserts the addon's main class loaded via muplugins_loaded.
	 */
	public function test_plugin_class_is_loaded(): void {
		$this->assertTrue( class_exists( 'IfthenpayPaymentsForLatepoint' ) );
	}

	/**
	 * Asserts a real row lock is reachable against the test DB.
	 */
	public function test_select_for_update_is_reachable(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- proving row-locking works needs a real SELECT ... FOR UPDATE; no $wpdb wrapper exists for it, and it must not be cached.
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->get_row( "SELECT ID FROM {$wpdb->posts} LIMIT 1 FOR UPDATE" );
		$error = $wpdb->last_error;
		$wpdb->query( 'COMMIT' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		$this->assertSame( '', $error );
	}
}
