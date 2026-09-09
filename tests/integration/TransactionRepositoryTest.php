<?php
/**
 * IfthenpayLpTransactionRepository — the new {prefix}ifthenpay_transactions table.
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
		$found = IfthenpayLpTransactionRepository::find_by_request_id( 'LOOKUP-REQUEST-ID' );

		$this->assertNotNull( $found );
		$this->assertSame( 'PAYSHOP', $found->method ); // @phpstan-ignore-line property.notFound
		$this->assertSame( 'PENDING', $found->status ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * The mark_settled() call stamps status, settled_at, AND settled_by together — a real,
	 * queryable column, not something buried in method_data — so "how many payments settled via
	 * callback vs. polling vs. manual" is a plain SQL question, not a per-row JSON decode.
	 */
	public function test_mark_settled_stamps_the_real_source_column(): void {
		IfthenpayLpTransactionRepository::insert(
			array(
				'token'      => 'tok-settled-by-manual',
				'request_id' => 'REQ-SETTLED-BY-MANUAL',
				'intent_id'  => 4,
				'kind'       => 'deferred',
				'method'     => 'MB',
			)
		);

		IfthenpayLpTransactionRepository::mark_settled( 'tok-settled-by-manual', 'manual' );

		$record = IfthenpayLpTransactionRepository::find_by_token( 'tok-settled-by-manual' );
		$this->assertSame( 'PAID', $record->status ); // @phpstan-ignore-line property.notFound
		$this->assertNotNull( $record->settled_at ); // @phpstan-ignore-line property.notFound
		$this->assertSame( 'manual', $record->settled_by ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * The record_verification() call keeps the endpoint's own raw, decoded response verbatim in method_data
	 * (`ifthenpay_response`), alongside the narrower `verified_*` fields already derived from it —
	 * a support/dispute lookup needing to see exactly what ifthenpay returned shouldn't have to
	 * trust this add-on's own interpretation of it. Also proves settled_by (not method_data) is
	 * where the source lands once $settle is true.
	 */
	public function test_record_verification_keeps_the_raw_endpoint_response(): void {
		IfthenpayLpTransactionRepository::insert(
			array(
				'token'     => 'tok-raw-response',
				'intent_id' => 5,
				'kind'      => 'realtime',
				'method'    => 'PAYBYLINK',
			)
		);

		$confirmation = (object) array(
			'payment_method' => 'MBWAY',
			'amount'         => '25.00',
			'order_id'       => 'tok-raw-response',
			'raw'            => array(
				'TransactionId' => 'TXID-RAW-001',
				'PaymentMethod' => 'MBWAY',
				'Amount'        => '25.00',
				'OrderId'       => 'tok-raw-response',
			),
		);

		IfthenpayLpTransactionRepository::record_verification( 'tok-raw-response', 'TXID-RAW-001', $confirmation, 'polling', true );

		$record      = IfthenpayLpTransactionRepository::find_by_token( 'tok-raw-response' );
		$method_data = IfthenpayLpTransactionRepository::decode_method_data( $record ); // @phpstan-ignore-line argument.type

		$this->assertSame( 'PAID', $record->status ); // @phpstan-ignore-line property.notFound
		$this->assertSame( 'polling', $record->settled_by ); // @phpstan-ignore-line property.notFound
		$this->assertSame( 'TXID-RAW-001', $method_data['transaction_id'] );
		$this->assertSame(
			array(
				'TransactionId' => 'TXID-RAW-001',
				'PaymentMethod' => 'MBWAY',
				'Amount'        => '25.00',
				'OrderId'       => 'tok-raw-response',
			),
			$method_data['ifthenpay_response']
		);
	}

	/**
	 * A confirmation with no `raw` property (the callback route's own locally-built stand-in, never
	 * fetched from an endpoint) leaves `ifthenpay_response` alone rather than clobbering it with
	 * null — relevant when a row already carries one from an earlier polling attempt.
	 */
	public function test_record_verification_does_not_clobber_a_previous_raw_response_when_none_is_given(): void {
		IfthenpayLpTransactionRepository::insert(
			array(
				'token'       => 'tok-preserve-raw',
				'intent_id'   => 6,
				'kind'        => 'realtime',
				'method'      => 'PAYBYLINK',
				'method_data' => wp_json_encode( array( 'ifthenpay_response' => array( 'TransactionId' => 'TXID-EARLIER' ) ) ),
			)
		);

		$confirmation = (object) array(
			'payment_method' => 'MBWAY',
			'amount'         => '25.00',
			'order_id'       => 'tok-preserve-raw',
		);

		IfthenpayLpTransactionRepository::record_verification( 'tok-preserve-raw', 'REQ-PRESERVE-RAW', $confirmation, 'callback', true );

		$record      = IfthenpayLpTransactionRepository::find_by_token( 'tok-preserve-raw' );
		$method_data = IfthenpayLpTransactionRepository::decode_method_data( $record ); // @phpstan-ignore-line argument.type

		$this->assertSame( array( 'TransactionId' => 'TXID-EARLIER' ), $method_data['ifthenpay_response'] );
	}
}
