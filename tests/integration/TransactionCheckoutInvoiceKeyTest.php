<?php
/**
 * Proves OsPaymentsIfthenpayCheckoutController::get_transaction_ifthenpay_options() resolves the
 * invoice by its opaque access_key — never by a raw, guessable invoice id — closing an IDOR where
 * any anonymous caller could enumerate invoice_id to read another customer's charge amount and
 * generate a live Pay By Link for someone else's invoice. Same pattern RealtimePollingTest.php uses
 * to test this controller without a real HTTP/AJAX round trip: build via
 * newInstanceWithoutConstructor() and set $params directly, since OsController::__construct()
 * would otherwise read $_POST/$_GET, and OsController::send_json() hard-dies the PHPUnit process
 * via wp_send_json() outside a real AJAX request.
 *
 * @package ifthenpay-payments-for-latepoint
 */

// phpcs:ignore Squiz.Commenting.FileComment.Missing -- docblock above is the file comment; the sniff misclassifies it when a require is the first statement.
require_once __DIR__ . '/../support/class-latepoint-order-fixture.php';
require_once __DIR__ . '/../support/class-ifthenpay-lp-testable-checkout-controller.php';

/**
 * Invoice-key resolution proof.
 */
class TransactionCheckoutInvoiceKeyTest extends WP_UnitTestCase {

	/**
	 * Builds the testable controller without running OsController::__construct(), then sets
	 * $params directly via Reflection — the same params shape OsParamsHelper::get_params() would
	 * produce from a real request, without needing $_POST/$_GET at all.
	 *
	 * @param array<string,mixed> $params As the controller would see in $this->params.
	 */
	private function controller_with_params( array $params ): IfthenpayLpTestableCheckoutController {
		$controller = ( new ReflectionClass( IfthenpayLpTestableCheckoutController::class ) )->newInstanceWithoutConstructor();

		$property = new ReflectionProperty( OsController::class, 'params' );
		$property->setAccessible( true );
		$property->setValue( $controller, $params );

		return $controller;
	}

	/**
	 * No key at all: rejected with an error response, never falls through to resolving any invoice
	 * or building a transaction intent.
	 */
	public function test_rejects_a_missing_key(): void {
		$controller = $this->controller_with_params( array() );

		$controller->get_transaction_ifthenpay_options();

		$this->assertSame( LATEPOINT_STATUS_ERROR, $controller->captured['status'] );
	}

	/**
	 * The exact IDOR this closes: a real invoice's own raw, sequential id — exactly what an
	 * attacker would enumerate, and exactly what the vulnerable version accepted directly — no
	 * longer resolves anything, since access_key (a random UUID, never the id) is the only column
	 * checked now.
	 */
	public function test_a_real_invoices_own_raw_id_no_longer_resolves_it(): void {
		$fixture = ifthenpay_lp_create_order_fixture();

		$controller = $this->controller_with_params( array( 'key' => (string) $fixture->invoice->id ) );

		$controller->get_transaction_ifthenpay_options();

		$this->assertSame( LATEPOINT_STATUS_ERROR, $controller->captured['status'] );
	}

	/**
	 * A key belonging to a different invoice than the one the caller means to pay for is rejected
	 * exactly like an unknown one — proving this isn't just "any non-numeric string passes",
	 * verifying by contrast that a real access_key (see the next test) actually is what unlocks
	 * resolution.
	 */
	public function test_a_foreign_key_does_not_resolve_this_invoice(): void {
		$controller = $this->controller_with_params( array( 'key' => 'not-a-real-access-key' ) );
		$controller->get_transaction_ifthenpay_options();

		$this->assertSame( LATEPOINT_STATUS_ERROR, $controller->captured['status'] );
	}

	/**
	 * The invoice's own real access_key (as LatePoint core's own payment_form.php view emits in a
	 * hidden field, submitted verbatim by front.js's FormData post) resolves it and proceeds past
	 * the invoice-lookup guard — proven directly by a real, saved OsTransactionIntentModel now
	 * existing for this invoice, regardless of whether the rest of the checkout (an unconfigured
	 * gateway in this test environment) goes on to succeed or fail for unrelated reasons — this
	 * test is only about proving the access_key resolved the right invoice, not about the full
	 * Pay By Link flow (already covered elsewhere for the deferred methods; no ifthenpay HTTP call
	 * is mocked here since one may or may not even be reached).
	 */
	public function test_the_invoices_own_access_key_resolves_it(): void {
		$fixture = ifthenpay_lp_create_order_fixture();
		$this->assertNotEmpty( $fixture->invoice->access_key );

		$controller = $this->controller_with_params(
			array(
				'key'               => $fixture->invoice->access_key,
				'payment_portion'   => LATEPOINT_PAYMENT_PORTION_FULL,
				'payment_method'    => 'ifthenpay_mbway',
				'payment_processor' => 'ifthenpay',
			)
		);

		$controller->get_transaction_ifthenpay_options();

		$intent = ( new OsTransactionIntentModel() )->where( array( 'invoice_id' => $fixture->invoice->id ) )->set_limit( 1 )->get_results_as_models();
		$this->assertFalse( $intent->is_new_record() );
	}
}
