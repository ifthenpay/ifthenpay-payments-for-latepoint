<?php
/**
 * How many whole calendar days stand between now and the earliest booking appointment in a cart —
 * shared by the deferred-reference validity clamp (IfthenpayLpPaymentProcessor, which must never
 * let a reference outlive the appointment it pays for) and the minimum-lead-time checkout gate
 * (IfthenpayLpPaymentMethodAvailability, which stops offering a deferred method once an appointment
 * is too close), so the two can never disagree about what "N days from now" means for the same cart.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calendar-day distance, not a 24-hour rolling window — "tomorrow morning" and "tomorrow evening"
 * both count as 1, matching how a merchant reads a whole-days setting. Computed in UTC, the same
 * basis IfthenpayLpExpiry's own day arithmetic already uses (plain time()/strtotime(), not
 * current_time()), so the two never disagree about what "N days from now" means.
 */
class IfthenpayLpAppointmentLeadTime {

	/**
	 * The earliest booking item's own start date, across every booking-variant item in the cart —
	 * a single order/cart can hold several (multi-service checkout), and LatePoint core has no
	 * concept of a "primary" one among them; verified directly against LatePoint's own conversion
	 * code, which iterates every item the same way. Reconstructs each item via
	 * OsCartItemModel::build_original_object_from_item_data() — the same method
	 * OsOrderIntentModel::convert_to_order() itself calls — rather than hand-parsing item_data JSON.
	 *
	 * @param OsCartModel $cart A real cart — either built from an order intent
	 *                          (OsOrderIntentModel::build_cart_object()) or reconstructed from the
	 *                          visitor's own cart cookie (OsCartsHelper::get_or_create_cart()).
	 * @return int|null Whole calendar days from today to the earliest appointment; 0 for today,
	 *                   negative if somehow already in the past. Null if the cart has no booking
	 *                   items at all — nothing to clamp or gate against.
	 */
	public static function days_until_earliest_booking( OsCartModel $cart ): ?int {
		$earliest_utc = null;

		foreach ( $cart->get_items() as $item ) {
			if ( ! $item->is_booking() ) {
				continue;
			}

			$booking = $item->build_original_object_from_item_data();
			if ( empty( $booking->start_datetime_utc ) ) {
				continue;
			}

			// LATEPOINT_DATETIME_DB_FORMAT ('Y-m-d H:i:s') sorts lexicographically like a real
			// datetime — no need to parse before comparing.
			if ( null === $earliest_utc || $booking->start_datetime_utc < $earliest_utc ) {
				$earliest_utc = $booking->start_datetime_utc;
			}
		}

		if ( null === $earliest_utc ) {
			return null;
		}

		$today       = gmdate( 'Y-m-d' );
		$appointment = gmdate( 'Y-m-d', strtotime( $earliest_utc ) );

		return (int) round( ( strtotime( $appointment ) - strtotime( $today ) ) / DAY_IN_SECONDS );
	}
}
