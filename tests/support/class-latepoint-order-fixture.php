<?php
/**
 * Builds a minimal but real order+booking+invoice chain for integration tests that exercise
 * IfthenpayLpSettlement::settle_payment() — it needs LatePoint's own state changes
 * (OsBookingHelper::change_booking_status(), OsOrdersHelper::check_if_order_invoices_paid_full_balance())
 * to run for real, not mocked, so the fixture has to be a real, saved row chain, not a stub.
 *
 * Only presence-validated columns are filled in (see each model's own properties_to_validate()) —
 * service_id/agent_id/location_id are plausible-looking IDs, not real rows, since nothing in the
 * settlement path joins out to those tables.
 *
 * @package ifthenpay-payments-for-latepoint
 */

/**
 * Creates one order, in a state that mirrors a Multibanco checkout that already committed the
 * booking unpaid: an order intent marked converted, an OPEN invoice for the full amount, a
 * booking on that order, but no transaction yet.
 *
 * @param array<string,mixed> $overrides Any of 'amount' (string, default '25.00'), 'booking_status'
 *                                       (default LATEPOINT_BOOKING_STATUS_PAYMENT_PENDING).
 * @return object{customer: OsCustomerModel, order_intent: OsOrderIntentModel, order: OsOrderModel,
 *                order_item: OsOrderItemModel, booking: OsBookingModel, invoice: OsInvoiceModel}
 */
function ifthenpay_lp_create_order_fixture( array $overrides = array() ): object {
	$amount         = $overrides['amount'] ?? '25.00';
	$booking_status = $overrides['booking_status'] ?? LATEPOINT_BOOKING_STATUS_PAYMENT_PENDING;

	$customer             = new OsCustomerModel();
	$customer->first_name = 'Test';
	$customer->last_name  = 'Customer';
	$customer->email      = 'ifthenpay-lp-fixture-' . wp_generate_password( 8, false ) . '@example.com';
	$customer->save();

	$order                 = new OsOrderModel();
	$order->customer_id    = $customer->id;
	$order->status         = LATEPOINT_ORDER_STATUS_OPEN;
	$order->payment_status = LATEPOINT_ORDER_PAYMENT_STATUS_NOT_PAID;
	$order->total          = $amount;
	$order->subtotal       = $amount;
	$order->save();

	$order_intent                = new OsOrderIntentModel();
	$order_intent->customer_id   = $customer->id;
	$order_intent->charge_amount = $amount;
	$order_intent->status        = LATEPOINT_ORDER_INTENT_STATUS_CONVERTED;
	$order_intent->order_id      = $order->id;
	$order_intent->save();

	$order_item            = new OsOrderItemModel();
	$order_item->order_id  = $order->id;
	$order_item->variant   = LATEPOINT_ITEM_VARIANT_BOOKING;
	$order_item->subtotal  = $amount;
	$order_item->total     = $amount;
	$order_item->item_data = wp_json_encode( array() );
	$order_item->save();

	$booking                = new OsBookingModel();
	$booking->order_item_id = $order_item->id;
	$booking->service_id    = 1;
	$booking->agent_id      = 1;
	$booking->location_id   = 1;
	$booking->customer_id   = $customer->id;
	$booking->start_date    = gmdate( 'Y-m-d', strtotime( '+1 day' ) );
	$booking->end_date      = gmdate( 'Y-m-d', strtotime( '+1 day' ) );
	$booking->start_time    = '10:00';
	$booking->end_time      = '11:00';
	$booking->status        = $booking_status;
	$booking->save();

	$order_item->item_data = wp_json_encode( array( 'id' => $booking->id ) );
	$order_item->update_attributes( array( 'item_data' => $order_item->item_data ) );

	$invoice                  = new OsInvoiceModel();
	$invoice->order_id        = $order->id;
	$invoice->charge_amount   = $amount;
	$invoice->payment_portion = LATEPOINT_PAYMENT_PORTION_FULL;
	$invoice->status          = LATEPOINT_INVOICE_STATUS_OPEN;
	$invoice->data            = wp_json_encode( array() );
	$invoice->due_at          = current_time( 'mysql', true );
	$invoice->save();

	return (object) array(
		'customer'     => $customer,
		'order_intent' => $order_intent,
		'order'        => $order,
		'order_item'   => $order_item,
		'booking'      => $booking,
		'invoice'      => $invoice,
	);
}

/**
 * Inserts an IfthenpayLpTransactionRepository row for a fixture built by
 * ifthenpay_lp_create_order_fixture(), the way IfthenpayLpPaymentProcessor's own deferred
 * processing methods eventually will — PENDING, unsettled, linked by the order intent's own id.
 *
 * @phpstan-param object{customer: OsCustomerModel, order_intent: OsOrderIntentModel, order: OsOrderModel, order_item: OsOrderItemModel, booking: OsBookingModel, invoice: OsInvoiceModel} $fixture
 *
 * @param object              $fixture    As returned by ifthenpay_lp_create_order_fixture().
 * @param string              $request_id ifthenpay's identifier for this payment.
 * @param array<string,mixed> $overrides  Any repository column to override, e.g. 'amount',
 *                                        'gateway_key'.
 */
function ifthenpay_lp_insert_pending_transaction_row( object $fixture, string $request_id, array $overrides = array() ): void {
	IfthenpayLpTransactionRepository::insert(
		array_merge(
			array(
				'token'       => 'ifp-lp-fixture-' . wp_generate_password( 12, false ),
				'request_id'  => $request_id,
				'intent_id'   => $fixture->order_intent->id,
				'kind'        => 'deferred',
				'method'      => 'MB',
				'amount'      => $fixture->invoice->charge_amount,
				'gateway_key' => 'TEST-GW-KEY-0001',
				'entity'      => '12345',
				'reference'   => '123456789',
			),
			$overrides
		)
	);
}

/**
 * Inserts a bare row directly into LatePoint core's own `{prefix}latepoint_transactions` table — a
 * real transaction, never created through this add-on's own settlement path. Only
 * IfthenpayLpTransactionRepository::find_unclaimed_realtime()'s own tests need this: it proves a
 * realtime ifthenpay_transactions row was genuinely claimed by checking for a real LatePoint
 * transaction with the matching token, so the "claimed" test case needs one of those to exist.
 *
 * @param string $token The token this row's own `token` column must match, to count as "claimed".
 */
function ifthenpay_lp_insert_latepoint_transaction( string $token ): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only fixture helper, not production code.
	$wpdb->insert(
		LATEPOINT_TABLE_TRANSACTIONS,
		array(
			'token'  => $token,
			'status' => LATEPOINT_TRANSACTION_STATUS_SUCCEEDED,
		)
	);
}
