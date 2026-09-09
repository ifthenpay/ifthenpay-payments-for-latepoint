<?php
/**
 * Proves IfthenpayLpSettlement::settle_payment() against a real order+booking+invoice chain and
 * real LatePoint state-change helpers — one test per guarantee this method makes about settling a
 * payment. Brain Monkey can't exercise real OsModel::save() or booking/order transitions, so this
 * needs the wp-phpunit harness.
 *
 * @package ifthenpay-payments-for-latepoint
 */

// phpcs:ignore Squiz.Commenting.FileComment.Missing -- docblock above is the file comment; the sniff misclassifies it when a require is the first statement.
require_once __DIR__ . '/../support/class-latepoint-order-fixture.php';

/**
 * Settlement proof.
 */
class SettlementTest extends WP_UnitTestCase {

	/**
	 * A valid first notification settles: transaction recorded, invoice paid, order fully paid,
	 * booking approved, and the repository row stamped PAID + settled_at. The LatePoint
	 * transaction's own token is our own repository token — not ifthenpay's request_id — matching
	 * the realtime flow's own convention (2.1.1: "payment charge reference is now the payment token
	 * ... so merchants can reconcile payments more easily") so a merchant sees one consistent
	 * identifier regardless of payment method; request_id/entity/reference go in `notes` instead.
	 */
	public function test_settles_a_valid_first_notification(): void {
		$fixture = ifthenpay_lp_create_order_fixture();
		ifthenpay_lp_insert_pending_transaction_row( $fixture, 'REQ-SETTLE-001', array( 'token' => 'TOK-SETTLE-001' ) );

		$result = IfthenpayLpSettlement::settle_payment( 'REQ-SETTLE-001', array( 'amount' => '25.00' ), 'callback' );

		$this->assertSame( IfthenpayLpSettlementResult::SETTLED, $result->status() );

		$transaction = ( new OsTransactionModel() )->where( array( 'token' => 'TOK-SETTLE-001' ) )->set_limit( 1 )->get_results_as_models();
		$this->assertNotFalse( $transaction );
		$this->assertSame( LATEPOINT_TRANSACTION_STATUS_SUCCEEDED, $transaction->status );
		$this->assertSame( $fixture->order->id, (int) $transaction->order_id );
		$this->assertStringContainsString( 'REQ-SETTLE-001', $transaction->notes );
		$this->assertStringContainsString( 'callback', $transaction->notes );

		$invoice = new OsInvoiceModel( $fixture->invoice->id );
		$this->assertSame( LATEPOINT_INVOICE_STATUS_PAID, $invoice->status );

		$order = new OsOrderModel( $fixture->order->id );
		$this->assertSame( LATEPOINT_ORDER_PAYMENT_STATUS_FULLY_PAID, $order->payment_status );

		$booking = new OsBookingModel( $fixture->booking->id );
		$this->assertSame( LATEPOINT_BOOKING_STATUS_APPROVED, $booking->status );

		$record = IfthenpayLpTransactionRepository::find_by_request_id( 'REQ-SETTLE-001' );
		$this->assertSame( 'PAID', $record->status ); // @phpstan-ignore-line property.notFound
		$this->assertNotNull( $record->settled_at ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * The settled transaction's own notes carry Entity + Reference for a Multibanco row, and
	 * Reference alone (no dangling "Entity:" label, and — the actual bug this proves — no dropped
	 * Reference either) for a Payshop one, which carries no entity at all. An earlier version of
	 * build_transaction_notes() required both entity AND reference non-empty before showing either,
	 * which silently dropped Payshop's own reference from the note entirely instead of just omitting
	 * its Entity label.
	 */
	public function test_settled_notes_show_reference_for_both_methods(): void {
		$mb_fixture = ifthenpay_lp_create_order_fixture();
		ifthenpay_lp_insert_pending_transaction_row(
			$mb_fixture,
			'REQ-NOTES-MB-001',
			array(
				'token'     => 'TOK-NOTES-MB-001',
				'entity'    => '12345',
				'reference' => '123456789',
			)
		);
		IfthenpayLpSettlement::settle_payment( 'REQ-NOTES-MB-001', array( 'amount' => '25.00' ), 'callback' );
		$mb_transaction = ( new OsTransactionModel() )->where( array( 'token' => 'TOK-NOTES-MB-001' ) )->set_limit( 1 )->get_results_as_models();
		$this->assertStringContainsString( 'Entity: 12345 | Reference: 123456789', $mb_transaction->notes );

		$payshop_fixture = ifthenpay_lp_create_order_fixture();
		ifthenpay_lp_insert_pending_transaction_row(
			$payshop_fixture,
			'REQ-NOTES-PAYSHOP-001',
			array(
				'token'     => 'TOK-NOTES-PAYSHOP-001',
				'method'    => 'PAYSHOP',
				'entity'    => null,
				'reference' => '987654321',
			)
		);
		IfthenpayLpSettlement::settle_payment( 'REQ-NOTES-PAYSHOP-001', array( 'amount' => '25.00' ), 'callback' );
		$payshop_transaction = ( new OsTransactionModel() )->where( array( 'token' => 'TOK-NOTES-PAYSHOP-001' ) )->set_limit( 1 )->get_results_as_models();
		$this->assertStringContainsString( 'Reference: 987654321', $payshop_transaction->notes );
		$this->assertStringNotContainsString( 'Entity:', $payshop_transaction->notes );
	}

	/**
	 * A second, identical notification is a no-op — still reports success (already-settled), but
	 * creates no second transaction and triggers no further booking/order state changes at all.
	 *
	 * Counts, not exact-once assertions: OsBookingHelper::change_booking_status() fires
	 * `latepoint_booking_updated` itself *and* the OsBookingModel::update_status() it calls fires
	 * the same action again — a real double-fire already present in LatePoint 5.6.10 core, nothing
	 * to do with this add-on. What we're actually responsible for, and what this asserts, is that
	 * the *second* settle_payment() call adds no further fires at all.
	 */
	public function test_duplicate_notification_is_a_no_op_and_still_reports_success(): void {
		$fixture = ifthenpay_lp_create_order_fixture();
		ifthenpay_lp_insert_pending_transaction_row( $fixture, 'REQ-DUPLICATE-001' );

		$booking_updated_count = 0;
		$order_updated_count   = 0;
		add_action(
			'latepoint_booking_updated',
			static function () use ( &$booking_updated_count ) {
				++$booking_updated_count;
			}
		);
		add_action(
			'latepoint_order_updated',
			static function () use ( &$order_updated_count ) {
				++$order_updated_count;
			}
		);

		$first = IfthenpayLpSettlement::settle_payment( 'REQ-DUPLICATE-001', array( 'amount' => '25.00' ), 'callback' );
		$this->assertSame( IfthenpayLpSettlementResult::SETTLED, $first->status() );

		$booking_updated_after_first = $booking_updated_count;
		$order_updated_after_first   = $order_updated_count;
		$this->assertGreaterThan( 0, $booking_updated_after_first );
		$this->assertGreaterThan( 0, $order_updated_after_first );

		$second = IfthenpayLpSettlement::settle_payment( 'REQ-DUPLICATE-001', array( 'amount' => '25.00' ), 'callback' );

		$this->assertSame( IfthenpayLpSettlementResult::ALREADY_SETTLED, $second->status() );
		$this->assertTrue( $second->is_settled() );

		$this->assertSame( $booking_updated_after_first, $booking_updated_count );
		$this->assertSame( $order_updated_after_first, $order_updated_count );

		$transactions = ( new OsTransactionModel() )->where( array( 'order_id' => $fixture->order->id ) )->get_results_as_models();
		$this->assertCount( 1, $transactions );
	}

	/**
	 * Ten repeated notifications for the same payment settle exactly once. True multi-connection
	 * concurrency isn't exercisable inside a single-process PHPUnit run wrapped in one DB
	 * transaction; this proves the idempotency guard holds under repetition, which is what
	 * GET_LOCK() plus the settled_at check are actually there to guarantee.
	 */
	public function test_ten_repeated_notifications_settle_exactly_once(): void {
		$fixture = ifthenpay_lp_create_order_fixture();
		ifthenpay_lp_insert_pending_transaction_row( $fixture, 'REQ-TENFOLD-001' );

		$settled_count = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			$result = IfthenpayLpSettlement::settle_payment( 'REQ-TENFOLD-001', array( 'amount' => '25.00' ), 'callback' );
			if ( IfthenpayLpSettlementResult::SETTLED === $result->status() ) {
				++$settled_count;
			}
		}

		$this->assertSame( 1, $settled_count );

		$transactions = ( new OsTransactionModel() )->where( array( 'order_id' => $fixture->order->id ) )->get_results_as_models();
		$this->assertCount( 1, $transactions );
	}

	/**
	 * An amount that doesn't match the stored record's own amount is rejected, and the order is
	 * left completely untouched.
	 */
	public function test_amount_mismatch_is_rejected_and_order_is_untouched(): void {
		$fixture = ifthenpay_lp_create_order_fixture( array( 'amount' => '25.00' ) );
		ifthenpay_lp_insert_pending_transaction_row( $fixture, 'REQ-WRONGAMOUNT-001', array( 'amount' => '25.00' ) );

		$result = IfthenpayLpSettlement::settle_payment( 'REQ-WRONGAMOUNT-001', array( 'amount' => '999.99' ), 'callback' );

		$this->assertSame( IfthenpayLpSettlementResult::REJECTED, $result->status() );
		$this->assertSame( 'amount_mismatch', $result->reason() );

		$order = new OsOrderModel( $fixture->order->id );
		$this->assertSame( LATEPOINT_ORDER_PAYMENT_STATUS_NOT_PAID, $order->payment_status );

		$record = IfthenpayLpTransactionRepository::find_by_request_id( 'REQ-WRONGAMOUNT-001' );
		$this->assertNull( $record->settled_at ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * A notification for an already-cancelled order is rejected outright — it must never be
	 * re-opened by a late or replayed payment.
	 */
	public function test_notification_for_a_cancelled_order_does_not_reopen_it(): void {
		$fixture = ifthenpay_lp_create_order_fixture();
		$fixture->order->update_attributes( array( 'status' => LATEPOINT_ORDER_STATUS_CANCELLED ) );
		ifthenpay_lp_insert_pending_transaction_row( $fixture, 'REQ-CANCELLED-001' );

		$result = IfthenpayLpSettlement::settle_payment( 'REQ-CANCELLED-001', array( 'amount' => '25.00' ), 'callback' );

		$this->assertSame( IfthenpayLpSettlementResult::REJECTED, $result->status() );
		$this->assertSame( 'order_not_settleable', $result->reason() );

		$order = new OsOrderModel( $fixture->order->id );
		$this->assertSame( LATEPOINT_ORDER_STATUS_CANCELLED, $order->status );
	}

	/**
	 * A request id with no matching record at all is rejected — nothing to settle, and nothing is
	 * revealed about whether a record with a different id exists.
	 */
	public function test_unknown_request_id_is_rejected(): void {
		$result = IfthenpayLpSettlement::settle_payment( 'REQ-DOES-NOT-EXIST', array( 'amount' => '25.00' ), 'callback' );

		$this->assertSame( IfthenpayLpSettlementResult::REJECTED, $result->status() );
		$this->assertSame( 'unknown_request_id', $result->reason() );
	}

	/**
	 * A notification arriving before checkout has finished converting the order intent to an order
	 * is not lost — it's a retryable failure, not a rejection, so a caller (e.g. the callback
	 * route, which ifthenpay retries) tries again shortly instead of discarding the payment.
	 */
	public function test_notification_before_order_exists_is_not_lost(): void {
		$customer             = new OsCustomerModel();
		$customer->first_name = 'Test';
		$customer->last_name  = 'Customer';
		$customer->email      = 'ifthenpay-lp-fixture-' . wp_generate_password( 8, false ) . '@example.com';
		$customer->save();

		$order_intent                = new OsOrderIntentModel();
		$order_intent->customer_id   = $customer->id;
		$order_intent->charge_amount = '25.00';
		$order_intent->status        = LATEPOINT_ORDER_INTENT_STATUS_NEW;
		$order_intent->save();

		IfthenpayLpTransactionRepository::insert(
			array(
				'token'       => 'ifp-lp-fixture-not-converted',
				'request_id'  => 'REQ-NOTYETCONVERTED-001',
				'intent_id'   => $order_intent->id,
				'kind'        => 'deferred',
				'method'      => 'MB',
				'amount'      => '25.00',
				'gateway_key' => 'TEST-GW-KEY-0001',
			)
		);

		$result = IfthenpayLpSettlement::settle_payment( 'REQ-NOTYETCONVERTED-001', array( 'amount' => '25.00' ), 'callback' );

		$this->assertSame( IfthenpayLpSettlementResult::FAILED, $result->status() );
		$this->assertSame( 'order_not_ready', $result->reason() );
		$this->assertFalse( $result->is_settled() );

		$record = IfthenpayLpTransactionRepository::find_by_request_id( 'REQ-NOTYETCONVERTED-001' );
		$this->assertNull( $record->settled_at ); // @phpstan-ignore-line property.notFound -- confirms the payment is still there to retry, not dropped.
	}

	/**
	 * When the resolved order doesn't actually exist (a data-integrity edge case, not the normal
	 * "not converted yet" path above), settlement fails rather than creating a transaction against
	 * nothing — and, critically, leaves no partial state behind: no transaction row, no repository
	 * settled_at.
	 */
	public function test_missing_order_leaves_no_partial_state(): void {
		$customer             = new OsCustomerModel();
		$customer->first_name = 'Test';
		$customer->last_name  = 'Customer';
		$customer->email      = 'ifthenpay-lp-fixture-' . wp_generate_password( 8, false ) . '@example.com';
		$customer->save();

		$order_intent                = new OsOrderIntentModel();
		$order_intent->customer_id   = $customer->id;
		$order_intent->charge_amount = '25.00';
		$order_intent->status        = LATEPOINT_ORDER_INTENT_STATUS_CONVERTED;
		$order_intent->order_id      = 999999999; // No order with this id exists.
		$order_intent->save();

		IfthenpayLpTransactionRepository::insert(
			array(
				'token'       => 'ifp-lp-fixture-missing-order',
				'request_id'  => 'REQ-MISSINGORDER-001',
				'intent_id'   => $order_intent->id,
				'kind'        => 'deferred',
				'method'      => 'MB',
				'amount'      => '25.00',
				'gateway_key' => 'TEST-GW-KEY-0001',
			)
		);

		$result = IfthenpayLpSettlement::settle_payment( 'REQ-MISSINGORDER-001', array( 'amount' => '25.00' ), 'callback' );

		$this->assertSame( IfthenpayLpSettlementResult::FAILED, $result->status() );
		$this->assertSame( 'order_not_found', $result->reason() );

		$transactions = ( new OsTransactionModel() )->where( array( 'token' => 'REQ-MISSINGORDER-001' ) )->get_results_as_models();
		$this->assertEmpty( $transactions );

		$record = IfthenpayLpTransactionRepository::find_by_request_id( 'REQ-MISSINGORDER-001' );
		$this->assertNull( $record->settled_at ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * A record with no stored amount at all (the shape of a migrated, pre-003 realtime row) skips
	 * amount verification rather than rejecting outright — there is nothing stored to check
	 * against. See IfthenpayLpSettlement::amounts_match()'s caller.
	 */
	public function test_record_with_no_stored_amount_settles_without_an_amount_check(): void {
		$fixture = ifthenpay_lp_create_order_fixture();
		ifthenpay_lp_insert_pending_transaction_row( $fixture, 'REQ-NOAMOUNT-001', array( 'amount' => null ) );

		$result = IfthenpayLpSettlement::settle_payment( 'REQ-NOAMOUNT-001', array( 'amount' => 'anything-at-all' ), 'callback' );

		$this->assertSame( IfthenpayLpSettlementResult::SETTLED, $result->status() );
	}
}
