<?php
/**
 * The hourly WP-Cron job that cancels deferred payments (Multibanco, Payshop) whose reference
 * expired unpaid, releasing the slot they were holding.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Takes the same lock settle_payment() uses, keyed the same way (the row's own request_id), so a
 * payment settling in the same instant the sweep reaches that row always wins: the sweep re-checks
 * inside the lock and only cancels what is still genuinely unpaid at that moment. cancel_now() is
 * the same cancellation, on demand — the ifthenpay Tools page's own manual Cancel action, for a row
 * a merchant has decided isn't getting paid without waiting for expiry.
 */
class IfthenpayLpExpirySweep {

	/**
	 * The WP-Cron hook name this class's own run() is bound to.
	 */
	public const HOOK = 'ifthenpay_lp_expiry_sweep';

	/**
	 * Processes every deferred row past its expiry that is still unpaid. A failure on one row (a
	 * lock held by a concurrent settlement, or an unexpected exception) is skipped, not fatal to
	 * the rest of the sweep — there is always a next hourly run.
	 */
	public static function run(): void {
		foreach ( IfthenpayLpTransactionRepository::find_expired_pending() as $record ) {
			self::expire_one( $record );
		}
	}

	/**
	 * Cancels one deferred row right now, under its own lock acquisition — not gated on expiry, and
	 * not called from run()'s own loop, so it does not nest inside that loop's own lock. Same
	 * outcome as a row the sweep itself reaches once expired: every booking on the order cancelled
	 * (releasing the slot), the row itself marked CANCELLED.
	 *
	 * @param string $token Our own correlation handle (the repository row's token column).
	 * @return bool Whether this call actually cancelled anything — false when the row doesn't
	 *              exist, has no request_id (a realtime row; nothing here applies to it), is
	 *              already settled, or is no longer PENDING.
	 */
	public static function cancel_now( string $token ): bool {
		$record = IfthenpayLpTransactionRepository::find_by_token( $token );
		if ( ! $record || null === $record->request_id ) {
			return false;
		}

		try {
			return IfthenpayLpSettlementLock::with_lock(
				(string) $record->request_id,
				static function () use ( $token ) {
					return self::cancel_locked( $token );
				}
			);
		} catch ( IfthenpayLpLockUnavailableException $e ) {
			// A concurrent settlement holds this row's lock right now — never silently cancel a
			// payment that may be settling at this exact moment; the caller can simply try again.
			unset( $e );
			return false;
		}
	}

	/**
	 * Handles a single row, under its own lock.
	 *
	 * @param object $record A row from find_expired_pending().
	 */
	private static function expire_one( object $record ): void {
		try {
			IfthenpayLpSettlementLock::with_lock(
				(string) $record->request_id,
				static function () use ( $record ) {
					self::cancel_locked( (string) $record->token );
				}
			);
		} catch ( IfthenpayLpLockUnavailableException $e ) {
			// A concurrent settlement (or a previous, still-running sweep) holds this row's lock
			// right now — leave it for the next hourly run rather than contend for it.
			unset( $e );
		}
	}

	/**
	 * Runs with the lock for $token's own request_id already held — shared by run()'s own per-row
	 * handling (expire_one()) and the on-demand cancel_now(), each of which acquires that lock
	 * itself before calling here, so this never acquires one of its own.
	 *
	 * @param string $token Re-read fresh here regardless of how the caller found it, since anything
	 *                      read before the lock may already be stale.
	 * @return bool Whether this call actually cancelled anything.
	 */
	private static function cancel_locked( string $token ): bool {
		$fresh = IfthenpayLpTransactionRepository::find_by_token( $token );
		if ( ! $fresh || null !== $fresh->settled_at || 'PENDING' !== $fresh->status ) {
			// Already settled (payment won the race) or already handled by an earlier call.
			return false;
		}

		$order_id = OsOrderIntentHelper::is_converted( (int) $fresh->intent_id );
		if ( ! $order_id ) {
			return false;
		}

		$order = new OsOrderModel( $order_id );
		if ( $order->is_new_record() ) {
			return false;
		}

		foreach ( $order->get_bookings_from_order_items( true ) as $booking ) {
			// The one status that actually releases the slot (availability excludes only
			// cancelled bookings) — nothing else does.
			OsBookingHelper::change_booking_status( $booking->id, LATEPOINT_BOOKING_STATUS_CANCELLED );
		}

		IfthenpayLpTransactionRepository::update_status( $fresh->token, 'CANCELLED' );

		return true;
	}
}
