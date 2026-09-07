<?php
/**
 * The single point at which an ifthenpay payment becomes real in LatePoint — the callback, the
 * realtime polling fallback, and the manual re-check action all call settle_payment() and only
 * settle_payment(). The state change below is deliberately resumable two-phase, not atomic —
 * record the transaction first, then confirm the booking — following the same approach
 * ifthenpay's own Moodle payment plugin uses for this exact problem: an atomic rollback would
 * discard the record of already-captured money, while marking the transaction paid before the
 * booking is actually confirmed would make a retry short-circuit on a booking nobody ever
 * approved.
 *
 * Scope note: resolves the order behind a record via OsOrderIntentHelper::is_converted(), which
 * only makes sense for a record created from an *order* intent (a booking checkout). LatePoint's
 * OsTransactionIntentModel::convert_to_transaction() (paying an existing invoice directly) aborts
 * outright on a non-success payment result instead of committing unpaid the way an order intent
 * does — verified against LatePoint 5.6.9 source — so a deferred method cannot currently be
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
	 * @param string               $request_id     ifthenpay's own identifier for this payment —
	 *                                              the idempotency key, and the lock key.
	 * @param array<string,string> $payload        Normalised notification data; at minimum
	 *                                              `amount`, already formatted the same way as the
	 *                                              stored record's own `amount` column (see
	 *                                              amounts_match()).
	 * @param string               $source         'callback' | 'polling' | 'manual' — recorded on
	 *                                              the LatePoint transaction's own `notes` field
	 *                                              (see apply_state_change()), never used to change
	 *                                              behaviour.
	 * @param string|null          $expected_token When given, the record $request_id resolves to
	 *                                              must carry this exact token — the caller's own,
	 *                                              independently-sourced correlation handle (e.g. a
	 *                                              callback's own `reference` param, already
	 *                                              authenticated against a specific record's
	 *                                              gateway key before this is ever called). Two
	 *                                              identifiers that don't refer to the same row is
	 *                                              rejected outright — without it, a request_id for
	 *                                              an unrelated record would still settle, no
	 *                                              matter which reference/token accompanied it.
	 */
	public static function settle_payment( string $request_id, array $payload, string $source, ?string $expected_token = null ): IfthenpayLpSettlementResult {
		try {
			return IfthenpayLpSettlementLock::with_lock(
				$request_id,
				static function () use ( $request_id, $payload, $source, $expected_token ) {
					return self::settle_locked( $request_id, $payload, $source, $expected_token );
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
	 * @param string               $request_id     As passed to settle_payment().
	 * @param array<string,string> $payload        As passed to settle_payment().
	 * @param string               $source         As passed to settle_payment().
	 * @param string|null          $expected_token As passed to settle_payment().
	 */
	private static function settle_locked( string $request_id, array $payload, string $source, ?string $expected_token ): IfthenpayLpSettlementResult {
		$record = IfthenpayLpTransactionRepository::find_by_request_id( $request_id );
		if ( ! $record ) {
			return IfthenpayLpSettlementResult::rejected( 'unknown_request_id' );
		}

		if ( null !== $expected_token && $expected_token !== $record->token ) {
			return IfthenpayLpSettlementResult::rejected( 'token_mismatch' );
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

		$transaction = self::apply_state_change( $record, $order, $request_id, $source );
		if ( null === $transaction ) {
			return IfthenpayLpSettlementResult::failed( 'state_change_failed' );
		}

		// Stamped before the event fires, not after — a listener reacting synchronously to
		// latepoint_transaction_created (e.g. IfthenpayLpProcessSeeder's own "Payment Received"
		// email) reads this add-on's own repository row via IfthenpayLpReferenceDisplay::for_order(),
		// and must see it already PAID, not the still-PENDING row that existed a moment ago.
		// Firing the event first (as apply_state_change() itself used to, right after saving the
		// LatePoint transaction) sent that email showing "pay this reference" instructions for a
		// reference that had, from the customer's own point of view, just been paid.
		IfthenpayLpTransactionRepository::mark_settled( $record->token, $source );

		do_action( 'latepoint_transaction_created', $transaction );

		return IfthenpayLpSettlementResult::settled();
	}

	/**
	 * True when the order is in a state that must never be re-opened by an incoming payment —
	 * cancelled, or already refunded.
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
	 * The resumable two-phase state change: record the transaction first (so captured money is
	 * never un-tracked by a later failure), then approve the booking(s) and let core recompute the
	 * order's payment status via OsOrdersHelper::check_if_order_invoices_paid_full_balance() — never
	 * assigned directly, since that would skip LatePoint's own invoice bookkeeping, leave the order
	 * inconsistent, and fire no event (so no notifications). Only the repository's own settled_at is
	 * stamped by the caller, and only once every step here has actually succeeded.
	 *
	 * Returns the saved OsTransactionModel rather than firing `latepoint_transaction_created`
	 * itself — the caller (settle_locked()) fires it, deliberately after mark_settled(), so a
	 * listener reacting to that event synchronously (e.g. a notification) already sees this add-on's
	 * own repository row as PAID, not the still-PENDING row that existed a moment earlier.
	 *
	 * @param object       $record     The repository row (see IfthenpayLpTransactionRepository).
	 * @param OsOrderModel $order      The already-loaded, already-validated order.
	 * @param string       $request_id ifthenpay's identifier for this payment — recorded in
	 *                                 `notes`, not as the transaction's own token (see
	 *                                 build_transaction_notes()).
	 * @param string       $source     'callback' | 'polling' | 'manual' — recorded in `notes`.
	 */
	private static function apply_state_change( object $record, OsOrderModel $order, string $request_id, string $source ): ?OsTransactionModel {
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
		$transaction->notes           = self::build_transaction_notes( $record, 'ifthenpay request ID', $request_id, $source );

		$invoice = OsInvoicesHelper::get_matching_invoice_for_transaction( $transaction );
		if ( ! $invoice->is_new_record() ) {
			$transaction->invoice_id = $invoice->id;
		}

		if ( ! $transaction->save() ) {
			return null;
		}

		if ( ! $invoice->is_new_record() ) {
			$invoice->update_attributes( array( 'status' => LATEPOINT_INVOICE_STATUS_PAID ) );
		}

		OsOrdersHelper::check_if_order_invoices_paid_full_balance( $order->id );

		foreach ( $order->get_bookings_from_order_items( true ) as $booking ) {
			OsBookingHelper::change_booking_status( $booking->id, LATEPOINT_BOOKING_STATUS_APPROVED );
		}

		return $transaction;
	}

	/**
	 * Everything about this payment that isn't already the transaction's own token/amount/method —
	 * ifthenpay's own identifier for it (our token is what LatePoint shows as "Confirmation Code",
	 * consistent with the realtime flow's own convention; ifthenpay's identifier would only confuse
	 * a merchant reconciling by the token they already see everywhere else), the Multibanco
	 * entity/reference when this was a deferred payment, and how it was settled — all otherwise
	 * invisible once the row leaves the plugin's own table. Shared with
	 * IfthenpayLpPaymentProcessor::backfill_realtime_transaction_notes(): the only real difference
	 * between a deferred/callback/manual settlement and a realtime one is which identifier ifthenpay
	 * gave it — a request_id for the former, a transaction id for the latter, never
	 * interchangeable (see IfthenpayLpTransactionStatus's own docblock) — everything else about
	 * assembling the note is identical.
	 *
	 * @param object $record           The repository row.
	 * @param string $identifier_label What kind of identifier follows, e.g. "ifthenpay request ID"
	 *                                 or "ifthenpay transaction ID".
	 * @param string $identifier_value The identifier itself; the line is omitted when empty.
	 * @param string $source           'callback' | 'polling' | 'manual'.
	 */
	public static function build_transaction_notes( object $record, string $identifier_label, string $identifier_value, string $source ): string {
		$lines = array();
		if ( '' !== $identifier_value ) {
			$lines[] = $identifier_label . ': ' . $identifier_value;
		}

		// Payshop rows carry no entity (a reference stands alone there), unlike Multibanco's — this
		// must not fall back to requiring both, or a real Payshop reference silently disappears from
		// the note entirely instead of just dropping its (correctly absent) Entity label.
		if ( ! empty( $record->reference ) ) {
			$lines[] = empty( $record->entity )
				? 'Reference: ' . $record->reference
				: 'Entity: ' . $record->entity . ' | Reference: ' . $record->reference;
		}

		$lines[] = 'Settled via: ' . $source;

		return implode( "\n", $lines );
	}

	/**
	 * Compares as formatted strings, never as floats — a stray float comparison is exactly the
	 * kind of bug that silently accepts a wrong amount on some PHP builds and rejects a correct one
	 * on others.
	 *
	 * @param string      $expected_amount The record's own stored `amount` column.
	 * @param string|null $paid_amount     The payload's `amount`, or null if the caller omitted it.
	 */
	private static function amounts_match( string $expected_amount, ?string $paid_amount ): bool {
		if ( null === $paid_amount ) {
			return false;
		}

		return IfthenpayLpDataFormatter::format_amount( $expected_amount ) === IfthenpayLpDataFormatter::format_amount( $paid_amount );
	}
}
