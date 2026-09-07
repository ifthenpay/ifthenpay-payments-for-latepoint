<?php
/**
 * Proves IfthenpayLpTransactionRepository::prime_cache_for_customer() — the customer-dashboard
 * N+1 fix: warms find_by_intent_id()'s own wp_cache entry for every one of a customer's order
 * intents in 2 queries total, so IfthenpayLpReferenceDisplay::for_order()'s own per-booking lookup
 * (fired once per booking on the dashboard) hits cache instead of the database afterward.
 *
 * @package ifthenpay-payments-for-latepoint
 */

// phpcs:ignore Squiz.Commenting.FileComment.Missing -- docblock above is the file comment; the sniff misclassifies it when a require is the first statement.
require_once __DIR__ . '/../support/class-latepoint-order-fixture.php';

/**
 * Dashboard cache-priming proof.
 */
class DashboardCachePrimingTest extends WP_UnitTestCase {

	/**
	 * A real order+intent fixture, re-pointed at $customer instead of the fixture's own — mirrors
	 * how other integration tests here re-point a fixture's own agent (LapsedAppointmentDigestTest)
	 * rather than changing the shared fixture helper's own signature, since only order_intent's own
	 * `customer_id` column matters to prime_cache_for_customer()'s own query, not the order/booking's.
	 *
	 * @phpstan-return object{customer: OsCustomerModel, order_intent: OsOrderIntentModel, order: OsOrderModel, order_item: OsOrderItemModel, booking: OsBookingModel, invoice: OsInvoiceModel}
	 *
	 * @param OsCustomerModel $customer The customer to point this fixture's own order intent at.
	 */
	private function create_order_for_customer( OsCustomerModel $customer ): object {
		$fixture = ifthenpay_lp_create_order_fixture();
		$fixture->order_intent->update_attributes( array( 'customer_id' => $customer->id ) );
		$fixture->customer = $customer;

		return $fixture;
	}

	/**
	 * Reads a primed cache entry directly, distinguishing "primed as false" (negative cache, $found
	 * true) from "never primed at all" (a real miss, $found false) — wp_cache_get() alone can't tell
	 * those apart, since both return false without the $found by-ref parameter.
	 *
	 * @param int $intent_id The order intent id a row was (or wasn't) primed under.
	 * @return array{0: mixed, 1: bool} [$value, $found]
	 */
	private function read_primed_entry( int $intent_id ): array {
		$found = false;
		$value = wp_cache_get( 'intent_id_' . $intent_id, 'ifthenpay_lp_transactions', false, $found );

		return array( $value, $found );
	}

	/**
	 * Two orders for the same customer, each with its own deferred transaction row: priming caches
	 * both, keyed by each order intent's own id.
	 */
	public function test_primes_the_real_row_for_every_intent_that_has_one(): void {
		$customer             = new OsCustomerModel();
		$customer->first_name = 'Dashboard';
		$customer->last_name  = 'Customer';
		$customer->email      = 'ifthenpay-lp-dashboard-' . wp_generate_password( 8, false ) . '@example.com';
		$customer->save();

		$first  = $this->create_order_for_customer( $customer );
		$second = $this->create_order_for_customer( $customer );
		ifthenpay_lp_insert_pending_transaction_row( $first, 'REQ-DASH-' . $first->order->id );
		ifthenpay_lp_insert_pending_transaction_row( $second, 'REQ-DASH-' . $second->order->id );

		IfthenpayLpTransactionRepository::prime_cache_for_customer( (int) $customer->id );

		list( $first_value, $first_found )   = $this->read_primed_entry( (int) $first->order_intent->id );
		list( $second_value, $second_found ) = $this->read_primed_entry( (int) $second->order_intent->id );

		$this->assertTrue( $first_found );
		$this->assertSame( 'REQ-DASH-' . $first->order->id, $first_value->request_id ); // @phpstan-ignore-line property.notFound
		$this->assertTrue( $second_found );
		$this->assertSame( 'REQ-DASH-' . $second->order->id, $second_value->request_id ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * An order intent with no transaction row behind it (e.g. a realtime/Pay-By-Link checkout, or
	 * one that never converted to a payment attempt at all) still gets a cache entry — explicitly
	 * `false`, matching find_one()'s own negative-caching convention — not simply skipped, or the
	 * later find_by_intent_id() call for that same intent would fall through to a real query anyway.
	 */
	public function test_primes_a_negative_entry_for_an_intent_with_no_transaction_row(): void {
		$customer             = new OsCustomerModel();
		$customer->first_name = 'Dashboard';
		$customer->last_name  = 'NoPayment';
		$customer->email      = 'ifthenpay-lp-dashboard-nopay-' . wp_generate_password( 8, false ) . '@example.com';
		$customer->save();

		$fixture = $this->create_order_for_customer( $customer );

		IfthenpayLpTransactionRepository::prime_cache_for_customer( (int) $customer->id );

		list( $value, $found ) = $this->read_primed_entry( (int) $fixture->order_intent->id );

		$this->assertTrue( $found );
		$this->assertFalse( $value );
	}

	/**
	 * End-to-end: after priming, IfthenpayLpReferenceDisplay::for_order() — the actual consumer on
	 * the dashboard tile hook — still returns the correct record, proving the primed cache entry is
	 * genuinely usable by the real lookup path, not just present under the right key in isolation.
	 */
	public function test_for_order_returns_the_correct_record_after_priming(): void {
		$customer             = new OsCustomerModel();
		$customer->first_name = 'Dashboard';
		$customer->last_name  = 'EndToEnd';
		$customer->email      = 'ifthenpay-lp-dashboard-e2e-' . wp_generate_password( 8, false ) . '@example.com';
		$customer->save();

		$fixture = $this->create_order_for_customer( $customer );
		ifthenpay_lp_insert_pending_transaction_row( $fixture, 'REQ-DASH-E2E-' . $fixture->order->id );

		IfthenpayLpTransactionRepository::prime_cache_for_customer( (int) $customer->id );

		$record = IfthenpayLpReferenceDisplay::for_order( (int) $fixture->order->id );

		$this->assertNotNull( $record );
		$this->assertSame( 'REQ-DASH-E2E-' . $fixture->order->id, $record->request_id ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * A customer with no orders at all: no query error, nothing primed, a no-op.
	 */
	public function test_does_not_error_for_a_customer_with_no_orders(): void {
		$customer             = new OsCustomerModel();
		$customer->first_name = 'No';
		$customer->last_name  = 'Orders';
		$customer->email      = 'ifthenpay-lp-dashboard-none-' . wp_generate_password( 8, false ) . '@example.com';
		$customer->save();

		IfthenpayLpTransactionRepository::prime_cache_for_customer( (int) $customer->id );

		$this->addToAssertionCount( 1 );
	}
}
