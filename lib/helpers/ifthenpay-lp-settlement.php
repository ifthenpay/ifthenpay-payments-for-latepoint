<?php
/**
 * The single point at which an ifthenpay payment becomes real in LatePoint — the callback, the
 * realtime polling fallback, and the manual re-check action all call settle_payment() and only
 * settle_payment(). See specs/001-multibanco-deferred/contracts/settlement.md; state-change order
 * follows that contract's §"Guarantees it must provide" and the Moodle-plugin precedent recorded
 * in research.md (resumable two-phase, not atomic).
 *
 * Scope note: resolves the order behind a record via OsOrderIntentHelper::is_converted(), which
 * only makes sense for a record created from an *order* intent (a booking checkout). LatePoint's
 * OsTransactionIntentModel::convert_to_transaction() (paying an existing invoice directly) aborts
 * outright on a non-success payment result instead of committing unpaid the way an order intent
 * does — verified against LatePoint 5.6.10 source — so a deferred method cannot currently be
 * offered on that checkout path at all. Every record this function is ever asked to settle is
 * therefore assumed to carry an order-intent id.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every public entry point is this one static method — there is deliberately no way to perform
 * any part of a settlement without going through the lock and the full guard sequence below.
 */
class IfthenpayLpSettlement {

	/**
	 * Settles a payment. Safe to call more than once with the same $request_id: every call after
	 * the first returns already-settled, with no further side effects.
	 *
	 * @param string               $request_id ifthenpay's own identifier for this payment — the
	 *                                          idempotency key, and the lock key.
	 * @param array<string,string> $payload    Normalised notification data; at minimum `amount`,
	 *                                          already formatted the same way as the stored
	 *                                          record's own `amount` column (see amounts_match()).
	 * @param string               $source     'callback' | 'polling' | 'manual' — recorded on the
	 *                                          LatePoint transaction's own `notes` field (see
	 *                                          apply_state_change()), never used to change
	 *                                          behaviour.
	 */
	public static function settle_payment( string $request_id, array $payload, string $source ): IfthenpayLpSettlementResult {
		try {
			return IfthenpayLpSettlementLock::with_lock(
				$request_id,
				static function () use ( $request_id, $payload, $source ) {
					return self::settle_locked( $request_id, $payload, $source );
				}
			);
		} catch ( IfthenpayLpLockUnavailableException $e ) {
			return IfthenpayLpSettlementResult::failed( 'lock_unavailable' );
		}
	}

	/**
	 * Everything from here down runs with the lock for $request_id already held — see
	 * IfthenpayLpSettlementLock. Re-reads the record fresh; anything read before the lock (by a
	 * caller's own pre-checks) may already be stale.
	 *
	 * @param string               $request_id As passed to settle_payment().
	 * @param array<string,string> $payload    As passed to settle_payment().
	 * @param string               $source     As passed to settle_payment().
	 */
	private static function settle_locked( string $request_id, array $payload, string $source ): IfthenpayLpSettlementResult {
		$record = IfthenpayLpTransactionRepository::find_by_request_id( $request_id );
		if ( ! $record ) {
			return IfthenpayLpSettlementResult::rejected( 'unknown_request_id' );
		}

		if ( null !== $record->settled_at ) {
			return IfthenpayLpSettlementResult::already_settled();
		}

		if ( null !== $record->amount && ! self::amounts_match( $record->amount, $payload['amount'] ?? null ) ) {
			return IfthenpayLpSettlementResult::rejected( 'amount_mismatch' );
		}

		$order_id = OsOrderIntentHelper::is_converted( (int) $record->intent_id );
		if ( ! $order_id ) {
			// Checkout may simply still be mid-flight (see the file docblock's ordering-tolerance
			// note) — not a rejection, a reason to have the caller ask again.
			return IfthenpayLpSettlementResult::failed( 'order_not_ready' );
		}

		$order = new OsOrderModel( $order_id );
		if ( $order->is_new_record() ) {
			return IfthenpayLpSettlementResult::failed( 'order_not_found' );
		}

		if ( self::order_is_closed_to_settlement( $order ) ) {
			return IfthenpayLpSettlementResult::rejected( 'order_not_settleable' );
		}

		if ( ! self::apply_state_change( $record, $order, $request_id, $source ) ) {
			return IfthenpayLpSettlementResult::failed( 'state_change_failed' );
		}

		IfthenpayLpTransactionRepository::mark_settled( $record->token );

		return IfthenpayLpSettlementResult::settled();
	}

	/**
	 * True when the order is in a state that must never be re-opened by an incoming payment —
	 * cancelled, or already refunded (guarantee #7).
	 *
	 * @param OsOrderModel $order The order to check.
	 */
	private static function order_is_closed_to_settlement( OsOrderModel $order ): bool {
		if ( LATEPOINT_ORDER_STATUS_CANCELLED === $order->status ) {
			return true;
		}

		return in_array( $order->payment_status, array( LATEPOINT_ORDER_PAYMENT_STATUS_REFUNDED, LATEPOINT_ORDER_PAYMENT_STATUS_PARTIALLY_REFUNDED ), true );
	}

	/**
	 * The resumable two-phase state change from contracts/settlement.md: record the transaction
	 * first (so captured money is never un-tracked by a later failure), then approve the booking(s)
	 * and let core recompute the order's payment status — never assigned directly, see
	 * research.md's note on OsOrdersHelper::check_if_order_invoices_paid_full_balance(). Only the
	 * repository's own settled_at is stamped by the caller, and only once every step here has
	 * actually succeeded.
	 *
	 * @param object       $record     The repository row (see IfthenpayLpTransactionRepository).
	 * @param OsOrderModel $order      The already-loaded, already-validated order.
	 * @param string       $request_id ifthenpay's identifier for this payment — recorded in
	 *                                 `notes`, not as the transaction's own token (see
	 *                                 build_transaction_notes()).
	 * @param string       $source     'callback' | 'polling' | 'manual' — recorded in `notes`.
	 */
	private static function apply_state_change( object $record, OsOrderModel $order, string $request_id, string $source ): bool {
		$transaction                  = new OsTransactionModel();
		$transaction->token           = (string) $record->token;
		$transaction->payment_method  = $record->method;
		$transaction->payment_portion = LATEPOINT_PAYMENT_PORTION_FULL;
		$transaction->amount          = $record->amount;
		$transaction->order_id        = $order->id;
		$transaction->customer_id     = $order->customer_id;
		$transaction->processor       = 'ifthenpay';
		$transaction->kind            = LATEPOINT_TRANSACTION_KIND_CAPTURE;
		$transaction->status          = LATEPOINT_TRANSACTION_STATUS_SUCCEEDED;
		$transaction->notes           = self::build_transaction_notes( $record, $request_id, $source );

		$invoice = OsInvoicesHelper::get_matching_invoice_for_transaction( $transaction );
		if ( ! $invoice->is_new_record() ) {
			$transaction->invoice_id = $invoice->id;
		}

		if ( ! $transaction->save() ) {
			return false;
		}
		do_action( 'latepoint_transaction_created', $transaction );

		if ( ! $invoice->is_new_record() ) {
			$invoice->update_attributes( array( 'status' => LATEPOINT_INVOICE_STATUS_PAID ) );
		}

		OsOrdersHelper::check_if_order_invoices_paid_full_balance( $order->id );

		foreach ( $order->get_bookings_from_order_items( true ) as $booking ) {
			OsBookingHelper::change_booking_status( $booking->id, LATEPOINT_BOOKING_STATUS_APPROVED );
		}

		return true;
	}

	/**
	 * Everything about this payment that isn't already the transaction's own token/amount/method —
	 * ifthenpay's own request id (our token is what LatePoint shows as "Confirmation Code",
	 * consistent with the realtime flow's own convention; request_id would only confuse a merchant
	 * reconciling by the token they already see everywhere else), the Multibanco entity/reference
	 * when this was a deferred payment, and which of the three settle_payment() call sites settled
	 * it — all otherwise invisible once the row leaves the plugin's own table.
	 *
	 * @param object $record     The repository row.
	 * @param string $request_id ifthenpay's identifier for this payment.
	 * @param string $source     'callback' | 'polling' | 'manual'.
	 */
	private static function build_transaction_notes( object $record, string $request_id, string $source ): string {
		$lines   = array();
		$lines[] = 'ifthenpay request ID: ' . $request_id;

		if ( ! empty( $record->entity ) && ! empty( $record->reference ) ) {
			$lines[] = 'Entity: ' . $record->entity . ' | Reference: ' . $record->reference;
		}

		$lines[] = 'Settled via: ' . $source;

		return implode( "\n", $lines );
	}

	/**
	 * Compares as formatted strings, never as floats (contracts/callback.md step 5) — a stray
	 * float comparison is exactly the kind of bug that silently accepts a wrong amount on some PHP
	 * builds and rejects a correct one on others.
	 *
	 * @param string      $expected_amount The record's own stored `amount` column.
	 * @param string|null $paid_amount     The payload's `amount`, or null if the caller omitted it.
	 */
	private static function amounts_match( string $expected_amount, ?string $paid_amount ): bool {
		if ( null === $paid_amount ) {
			return false;
		}

		return number_format( (float) $expected_amount, 2, '.', '' ) === number_format( (float) $paid_amount, 2, '.', '' );
	}
}
