<?php
/**
 * Proves IfthenpayLpManualRecheck::run() — the manual re-check action's own logic (D-5, spec 001),
 * shared by the admin controller action and the WP-CLI command. Settles through the real
 * settle_payment() against a real order+booking fixture, same as the callback route would.
 *
 * @package ifthenpay-payments-for-latepoint
 */

// phpcs:ignore Squiz.Commenting.FileComment.Missing -- docblock above is the file comment; the sniff misclassifies it when a require is the first statement.
require_once __DIR__ . '/../support/class-latepoint-order-fixture.php';

/**
 * Manual re-check proof.
 */
class ManualRecheckTest extends WP_UnitTestCase {

	/**
	 * A missing token is a clean, named outcome — never a fatal.
	 */
	public function test_missing_token_is_reported(): void {
		$result = IfthenpayLpManualRecheck::run( '' );

		$this->assertSame( IfthenpayLpManualRecheck::MISSING_ARGUMENT, $result['outcome'] );
	}

	/**
	 * An unknown token — nothing to act on.
	 */
	public function test_unknown_token_is_reported(): void {
		$result = IfthenpayLpManualRecheck::run( 'tok-does-not-exist' );

		$this->assertSame( IfthenpayLpManualRecheck::NOT_FOUND, $result['outcome'] );
	}

	/**
	 * A record with no request_id at all (nothing to settle by) is treated the same as not found —
	 * there is no idempotency key to hand settle_payment().
	 */
	public function test_record_with_no_request_id_is_reported_as_not_found(): void {
		$fixture = ifthenpay_lp_create_order_fixture();
		IfthenpayLpTransactionRepository::insert(
			array(
				'token'     => 'tok-no-request-id',
				'intent_id' => $fixture->order_intent->id,
				'kind'      => 'deferred',
				'method'    => 'MB',
				'amount'    => $fixture->invoice->charge_amount,
			)
		);

		$result = IfthenpayLpManualRecheck::run( 'tok-no-request-id' );

		$this->assertSame( IfthenpayLpManualRecheck::NOT_FOUND, $result['outcome'] );
	}

	/**
	 * A genuine, still-pending deferred payment settles — the order gets paid and the booking
	 * approved, exactly as a real callback would have done.
	 */
	public function test_pending_payment_settles(): void {
		$fixture = ifthenpay_lp_create_order_fixture( array( 'amount' => '25.00' ) );
		IfthenpayLpTransactionRepository::insert(
			array(
				'token'       => 'tok-manual-settle',
				'request_id'  => 'REQ-MANUAL-001',
				'intent_id'   => $fixture->order_intent->id,
				'kind'        => 'deferred',
				'method'      => 'MB',
				'amount'      => '25.00',
				'gateway_key' => 'TEST-GW-KEY-0001',
			)
		);

		$result = IfthenpayLpManualRecheck::run( 'tok-manual-settle' );

		$this->assertSame( IfthenpayLpManualRecheck::SETTLED, $result['outcome'] );

		$order = new OsOrderModel( $fixture->order->id );
		$this->assertSame( LATEPOINT_ORDER_PAYMENT_STATUS_FULLY_PAID, $order->payment_status );

		$booking = new OsBookingModel( $fixture->booking->id );
		$this->assertSame( LATEPOINT_BOOKING_STATUS_APPROVED, $booking->status );
	}

	/**
	 * A duplicate call after settlement is idempotent — still reports SETTLED (is_settled() covers
	 * both settled and already-settled), no second transaction.
	 */
	public function test_repeated_call_is_idempotent(): void {
		$fixture = ifthenpay_lp_create_order_fixture( array( 'amount' => '25.00' ) );
		IfthenpayLpTransactionRepository::insert(
			array(
				'token'       => 'tok-manual-repeat',
				'request_id'  => 'REQ-MANUAL-002',
				'intent_id'   => $fixture->order_intent->id,
				'kind'        => 'deferred',
				'method'      => 'MB',
				'amount'      => '25.00',
				'gateway_key' => 'TEST-GW-KEY-0001',
			)
		);

		IfthenpayLpManualRecheck::run( 'tok-manual-repeat' );
		$second = IfthenpayLpManualRecheck::run( 'tok-manual-repeat' );

		$this->assertSame( IfthenpayLpManualRecheck::SETTLED, $second['outcome'] );

		$transactions = ( new OsTransactionModel() )->where( array( 'order_id' => $fixture->order->id ) )->get_results_as_models();
		$this->assertCount( 1, $transactions );
	}

	/**
	 * A cancelled order is not silently re-opened by a manual re-check either — the same guard
	 * settle_payment() applies to every caller.
	 */
	public function test_cancelled_order_is_rejected(): void {
		$fixture = ifthenpay_lp_create_order_fixture( array( 'amount' => '25.00' ) );
		$fixture->order->update_attributes( array( 'status' => LATEPOINT_ORDER_STATUS_CANCELLED ) );
		IfthenpayLpTransactionRepository::insert(
			array(
				'token'       => 'tok-manual-cancelled',
				'request_id'  => 'REQ-MANUAL-003',
				'intent_id'   => $fixture->order_intent->id,
				'kind'        => 'deferred',
				'method'      => 'MB',
				'amount'      => '25.00',
				'gateway_key' => 'TEST-GW-KEY-0001',
			)
		);

		$result = IfthenpayLpManualRecheck::run( 'tok-manual-cancelled' );

		$this->assertSame( IfthenpayLpManualRecheck::REJECTED, $result['outcome'] );
	}
}
