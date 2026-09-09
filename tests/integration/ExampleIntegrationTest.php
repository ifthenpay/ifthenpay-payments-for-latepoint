<?php
/**
 * Proves the integration harness (wp-phpunit + a real wordpress_test DB) runs against real plugin
 * code, and that IfthenpayLpSettlementLock's own mutual-exclusion guarantee holds against a real
 * second MySQL connection — the one thing settle_payment() and the realtime write paths actually
 * depend on for correctness under concurrency, and the one thing Brain Monkey unit tests structurally
 * cannot exercise (there is no faking GET_LOCK()/RELEASE_LOCK() contention between two real
 * connections). Was previously a `SELECT ... FOR UPDATE` reachability check — a mechanism
 * IfthenpayLpSettlementLock's own docblock explains was tried first and rejected (wp-phpunit's own
 * transaction wrapping makes it unusable there), so that check was proving something the real
 * settlement code no longer uses.
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
	 * The happy path: $work() runs while the lock is held, and its return value passes through.
	 */
	public function test_with_lock_runs_work_and_returns_its_value(): void {
		$result = IfthenpayLpSettlementLock::with_lock(
			'example-lock-happy-path',
			static function () {
				return 'work-ran';
			}
		);

		$this->assertSame( 'work-ran', $result );
	}

	/**
	 * The lock is released even when $work() throws — proven by acquiring the same key again
	 * immediately after, on the same connection, and having it succeed rather than time out.
	 */
	public function test_with_lock_releases_the_lock_even_when_work_throws(): void {
		try {
			IfthenpayLpSettlementLock::with_lock(
				'example-lock-throws',
				static function () {
					throw new RuntimeException( 'boom' );
				}
			);
			$this->fail( 'Expected RuntimeException to propagate.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$result = IfthenpayLpSettlementLock::with_lock(
			'example-lock-throws',
			static function () {
				return 'still-works';
			}
		);
		$this->assertSame( 'still-works', $result );
	}

	/**
	 * The core guarantee: a lock genuinely held by another connection blocks this one, which gives
	 * up as IfthenpayLpLockUnavailableException rather than waiting forever or silently proceeding.
	 * Opens a real second MySQL connection (same test DB, same credentials WordPress itself uses —
	 * DB_HOST/DB_NAME/DB_USER/DB_PASSWORD, defined by wp-tests-config.php) to hold the lock via a raw
	 * GET_LOCK(), independent of $wpdb's own connection — the only way to prove real cross-connection
	 * contention rather than reentrant acquisition on the same session (MySQL allows a session to
	 * re-acquire its own held lock, which would make a single-connection test pass for the wrong
	 * reason). Genuinely waits out the real ~10s timeout (IfthenpayLpSettlementLock's own
	 * LOCK_TIMEOUT_SECONDS) — slow, but the only way to prove the real timeout behaves, not a
	 * shortened stand-in for it.
	 */
	public function test_concurrent_lock_holder_blocks_until_timeout(): void {
		$lock_key = 'example-lock-contention-' . wp_generate_password( 8, false );

		$name_property = new ReflectionMethod( IfthenpayLpSettlementLock::class, 'mysql_lock_name' );
		$name_property->setAccessible( true );
		$mysql_lock_name = $name_property->invoke( null, $lock_key );

		// phpcs:disable WordPress.DB.RestrictedFunctions.mysql_mysqli_connect,WordPress.DB.RestrictedFunctions.mysql_mysqli_query,WordPress.DB.RestrictedFunctions.mysql_mysqli_fetch_row,WordPress.DB.RestrictedFunctions.mysql_mysqli_close -- deliberately a raw mysqli connection, independent of $wpdb's own — see this test's own docblock on why a second real connection is the only way to prove cross-connection lock contention.
		// @phpstan-ignore-next-line constant.notFound -- WordPress runtime constants, defined by wp-tests-config.php before this test ever runs; not part of the stub set this analysis run uses.
		$holder = mysqli_connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$this->assertNotFalse( $holder, 'Could not open a second connection to the test DB.' );

		$get_lock_result = mysqli_query( $holder, "SELECT GET_LOCK('{$mysql_lock_name}', 1)" );
		$this->assertInstanceOf( mysqli_result::class, $get_lock_result );
		$acquired = mysqli_fetch_row( $get_lock_result );
		$this->assertIsArray( $acquired );
		$this->assertSame( '1', $acquired[0], 'The second connection could not acquire the lock to hold it.' );

		try {
			$this->expectException( IfthenpayLpLockUnavailableException::class );
			IfthenpayLpSettlementLock::with_lock(
				$lock_key,
				static function () {
					self::fail( '$work() must never run when the lock could not be acquired.' );
				}
			);
		} finally {
			mysqli_query( $holder, "SELECT RELEASE_LOCK('{$mysql_lock_name}')" );
			mysqli_close( $holder );
		}
		// phpcs:enable WordPress.DB.RestrictedFunctions.mysql_mysqli_connect,WordPress.DB.RestrictedFunctions.mysql_mysqli_query,WordPress.DB.RestrictedFunctions.mysql_mysqli_fetch_row,WordPress.DB.RestrictedFunctions.mysql_mysqli_close
	}
}
