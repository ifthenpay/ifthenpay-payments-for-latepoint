<?php
/**
 * The hourly WP-Cron job that cancels deferred payments (Multibanco) whose reference expired
 * unpaid, releasing the slot they were holding (D-3/D-4). See
 * specs/001-multibanco-deferred/plan.md §7 and contracts/settlement.md's "Interaction with the
 * expiry job".
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Takes the same lock settle_payment() uses, keyed the same way (the row's own request_id), so a
 * payment settling in the same instant the sweep reaches that row always wins: the sweep re-checks
 * inside the lock and only cancels what is still genuinely unpaid at that moment.
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
	 * Handles a single row, under its own lock.
	 *
	 * @param object $record A row from find_expired_pending().
	 */
	private static function expire_one( object $record ): void {
		try {
			IfthenpayLpSettlementLock::with_lock(
				(string) $record->request_id,
				static function () use ( $record ) {
					self::expire_locked( $record );
				}
			);
		} catch ( IfthenpayLpLockUnavailableException $e ) {
			// A concurrent settlement (or a previous, still-running sweep) holds this row's lock
			// right now — leave it for the next hourly run rather than contend for it.
			unset( $e );
		}
	}

	/**
	 * Runs with the lock for $record's own request_id already held.
	 *
	 * @param object $record As passed to expire_one() — re-read fresh here regardless, since
	 *                       anything read before the lock may already be stale.
	 */
	private static function expire_locked( object $record ): void {
		$fresh = IfthenpayLpTransactionRepository::find_by_token( (string) $record->token );
		if ( ! $fresh || null !== $fresh->settled_at || 'PENDING' !== $fresh->status ) {
			// Already settled (payment won the race) or already handled by an earlier sweep.
			return;
		}

		$order_id = OsOrderIntentHelper::is_converted( (int) $fresh->intent_id );
		if ( ! $order_id ) {
			return;
		}

		$order = new OsOrderModel( $order_id );
		if ( $order->is_new_record() ) {
			return;
		}

		foreach ( $order->get_bookings_from_order_items( true ) as $booking ) {
			// The one status that actually releases the slot (D-3: availability excludes only
			// cancelled bookings) — nothing else does.
			OsBookingHelper::change_booking_status( $booking->id, LATEPOINT_BOOKING_STATUS_CANCELLED );
		}

		IfthenpayLpTransactionRepository::update_status( $fresh->token, 'CANCELLED' );
	}
}
