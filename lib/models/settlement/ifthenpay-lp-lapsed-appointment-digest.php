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
	 * Bookings behind a still-PENDING deferred payment row whose appointment has already passed.
	 *
	 * Deliberately keyed off this add-on's own transactions table, not a LatePoint booking status:
	 * a deferred Multibanco/Payshop checkout does not set the booking to
	 * `LATEPOINT_BOOKING_STATUS_PAYMENT_PENDING` — it gets LatePoint's own `default_booking_status`
	 * (`approved` out of the box) like any other booking, and that constant isn't even in this
	 * LatePoint version's own status list any more. Querying on it (an earlier version of this
	 * method did) matches nothing, ever — silently disabling this whole safety net. The
	 * transactions table's own `kind = 'deferred' AND status = 'PENDING'` is the actual source of
	 * truth for "still outstanding under this add-on's flow"; `o.payment_status != FULLY_PAID` is
	 * kept alongside it to also skip a booking an agent already marked paid by hand outside that
	 * flow, without us finding out. `b.status != CANCELLED` covers the same idea the other
	 * direction: a booking the expiry sweep already cancelled also flips its transaction row to
	 * CANCELLED (excluded by `t.status = 'PENDING'` alone), but an admin cancelling the booking by
	 * hand, outside that sweep, would not — this still excludes it.
	 *
	 * @return string[] Booking ids.
	 */
	private static function find_lapsed_booking_ids(): array {
		global $wpdb;
		$transactions_table = IfthenpayLpTransactionRepository::table_name();

		// A daily cron tick over a possibly large set, not a per-request lookup; every table name
		// is either this add-on's own (built from $wpdb->prefix, no user-controlled part) or one of
		// LatePoint's own constants, and %s placeholders cover every real value.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_col(
			$wpdb->prepare(
				'SELECT b.id FROM `' . $transactions_table . '` t
				INNER JOIN `' . LATEPOINT_TABLE_ORDER_INTENTS . '` oi ON oi.id = t.intent_id
				INNER JOIN `' . LATEPOINT_TABLE_ORDER_ITEMS . '` oit ON oit.order_id = oi.order_id
				INNER JOIN `' . LATEPOINT_TABLE_BOOKINGS . '` b ON b.order_item_id = oit.id
				INNER JOIN `' . LATEPOINT_TABLE_ORDERS . '` o ON o.id = oi.order_id
				WHERE t.kind = %s AND t.status = %s AND oi.order_id IS NOT NULL
				AND b.status != %s AND b.start_datetime_utc < %s AND o.payment_status != %s',
				'deferred',
				'PENDING',
				LATEPOINT_BOOKING_STATUS_CANCELLED,
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

		// Payshop rows carry no entity, unlike Multibanco's (see IfthenpayLpSettlement::build_transaction_notes()'s
		// own comment on this) — checks for a present entity rather than a truthy one, since
		// reference/entity are opaque strings that may legitimately be "0" or similar.
		$reference_record = IfthenpayLpReferenceDisplay::for_order( (int) $order->id );
		$reference        = $reference_record
			? ( null !== $reference_record->entity && '' !== $reference_record->entity
				? $reference_record->entity . '/' . $reference_record->reference
				: $reference_record->reference )
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
