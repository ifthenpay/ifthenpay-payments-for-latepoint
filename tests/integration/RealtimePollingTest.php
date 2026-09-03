<?php
/**
 * Proves OsPaymentsIfthenpayCheckoutController::resolve_payment_status_from_modal_url() — the
 * polling fallback's own decision logic, invoked directly via Reflection (no real HTTP/AJAX round
 * trip needed; only IfthenpayLpTransactionStatus's own outbound call is mocked). One test per FR-13
 * guarantee: a PAID row is never downgraded, and ifthenpay's own verification — never the
 * browser's self-reported $type — decides whether to mark the row paid; plus the 'pending' signal
 * that tells the browser (front.js) to keep polling instead of giving up.
 *
 * @package ifthenpay-payments-for-latepoint
 */

// phpcs:ignore Squiz.Commenting.FileComment.Missing -- docblock above is the file comment; the sniff misclassifies it when a require is the first statement.
require_once __DIR__ . '/../support/class-latepoint-order-fixture.php';

/**
 * Realtime polling proof.
 */
class RealtimePollingTest extends WP_UnitTestCase {

	/**
	 * A controller instance built without running OsController::__construct() — this test calls
	 * the private decision method directly with explicit arguments, so none of the
	 * params/session/settings machinery the constructor sets up is needed.
	 */
	private function controller(): OsPaymentsIfthenpayCheckoutController {
		$reflection = new ReflectionClass( OsPaymentsIfthenpayCheckoutController::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * Invokes the private decision method via Reflection.
	 *
	 * @param string $type  As OsPaymentsIfthenpayCheckoutController::resolve_payment_status_from_modal_url().
	 * @param string $txid  As above.
	 * @param string $token As above.
	 * @return array{status:string,message:string,pending:bool}
	 */
	private function resolve( string $type, string $txid, string $token ): array {
		$method = new ReflectionMethod( OsPaymentsIfthenpayCheckoutController::class, 'resolve_payment_status_from_modal_url' );
		$method->setAccessible( true );

		return $method->invoke( $this->controller(), $type, $txid, $token );
	}

	/**
	 * Mocks IfthenpayLpTransactionStatus::check()'s own HTTP call — a real, completed transaction
	 * id answers 200 with {"TransactionId":...,"PaymentMethod":...}; an unrecognised one answers
	 * 404 with an empty body (VERIFIED live against the real endpoint, not assumed).
	 *
	 * @param bool   $verified What the endpoint should answer.
	 * @param string $method   The confirmed payment method, when $verified is true.
	 */
	private function mock_transaction_status( bool $verified, string $method = 'MBWAY' ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $verified, $method ) {
				if ( false !== strpos( $url, '/gateway/transaction/status/get' ) ) {
					return $verified
						? array(
							'response' => array(
								'code'    => 200,
								'message' => '',
							),
							'body'     => wp_json_encode(
								array(
									'TransactionId' => 'TXID-REAL-001',
									'PaymentMethod' => $method,
								)
							),
							'headers'  => array(),
						)
						: array(
							'response' => array(
								'code'    => 404,
								'message' => '',
							),
							'body'     => '',
							'headers'  => array(),
						);
				}
				return $preempt;
			},
			10,
			3
		);
	}

	/**
	 * Inserts a realtime-kind, still-PENDING repository row for a real order fixture — the shape
	 * send_ifthenpay_options() itself creates.
	 *
	 * @param string $token Our correlation handle for the new row.
	 */
	private function seed_pending_realtime_row( string $token ): void {
		$fixture = ifthenpay_lp_create_order_fixture();
		IfthenpayLpTransactionRepository::insert(
			array(
				'token'         => $token,
				'intent_id'     => $fixture->order_intent->id,
				'kind'          => 'realtime',
				'method'        => IfthenpayLpTransactionRepository::METHOD_PAYBYLINK,
				'paybylink_url' => 'https://pay.example/' . $token,
			)
		);
	}

	/**
	 * A row already PAID is never downgraded — a forged 'cancel', with no real txid at all, cannot
	 * touch it. This is the core FR-13 regression test: the old code wrote CANCELLED unconditionally.
	 */
	public function test_paid_row_is_never_downgraded_by_a_forged_cancel(): void {
		$fixture = ifthenpay_lp_create_order_fixture();
		IfthenpayLpTransactionRepository::insert(
			array(
				'token'      => 'tok-already-paid',
				'intent_id'  => $fixture->order_intent->id,
				'kind'       => 'realtime',
				'method'     => IfthenpayLpTransactionRepository::METHOD_PAYBYLINK,
				'status'     => 'PAID',
				'settled_at' => current_time( 'mysql', true ),
			)
		);

		$result = $this->resolve( 'cancel', '', 'tok-already-paid' );

		$this->assertSame( LATEPOINT_STATUS_SUCCESS, $result['status'] );
		$this->assertFalse( $result['pending'] );
		$record = IfthenpayLpTransactionRepository::find_by_token( 'tok-already-paid' );
		$this->assertSame( 'PAID', $record->status ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * Verification marks the row PAID even though the browser itself reported 'cancel' — the
	 * exact scenario research.md warns about: a customer who closes the modal right after paying
	 * must not have their own successful payment marked failed/cancelled. Not routed through
	 * settle_payment() here: the order does not exist yet at this point in the realtime flow (the
	 * browser only submits the booking form after seeing this response), so settle_payment() would
	 * always fail with "order not ready". request_id is still linked and settled_at still stamped
	 * (mark_settled(), not a bare status update) so that a real async callback arriving later for
	 * this same payment — now possible, since gateway_key is stored — recognises it as already
	 * settled instead of trying to settle it a second time.
	 */
	public function test_verified_payment_is_marked_paid_regardless_of_reported_type(): void {
		$this->mock_transaction_status( true );
		$this->seed_pending_realtime_row( 'tok-verified-despite-cancel' );

		$result = $this->resolve( 'cancel', 'TXID-REAL-001', 'tok-verified-despite-cancel' );

		$this->assertSame( LATEPOINT_STATUS_SUCCESS, $result['status'] );
		$this->assertFalse( $result['pending'] );
		$record = IfthenpayLpTransactionRepository::find_by_token( 'tok-verified-despite-cancel' );
		$this->assertSame( 'PAID', $record->status ); // @phpstan-ignore-line property.notFound
		$this->assertSame( 'TXID-REAL-001', $record->request_id ); // @phpstan-ignore-line property.notFound
		$this->assertNotNull( $record->settled_at ); // @phpstan-ignore-line property.notFound
		// The generic PAYBYLINK method the row was inserted with is corrected to what ifthenpay
		// itself confirms the customer actually used.
		$this->assertSame( 'MBWAY', $record->method ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * The redirect says 'success' but ifthenpay doesn't confirm it yet (a real propagation delay —
	 * MBWAY via SIBS in particular can be slow) — the response says pending, not failed, so the
	 * browser (front.js's pollPaymentStatus()) asks again instead of giving up.
	 */
	public function test_unconfirmed_success_is_reported_as_pending_not_failed(): void {
		$this->mock_transaction_status( false );
		$this->seed_pending_realtime_row( 'tok-still-processing' );

		$result = $this->resolve( 'success', 'TXID-NOT-YET-CONFIRMED', 'tok-still-processing' );

		$this->assertTrue( $result['pending'] );
		$record = IfthenpayLpTransactionRepository::find_by_token( 'tok-still-processing' );
		$this->assertSame( 'PENDING', $record->status ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * An unverified 'cancel' is recorded as CANCELLED — the legitimate case this branch exists for.
	 */
	public function test_unverified_cancel_is_recorded(): void {
		$this->mock_transaction_status( false );
		$this->seed_pending_realtime_row( 'tok-real-cancel' );

		$result = $this->resolve( 'cancel', 'TXID-NOT-PAID', 'tok-real-cancel' );

		$this->assertSame( LATEPOINT_STATUS_ERROR, $result['status'] );
		$this->assertFalse( $result['pending'] );
		$record = IfthenpayLpTransactionRepository::find_by_token( 'tok-real-cancel' );
		$this->assertSame( 'CANCELLED', $record->status ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * An unverified non-cancel outcome (the modal's own 'error' branch, or anything unexpected) is
	 * recorded as FAILED, with the attempted txid kept in method_data for support/debugging.
	 */
	public function test_unverified_other_type_is_marked_failed(): void {
		$this->mock_transaction_status( false );
		$this->seed_pending_realtime_row( 'tok-real-failure' );

		$result = $this->resolve( 'error', 'TXID-NOT-PAID', 'tok-real-failure' );

		$this->assertSame( LATEPOINT_STATUS_ERROR, $result['status'] );
		$record = IfthenpayLpTransactionRepository::find_by_token( 'tok-real-failure' );
		$this->assertSame( 'FAILED', $record->status ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * An unknown token — nothing to act on — is a clean error, not a fatal.
	 */
	public function test_unknown_token_is_a_clean_error(): void {
		$result = $this->resolve( 'success', 'TXID-ANYTHING', 'tok-does-not-exist' );

		$this->assertSame( LATEPOINT_STATUS_ERROR, $result['status'] );
	}
}
