<?php
/**
 * Proves the 003 T-02c upgrade path: a still-PENDING row from the old, single-purpose
 * ifthenpay_payments table settles through the new table after upgrade, and the old table is
 * renamed to _legacy — never dropped — so already-settled history stays readable.
 *
 * @package ifthenpay-payments-for-latepoint
 */

/**
 * Legacy-table migration and retirement proof.
 */
class TransactionRepositoryMigrationTest extends WP_UnitTestCase {

	/**
	 * The DDL fixture below runs with wp-phpunit's temp-table rewrite disabled, so it is real,
	 * permanent DDL — and DDL causes an implicit commit in MySQL/InnoDB, which silently ends the
	 * per-test wrapping transaction partway through the test. Everything after that point,
	 * including the migration's own INSERT into the real (not per-test) ifthenpay_transactions
	 * table, is committed for real too — not rolled back like ordinary DML. Clean up both: the
	 * fixture tables themselves, and the row this test's migration inserts into the permanent
	 * table, or it collides with itself on the next run.
	 */
	public function tearDown(): void {
		global $wpdb;
		$old_table = $wpdb->prefix . 'ifthenpay_payments';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test cleanup only; $old_table has no user-controlled part, and the token literal below is this test's own fixture value.
		$wpdb->query( "DROP TABLE IF EXISTS `{$old_table}`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$old_table}_legacy`" );
		$wpdb->delete( $wpdb->prefix . 'ifthenpay_transactions', array( 'token' => 'legacy-inflight' ) );
		// phpcs:enable
		parent::tearDown();
	}

	/**
	 * Asserts an in-flight legacy payment still settles, and settled history survives the
	 * rename instead of being dropped.
	 */
	public function test_legacy_pending_rows_migrate_and_old_table_is_renamed_not_dropped(): void {
		global $wpdb;

		$old_table    = $wpdb->prefix . 'ifthenpay_payments';
		$legacy_table = $old_table . '_legacy';

		// wp-phpunit rewrites CREATE/DROP TABLE to CREATE/DROP TEMPORARY TABLE by default (so
		// tests can't leave real tables behind) — but that makes the fixture invisible to the
		// plain SHOW TABLES the migration itself uses, which is exactly what a real, permanent
		// v2.1.x site's table is. Disable the rewrite for this one fixture.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- simulating the real v2.1.x table this test upgrades from; table names can't be placeholders and $old_table/$legacy_table have no user-controlled part.
		$wpdb->query( "DROP TABLE IF EXISTS `{$legacy_table}`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$old_table}`" );
		$wpdb->query(
			"CREATE TABLE `{$old_table}` (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				token VARCHAR(255) NOT NULL,
				intent_id BIGINT UNSIGNED NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
				paybylink_url VARCHAR(255) DEFAULT NULL,
				transaction_id VARCHAR(255) DEFAULT NULL,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY token (token)
			) {$wpdb->get_charset_collate()}"
		);
		// phpcs:enable

		// An in-flight payment (PENDING) and a settled one (PAID) — same shape a real v2.1.x
		// site has at upgrade time.
		$wpdb->insert(
			$old_table,
			array(
				'token'         => 'legacy-inflight',
				'intent_id'     => 101,
				'status'        => 'PENDING',
				'paybylink_url' => 'https://pay.example/legacy-inflight',
			)
		);
		$wpdb->insert(
			$old_table,
			array(
				'token'          => 'legacy-settled',
				'intent_id'      => 102,
				'status'         => 'PAID',
				'transaction_id' => 'TXN-OLD-1',
			)
		);

		// Force the plugin's real upgrade path to run again, as it would on an in-place update.
		delete_option( 'ifthenpay_lp_transactions_schema_version' );
		IfthenpayLpTransactionRepository::maybe_upgrade_schema();

		// The in-flight payment still settles: it exists in the new table, findable by token.
		$migrated = IfthenpayLpTransactionRepository::find_by_token( 'legacy-inflight' );
		$this->assertNotNull( $migrated );
		// @phpstan-ignore-next-line property.notFound (find_by_token() returns a raw $wpdb->get_row() stdClass, same untyped-object pattern the rest of the codebase uses)
		$this->assertSame( 'realtime', $migrated->kind );
		// @phpstan-ignore-next-line property.notFound (see note above)
		$this->assertSame( IfthenpayLpTransactionRepository::METHOD_PAYBYLINK, $migrated->method );
		// @phpstan-ignore-next-line property.notFound (see note above)
		$this->assertSame( 'PENDING', $migrated->status );
		// @phpstan-ignore-next-line property.notFound (see note above)
		$this->assertSame( 'https://pay.example/legacy-inflight', $migrated->paybylink_url );

		// The already-settled row is not duplicated into the new table...
		$this->assertNull( IfthenpayLpTransactionRepository::find_by_token( 'legacy-settled' ) );

		// ...but stays readable: the old table was renamed, not dropped.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- reading back the migration's own effect; $legacy_table has no user-controlled part.
		$legacy_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$legacy_table}` WHERE token = %s", 'legacy-settled' ) );
		// phpcs:enable
		$this->assertNotNull( $legacy_row );
		$this->assertSame( 'PAID', $legacy_row->status );

		$table_still_present = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) );
		$this->assertNull( $table_still_present );
	}
}
