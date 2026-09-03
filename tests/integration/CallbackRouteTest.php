<?php
/**
 * Proves the `GET /wp-json/ifthenpay-lp/v1/callback` route end to end, dispatched through WordPress's
 * own REST server (not calling the handler directly) — against the real saved fixtures under
 * tests/fixtures/callbacks/, so a change to those fixtures is caught here too. One test per outcome
 * in contracts/callback.md's own response table.
 *
 * @package ifthenpay-payments-for-latepoint
 */

// phpcs:ignore Squiz.Commenting.FileComment.Missing -- docblock above is the file comment; the sniff misclassifies it when a require is the first statement.
require_once __DIR__ . '/../support/class-latepoint-order-fixture.php';
require_once __DIR__ . '/../support/ifthenpay-callback-fixtures.php';

/**
 * Callback route proof.
 */
class CallbackRouteTest extends WP_UnitTestCase {

	/**
	 * Registers the real REST route once per test, the way `rest_api_init` would.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Dispatches a callback fixture through the real REST server.
	 *
	 * @param string $fixture_name File under tests/fixtures/callbacks/.
	 */
	private function dispatch( string $fixture_name ): WP_REST_Response {
		global $wp_rest_server;

		$request = new WP_REST_Request( 'GET', '/ifthenpay-lp/v1/callback' );
		$request->set_query_params( ifthenpay_lp_callback_fixture_params( $fixture_name ) );

		return $wp_rest_server->dispatch( $request );
	}

	/**
	 * Inserts a repository row matching a fixture's own reference/amount/gateway key, linked to a
	 * real, already-converted order — so settlement can actually complete for the "valid" cases.
	 *
	 * @param string $token       Must match the fixture's own `reference` param.
	 * @param string $request_id  Must match the fixture's own `request_id` param.
	 * @param string $amount      Must match the fixture's own `amount` param, for a settling case.
	 */
	private function seed_record_for_fixture( string $token, string $request_id, string $amount ): void {
		$fixture = ifthenpay_lp_create_order_fixture( array( 'amount' => $amount ) );
		ifthenpay_lp_insert_pending_transaction_row(
			$fixture,
			$request_id,
			array(
				'token'       => $token,
				'amount'      => $amount,
				'gateway_key' => ifthenpay_lp_callback_fixture_gateway_key(),
			)
		);
	}

	/**
	 * The valid fixture settles: 200, and the underlying order actually gets paid.
	 */
	public function test_valid_notification_settles_and_returns_200(): void {
		$this->seed_record_for_fixture( 'lp-order-tok-abc123', 'REQ-VALID-0001', '25.00' );

		$response = $this->dispatch( 'valid-multibanco.txt' );

		$this->assertSame( 200, $response->get_status() );
		$record = IfthenpayLpTransactionRepository::find_by_request_id( 'REQ-VALID-0001' );
		$this->assertNotNull( $record );
		$this->assertNotNull( $record->settled_at ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * The duplicate fixture — a retried delivery of the same notification — still returns 200 and
	 * settles nothing a second time (contracts/callback.md's retry-behaviour note: any non-200
	 * would make ifthenpay retry a payment already recorded).
	 */
	public function test_duplicate_notification_still_returns_200_and_settles_once(): void {
		$this->seed_record_for_fixture( 'lp-order-tok-abc123', 'REQ-VALID-0001', '25.00' );

		$first  = $this->dispatch( 'valid-multibanco.txt' );
		$second = $this->dispatch( 'duplicate.txt' );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );

		$transactions = ( new OsTransactionModel() )->where( array( 'token' => 'lp-order-tok-abc123' ) )->get_results_as_models();
		$this->assertCount( 1, is_array( $transactions ) ? $transactions : array( $transactions ) );
	}

	/**
	 * A wrong `apk` — decodes to a key that doesn't match the record's own gateway key — is
	 * rejected with 403, before any settlement is attempted.
	 */
	public function test_bad_key_returns_403(): void {
		$this->seed_record_for_fixture( 'lp-order-tok-abc123', 'REQ-BADKEY-0003', '25.00' );

		$response = $this->dispatch( 'bad-key.txt' );

		$this->assertSame( 403, $response->get_status() );
		$record = IfthenpayLpTransactionRepository::find_by_request_id( 'REQ-BADKEY-0003' );
		$this->assertNull( $record->settled_at ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * A notified amount that doesn't match the record's own stored amount is rejected with 409 —
	 * the one rejection reason contracts/callback.md gives its own status code.
	 */
	public function test_wrong_amount_returns_409(): void {
		$this->seed_record_for_fixture( 'lp-order-tok-abc123', 'REQ-WRONGAMOUNT-0004', '25.00' );

		$response = $this->dispatch( 'wrong-amount.txt' );

		$this->assertSame( 409, $response->get_status() );
	}

	/**
	 * A reference with no matching record at all is rejected with 404 — nothing is revealed about
	 * whether a record with a different reference exists (NFR-6).
	 */
	public function test_unknown_reference_returns_404(): void {
		$response = $this->dispatch( 'unknown-reference.txt' );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * A request shaped for the old, per-method Payshop registration has none of our required
	 * parameters and is rejected the same way an unmatched reference is — 404, no per-method
	 * branching needed (contracts/callback.md).
	 */
	public function test_payshop_shaped_request_returns_404(): void {
		$response = $this->dispatch( 'payshop-shaped.txt' );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * `request_id` of `"0"` — a real value seen in production data (research.md) — is handled as
	 * an ordinary opaque string, not treated as falsy/missing.
	 */
	public function test_legacy_zero_request_id_settles_normally(): void {
		$this->seed_record_for_fixture( 'lp-order-tok-legacy001', '0', '10.00' );

		$response = $this->dispatch( 'requestid-legacy-zero.txt' );

		$this->assertSame( 200, $response->get_status() );
		$record = IfthenpayLpTransactionRepository::find_by_request_id( '0' );
		$this->assertNotNull( $record->settled_at ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * No response body in any case (contracts/callback.md) — only the status code carries meaning.
	 */
	public function test_response_never_carries_a_body(): void {
		$response = $this->dispatch( 'unknown-reference.txt' );

		$this->assertNull( $response->get_data() );
	}
}
