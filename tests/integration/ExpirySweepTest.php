<?php
/**
 * Proves IfthenpayLpExpirySweep — the hourly cron that cancels deferred payments (Multibanco,
 * Payshop) whose reference expired unpaid, releasing the slot. One test per guarantee this job
 * makes about its interaction with settlement.
 *
 * @package ifthenpay-payments-for-latepoint
 */

// phpcs:ignore Squiz.Commenting.FileComment.Missing -- docblock above is the file comment; the sniff misclassifies it when a require is the first statement.
require_once __DIR__ . '/../support/class-latepoint-order-fixture.php';

/**
 * Expiry sweep proof.
 */
class ExpirySweepTest extends WP_UnitTestCase {

	/**
	 * A deferred, still-PENDING row past its own expiry, for a real order+booking fixture.
	 *
	 * @phpstan-return object{customer: OsCustomerModel, order_intent: OsOrderIntentModel, order: OsOrderModel, order_item: OsOrderItemModel, booking: OsBookingModel, invoice: OsInvoiceModel}
	 *
	 * @param string $token       Our correlation handle for the new row.
	 * @param string $request_id  ifthenpay's identifier — also the row's lock key.
	 * @param string $expires_at  MySQL datetime; defaults to an hour in the past.
	 */
	private function seed_expired_row( string $token, string $request_id, string $expires_at = '' ): object {
		$fixture = ifthenpay_lp_create_order_fixture( array( 'booking_status' => LATEPOINT_BOOKING_STATUS_PAYMENT_PENDING ) );
		IfthenpayLpTransactionRepository::insert(
			array(
				'token'       => $token,
				'request_id'  => $request_id,
				'intent_id'   => $fixture->order_intent->id,
				'kind'        => 'deferred',
				'method'      => 'MB',
				'amount'      => $fixture->invoice->charge_amount,
				'gateway_key' => 'TEST-GW-KEY-0001',
				'expires_at'  => '' !== $expires_at ? $expires_at : gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			)
		);

		return $fixture;
	}

	/**
	 * A genuinely unpaid, expired reference is cancelled: the booking releases the slot, and the
	 * repository row is marked CANCELLED so it's not picked up by a later sweep.
	 */
	public function test_expires_a_genuinely_unpaid_reference(): void {
		$fixture = $this->seed_expired_row( 'tok-expired-001', 'REQ-EXPIRED-001' );

		IfthenpayLpExpirySweep::run();

		$booking = new OsBookingModel( $fixture->booking->id );
		$this->assertSame( LATEPOINT_BOOKING_STATUS_CANCELLED, $booking->status );

		$record = IfthenpayLpTransactionRepository::find_by_request_id( 'REQ-EXPIRED-001' );
		$this->assertSame( 'CANCELLED', $record->status ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * The repository's own expired-lookup filters only on status/kind/expires_at — no method
	 * column involved — so a genuinely unpaid, expired Payshop reference is cancelled exactly like
	 * Multibanco's own, with no method-specific code anywhere in this class.
	 */
	public function test_expires_a_genuinely_unpaid_payshop_reference(): void {
		$fixture = ifthenpay_lp_create_order_fixture( array( 'booking_status' => LATEPOINT_BOOKING_STATUS_PAYMENT_PENDING ) );
		IfthenpayLpTransactionRepository::insert(
			array(
				'token'       => 'tok-expired-payshop-001',
				'request_id'  => 'REQ-EXPIRED-PAYSHOP-001',
				'intent_id'   => $fixture->order_intent->id,
				'kind'        => 'deferred',
				'method'      => 'PAYSHOP',
				'amount'      => $fixture->invoice->charge_amount,
				'gateway_key' => 'TEST-GW-KEY-0001',
				'entity'      => null,
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			)
		);

		IfthenpayLpExpirySweep::run();

		$booking = new OsBookingModel( $fixture->booking->id );
		$this->assertSame( LATEPOINT_BOOKING_STATUS_CANCELLED, $booking->status );

		$record = IfthenpayLpTransactionRepository::find_by_request_id( 'REQ-EXPIRED-PAYSHOP-001' );
		$this->assertSame( 'CANCELLED', $record->status ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * A reference that hasn't expired yet is left alone.
	 */
	public function test_does_not_touch_a_reference_not_yet_expired(): void {
		$fixture = $this->seed_expired_row(
			'tok-not-expired-001',
			'REQ-NOTEXPIRED-001',
			gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS )
		);

		IfthenpayLpExpirySweep::run();

		$booking = new OsBookingModel( $fixture->booking->id );
		$this->assertSame( LATEPOINT_BOOKING_STATUS_PAYMENT_PENDING, $booking->status );

		$record = IfthenpayLpTransactionRepository::find_by_request_id( 'REQ-NOTEXPIRED-001' );
		$this->assertSame( 'PENDING', $record->status ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * A payment landing in the same instant as the sweep must win: even if the sweep's own initial
	 * query already picked up the row, the lock-protected re-check must see it's since been settled
	 * and leave it alone. Simulated by invoking the sweep's own private per-row method with a stale
	 * (pre-settlement) snapshot while the real row in the database has already settled — exactly
	 * the ordering a genuine race would produce.
	 */
	public function test_payment_landing_during_the_sweep_wins(): void {
		$fixture = $this->seed_expired_row( 'tok-race-001', 'REQ-RACE-001' );

		$stale_snapshot = IfthenpayLpTransactionRepository::find_by_request_id( 'REQ-RACE-001' );
		$this->assertNotNull( $stale_snapshot );

		// The payment settles for real, after the snapshot above was taken but before the sweep
		// gets to process it — the exact race window the lock exists to close.
		IfthenpayLpTransactionRepository::mark_settled( 'tok-race-001' );
		OsBookingHelper::change_booking_status( $fixture->booking->id, LATEPOINT_BOOKING_STATUS_APPROVED );

		$method = new ReflectionMethod( IfthenpayLpExpirySweep::class, 'expire_one' );
		$method->setAccessible( true );
		$method->invoke( null, $stale_snapshot );

		$booking = new OsBookingModel( $fixture->booking->id );
		$this->assertSame( LATEPOINT_BOOKING_STATUS_APPROVED, $booking->status );

		$record = IfthenpayLpTransactionRepository::find_by_request_id( 'REQ-RACE-001' );
		$this->assertSame( 'PAID', $record->status ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * Realtime rows are never touched by the sweep — a still-PENDING realtime row means the order
	 * was never created (send_ifthenpay_options() only inserts before checkout confirms), so there
	 * is nothing to cancel; only find_expired_pending()'s own `kind = 'deferred'` filter is what
	 * keeps them out, proven here by asserting the run doesn't error against one.
	 */
	public function test_realtime_rows_are_not_selected_by_the_sweep(): void {
		$fixture = ifthenpay_lp_create_order_fixture();
		IfthenpayLpTransactionRepository::insert(
			array(
				'token'      => 'tok-realtime-expired-001',
				'intent_id'  => $fixture->order_intent->id,
				'kind'       => 'realtime',
				'method'     => IfthenpayLpTransactionRepository::METHOD_PAYBYLINK,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			)
		);

		IfthenpayLpExpirySweep::run();

		$record = IfthenpayLpTransactionRepository::find_by_token( 'tok-realtime-expired-001' );
		$this->assertSame( 'PENDING', $record->status ); // @phpstan-ignore-line property.notFound
	}
}
