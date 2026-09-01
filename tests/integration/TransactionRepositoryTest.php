<?php
/**
 * IfthenpayLpTransactionRepository — the new {prefix}ifthenpay_transactions table (003 T-02b).
 * The schema is created by the plugin's own `init` hook (maybe_upgrade_schema), exactly as it
 * would be on a real site, so this also proves that wiring works end to end.
 *
 * @package ifthenpay-payments-for-latepoint
 */

/**
 * IfthenpayLpTransactionRepository harness proof.
 */
class TransactionRepositoryTest extends WP_UnitTestCase {

	/**
	 * Asserts the UNIQUE(request_id) index rejects a second row with the same request_id.
	 */
	public function test_duplicate_request_id_is_refused_by_the_database(): void {
		// @phpstan-ignore-next-line class.notFound (loaded via the plugin's own `latepoint_includes` bootstrap; tests/ is analysed on its own, so PHPStan can't see it here)
		$first = IfthenpayLpTransactionRepository::insert(
			array(
				'token'      => 'tok-' . wp_generate_password( 12, false ),
				'request_id' => 'SHARED-REQUEST-ID',
				'intent_id'  => 1,
				'kind'       => 'deferred',
				'method'     => 'MB',
			)
		);
		$this->assertNotFalse( $first );

		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		// @phpstan-ignore-next-line class.notFound (see note above)
		$second   = IfthenpayLpTransactionRepository::insert(
			array(
				'token'      => 'tok-' . wp_generate_password( 12, false ),
				'request_id' => 'SHARED-REQUEST-ID',
				'intent_id'  => 2,
				'kind'       => 'deferred',
				'method'     => 'MB',
			)
		);
		$wpdb->suppress_errors( $suppress );

		$this->assertFalse( $second );
	}

	/**
	 * Asserts a record can be found by request_id alone, method unknown ahead of time.
	 */
	public function test_settlement_lookup_by_request_id_needs_no_method_knowledge(): void {
		// @phpstan-ignore-next-line class.notFound (see note above)
		IfthenpayLpTransactionRepository::insert(
			array(
				'token'      => 'tok-' . wp_generate_password( 12, false ),
				'request_id' => 'LOOKUP-REQUEST-ID',
				'intent_id'  => 3,
				'kind'       => 'deferred',
				'method'     => 'PAYSHOP',
			)
		);

		// The caller does not know — and does not need to know — that this record is a Payshop
		// payment before looking it up; the method comes back as data on the found record.
		// @phpstan-ignore-next-line class.notFound (see note above)
		$found = IfthenpayLpTransactionRepository::find_by_request_id( 'LOOKUP-REQUEST-ID' );

		$this->assertNotNull( $found );
		$this->assertSame( 'PAYSHOP', $found->method );
		$this->assertSame( 'PENDING', $found->status );
	}
}
