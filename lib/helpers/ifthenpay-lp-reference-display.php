<?php
/**
 * Surfaces a deferred payment's own entity/reference/amount/deadline to the customer — on the
 * booking confirmation step, in the confirmation email, and in the customer dashboard (T-13, spec
 * 001), so a customer who loses the email can still recover the reference. One lookup, one render,
 * reused by every surface's own hook callback in the main plugin file.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deliberately shown for both PENDING and PAID rows — a customer who paid and refreshes the
 * confirmation step, or opens their dashboard later, should still be able to see what they paid
 * against, not have the box disappear the moment the callback lands.
 */
class IfthenpayLpReferenceDisplay {

	/**
	 * Finds the deferred payment record behind an order, if any.
	 *
	 * @param int $order_id A real, already-converted order id.
	 */
	public static function for_order( int $order_id ): ?object {
		$order_intent = ( new OsOrderIntentModel() )->where( array( 'order_id' => $order_id ) )->set_limit( 1 )->get_results_as_models();
		if ( ! $order_intent || $order_intent->is_new_record() ) {
			return null;
		}

		$record = IfthenpayLpTransactionRepository::find_by_intent_id( (int) $order_intent->id );
		if ( ! $record || 'deferred' !== $record->kind || empty( $record->reference ) ) {
			return null;
		}

		return $record;
	}

	/**
	 * Finds the deferred payment record behind a booking, via its own order.
	 *
	 * @param int $booking_id A real booking id.
	 */
	public static function for_booking( int $booking_id ): ?object {
		$booking = new OsBookingModel( $booking_id );
		if ( $booking->is_new_record() || empty( $booking->order_item_id ) ) {
			return null;
		}

		$order_item = new OsOrderItemModel( $booking->order_item_id );
		if ( $order_item->is_new_record() ) {
			return null;
		}

		return self::for_order( (int) $order_item->order_id );
	}

	/**
	 * Renders the reference box. Escaping happens here, not at each call site. Markup uses BEM-style
	 * classes (`ifthenpay-reference-box__*`), styled in ifthenpay-payments-for-latepoint-front.css —
	 * a merchant who wants a different look can target those same classes with their own CSS
	 * (a child theme, or LatePoint's own custom CSS setting) without touching this file.
	 *
	 * @param object $record As returned by for_order()/for_booking().
	 */
	public static function render_html( object $record ): string {
		$is_paid  = 'PAID' === $record->status;
		$deadline = null !== $record->expires_at
			? OsTimeHelper::get_readable_date( new OsWpDateTime( $record->expires_at, new DateTimeZone( 'UTC' ) ) )
			: '';

		ob_start();
		?>
		<div class="ifthenpay-reference-box <?php echo $is_paid ? 'is-paid' : 'is-pending'; ?>">
			<div class="ifthenpay-reference-box__title">
				<?php
				echo $is_paid
					? esc_html__( 'Multibanco payment', 'ifthenpay-payments-for-latepoint' )
					: esc_html__( 'Pay by Multibanco reference', 'ifthenpay-payments-for-latepoint' );
				?>
			</div>
			<?php if ( $is_paid ) : ?>
				<p class="ifthenpay-reference-box__paid-message"><?php echo esc_html__( 'Paid.', 'ifthenpay-payments-for-latepoint' ); ?></p>
			<?php else : ?>
				<div class="ifthenpay-reference-box__details">
					<div class="ifthenpay-reference-box__row ifthenpay-reference-box__row--entity">
						<span class="ifthenpay-reference-box__label"><?php echo esc_html__( 'Entity', 'ifthenpay-payments-for-latepoint' ); ?></span>
						<span class="ifthenpay-reference-box__value"><?php echo esc_html( (string) $record->entity ); ?></span>
					</div>
					<div class="ifthenpay-reference-box__row ifthenpay-reference-box__row--reference">
						<span class="ifthenpay-reference-box__label"><?php echo esc_html__( 'Reference', 'ifthenpay-payments-for-latepoint' ); ?></span>
						<span class="ifthenpay-reference-box__value"><?php echo esc_html( (string) $record->reference ); ?></span>
					</div>
					<div class="ifthenpay-reference-box__row ifthenpay-reference-box__row--amount">
						<span class="ifthenpay-reference-box__label"><?php echo esc_html__( 'Amount', 'ifthenpay-payments-for-latepoint' ); ?></span>
						<span class="ifthenpay-reference-box__value"><?php echo esc_html( OsMoneyHelper::format_price( $record->amount, true, false ) ); ?></span>
					</div>
					<?php if ( '' !== $deadline ) : ?>
						<div class="ifthenpay-reference-box__row ifthenpay-reference-box__row--deadline">
							<span class="ifthenpay-reference-box__label"><?php echo esc_html__( 'Pay by', 'ifthenpay-payments-for-latepoint' ); ?></span>
							<span class="ifthenpay-reference-box__value"><?php echo esc_html( $deadline ); ?></span>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
