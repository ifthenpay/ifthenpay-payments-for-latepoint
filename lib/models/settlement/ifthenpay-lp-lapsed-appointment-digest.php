<?php
/**
 * The daily WP-Cron job that tells each agent about their own bookings whose appointment time has
 * passed while the order is still not fully paid — the safety net for the expiry sweep
 * (IfthenpayLpExpirySweep) not having run in time (WP-Cron only fires on site traffic, so a
 * low-traffic site can leave a lapsed reference uncancelled for hours), plus bookings that predate
 * this feature, clock skew, and manual admin edits.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deliberately does not touch booking status — no auto-`no_show`. The system cannot know whether
 * the customer showed up, paid cash, or was seen anyway; this only ever informs, never decides.
 * Scoped per agent, not one global merchant digest — a global digest would expose one agent's
 * customers to another in a multi-agent business, and would force the admin to forward by hand.
 */
class IfthenpayLpLapsedAppointmentDigest {

	/**
	 * The WP-Cron hook name this class's own run() is bound to.
	 */
	public const HOOK = 'ifthenpay_lp_lapsed_appointment_digest';

	/**
	 * Finds every lapsed, still-unpaid booking, groups it by the agent it belongs to, and sends one
	 * email per agent. An agent with nothing lapsed gets no email at all.
	 */
	public static function run(): void {
		$bookings_by_agent = array();

		foreach ( self::find_lapsed_booking_ids() as $booking_id ) {
			$booking = new OsBookingModel( (int) $booking_id );
			if ( $booking->is_new_record() || empty( $booking->agent_id ) ) {
				continue;
			}
			$bookings_by_agent[ (int) $booking->agent_id ][] = $booking;
		}

		foreach ( $bookings_by_agent as $agent_id => $bookings ) {
			self::send_digest_for_agent( (int) $agent_id, $bookings );
		}
	}

	/**
	 * Bookings whose appointment has already passed while the order behind them is still not fully
	 * paid — joins to order_items/orders since payment status lives on the order, not the booking.
	 * Scoped to `payment_pending` specifically: the one status a deferred Multibanco checkout
	 * commits its booking as, while still held against the calendar and unpaid — a booking pending
	 * for any other reason is not this feature's concern.
	 *
	 * @return string[] Booking ids.
	 */
	private static function find_lapsed_booking_ids(): array {
		global $wpdb;

		// A daily cron tick over a possibly large set, not a per-request lookup; the three table
		// names are LatePoint's own constants, not user-controlled, and %s placeholders cover
		// every real value.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_col(
			$wpdb->prepare(
				'SELECT b.id FROM `' . LATEPOINT_TABLE_BOOKINGS . '` b
				INNER JOIN `' . LATEPOINT_TABLE_ORDER_ITEMS . '` oi ON oi.id = b.order_item_id
				INNER JOIN `' . LATEPOINT_TABLE_ORDERS . '` o ON o.id = oi.order_id
				WHERE b.status = %s AND b.start_datetime_utc < %s AND o.payment_status != %s',
				LATEPOINT_BOOKING_STATUS_PAYMENT_PENDING,
				current_time( 'mysql', true ),
				LATEPOINT_ORDER_PAYMENT_STATUS_FULLY_PAID
			)
		);
		// phpcs:enable
	}

	/**
	 * One email, one line per booking. An agent with no email on file (or a not-found row — a
	 * dangling agent_id, not expected but not worth failing the whole run over) is silently skipped;
	 * there is always tomorrow's run.
	 *
	 * @param int              $agent_id The agent every booking in $bookings belongs to.
	 * @param OsBookingModel[] $bookings This agent's own lapsed, unpaid bookings.
	 */
	private static function send_digest_for_agent( int $agent_id, array $bookings ): void {
		$agent = new OsAgentModel( $agent_id );
		if ( $agent->is_new_record() || empty( $agent->email ) ) {
			return;
		}

		$lines = array();
		foreach ( $bookings as $booking ) {
			$lines[] = self::line_for_booking( $booking );
		}

		$subject = sprintf(
			/* translators: %d: number of lapsed bookings in this digest */
			_n( '%d appointment passed with no payment recorded', '%d appointments passed with no payment recorded', count( $lines ), 'ifthenpay-payments-for-latepoint' ),
			count( $lines )
		);

		$body = esc_html__( 'These appointments have already happened, and their order is not marked fully paid yet. This is not a judgment on the customer — they may have paid in cash, or the payment may still be on its way. No booking status has been changed.', 'ifthenpay-payments-for-latepoint' )
			. "\n\n" . implode( "\n", $lines );

		OsMailer::send_email( $agent->email, $subject, $body, array() );
	}

	/**
	 * One line: enough to act on without opening the backoffice. The ifthenpay reference is what
	 * the customer has in their own email — it is how a "but I paid" call gets resolved.
	 *
	 * @param OsBookingModel $booking A lapsed, unpaid booking.
	 */
	private static function line_for_booking( OsBookingModel $booking ): string {
		$customer = $booking->customer;
		$service  = $booking->service;
		$order    = $booking->order;

		$contact = ! empty( $customer->email ) ? $customer->email : (string) $customer->phone;

		// Plain WordPress core date functions, not a LatePoint-specific formatter: wp_date()
		// converts from a Unix timestamp to the site's own configured timezone and locale in one
		// call, using the site's own date_format/time_format options — no guessing at a LatePoint
		// helper name for something core WordPress already does correctly.
		$timestamp      = strtotime( $booking->start_datetime_utc . ' UTC' );
		$formatted_date = false !== $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) : $booking->start_date;
		$formatted_time = false !== $timestamp ? wp_date( get_option( 'time_format' ), $timestamp ) : '';

		$reference_record = IfthenpayLpReferenceDisplay::for_order( (int) $order->id );
		$reference        = $reference_record
			? $reference_record->entity . '/' . $reference_record->reference
			: __( 'none on file', 'ifthenpay-payments-for-latepoint' );

		return sprintf(
			'%1$s (%2$s) — %3$s — %4$s %5$s — %6$s — %7$s: %8$s — %9$s: %10$s',
			$customer->full_name,
			$contact,
			$service->name,
			$formatted_date,
			$formatted_time,
			OsMoneyHelper::format_price( $order->total, true, false ),
			__( 'Reference', 'ifthenpay-payments-for-latepoint' ),
			$reference,
			__( 'Confirmation', 'ifthenpay-payments-for-latepoint' ),
			$order->confirmation_code
		);
	}
}
