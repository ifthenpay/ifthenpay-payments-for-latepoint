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
	 * The "Pay by" deadline, human-readable — shared by render_html() and render_email_html().
	 *
	 * @param object $record As returned by for_order()/for_booking().
	 */
	private static function deadline_for( object $record ): string {
		return null !== $record->expires_at
			? OsTimeHelper::get_readable_date( new OsWpDateTime( $record->expires_at, new DateTimeZone( 'UTC' ) ) )
			: '';
	}

	/**
	 * Renders the reference box. Escaping happens here, not at each call site. Markup uses
	 * `ifthenpay-reference-box-*` classes, styled in ifthenpay-payments-for-latepoint-front.css — a
	 * merchant who wants a different look can target those same classes with their own CSS (a child
	 * theme, or LatePoint's own custom CSS setting) without touching this file.
	 *
	 * @param object $record As returned by for_order()/for_booking().
	 */
	public static function render_html( object $record ): string {
		$is_paid  = 'PAID' === $record->status;
		$deadline = self::deadline_for( $record );

		ob_start();
		?>
		<div class="ifthenpay-reference-box <?php echo $is_paid ? 'is-paid' : 'is-pending'; ?>">
			<div class="ifthenpay-reference-box-title">
				<?php
				echo $is_paid
					? esc_html__( 'Multibanco payment', 'ifthenpay-payments-for-latepoint' )
					: esc_html__( 'Pay by Multibanco reference', 'ifthenpay-payments-for-latepoint' );
				?>
			</div>
			<?php if ( $is_paid ) : ?>
				<p class="ifthenpay-reference-box-paid-message"><?php echo esc_html__( 'Paid.', 'ifthenpay-payments-for-latepoint' ); ?></p>
			<?php else : ?>
				<p class="ifthenpay-reference-box-instructions">
					<?php echo esc_html__( 'Pay at any Multibanco ATM or via your bank\'s homebanking app, using the Entity and Reference below.', 'ifthenpay-payments-for-latepoint' ); ?>
				</p>
				<div class="ifthenpay-reference-box-details">
					<div class="ifthenpay-reference-box-row ifthenpay-reference-box-row-entity">
						<span class="ifthenpay-reference-box-label"><?php echo esc_html__( 'Entity', 'ifthenpay-payments-for-latepoint' ); ?></span>
						<span class="ifthenpay-reference-box-value"><?php echo esc_html( (string) $record->entity ); ?></span>
					</div>
					<div class="ifthenpay-reference-box-row ifthenpay-reference-box-row-reference">
						<span class="ifthenpay-reference-box-label"><?php echo esc_html__( 'Reference', 'ifthenpay-payments-for-latepoint' ); ?></span>
						<span class="ifthenpay-reference-box-value"><?php echo esc_html( (string) $record->reference ); ?></span>
					</div>
					<div class="ifthenpay-reference-box-row ifthenpay-reference-box-row-amount">
						<span class="ifthenpay-reference-box-label"><?php echo esc_html__( 'Amount', 'ifthenpay-payments-for-latepoint' ); ?></span>
						<span class="ifthenpay-reference-box-value"><?php echo esc_html( OsMoneyHelper::format_price( $record->amount, true, false ) ); ?></span>
					</div>
					<?php if ( '' !== $deadline ) : ?>
						<div class="ifthenpay-reference-box-row ifthenpay-reference-box-row-deadline">
							<span class="ifthenpay-reference-box-label"><?php echo esc_html__( 'Pay by', 'ifthenpay-payments-for-latepoint' ); ?></span>
							<span class="ifthenpay-reference-box-value"><?php echo esc_html( $deadline ); ?></span>
						</div>
					<?php endif; ?>
				</div>
				<div class="ifthenpay-reference-box-footer">
					<span><?php echo esc_html__( 'Powered by', 'ifthenpay-payments-for-latepoint' ); ?></span>
					<img src="<?php echo esc_url( IfthenpayPaymentsForLatepoint::images_url() . 'ifthenpay_brand.png' ); ?>" alt="ifthenpay" class="ifthenpay-reference-box-brand" />
				</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Email-safe rendering of the same data — HTML email clients never load this plugin's own
	 * stylesheet, so render_html()'s CSS-class markup reaches an inbox as bare, unstyled text once
	 * appended to a LatePoint notification (append_reference_to_email_content(), main plugin
	 * file). Uses OsPriceBreakdownHelper::output_price_breakdown_row( ..., true ) — the same
	 * inline-styled row primitive LatePoint's own "Order Summary" section in that same email
	 * already renders with — instead of inventing separate styling, so this reads as a native
	 * part of the email rather than a foreign block.
	 *
	 * @param object $record As returned by for_order()/for_booking().
	 */
	public static function render_email_html( object $record ): string {
		$is_paid = 'PAID' === $record->status;

		ob_start();
		?>
		<h4 style="margin-bottom: 10px; margin-top: 20px; font-size: 16px; font-weight: bold;">
			<?php
			echo $is_paid
				? esc_html__( 'Multibanco payment', 'ifthenpay-payments-for-latepoint' )
				: esc_html__( 'Pay by Multibanco reference', 'ifthenpay-payments-for-latepoint' );
			?>
		</h4>
		<?php if ( $is_paid ) : ?>
			<p><?php echo esc_html__( 'Paid.', 'ifthenpay-payments-for-latepoint' ); ?></p>
		<?php else : ?>
			<p><?php echo esc_html__( 'Pay at any Multibanco ATM or via your bank\'s homebanking app, using the Entity and Reference below.', 'ifthenpay-payments-for-latepoint' ); ?></p>
			<?php
			$rows     = array(
				array(
					'label' => __( 'Entity', 'ifthenpay-payments-for-latepoint' ),
					'value' => (string) $record->entity,
				),
				array(
					'label' => __( 'Reference', 'ifthenpay-payments-for-latepoint' ),
					'value' => (string) $record->reference,
				),
				array(
					'label' => __( 'Amount', 'ifthenpay-payments-for-latepoint' ),
					'value' => OsMoneyHelper::format_price( $record->amount, true, false ),
				),
			);
			$deadline = self::deadline_for( $record );
			if ( '' !== $deadline ) {
				$rows[] = array(
					'label' => __( 'Pay by', 'ifthenpay-payments-for-latepoint' ),
					'value' => $deadline,
				);
			}
			foreach ( $rows as $row ) {
				OsPriceBreakdownHelper::output_price_breakdown_row( $row, true );
			}
			?>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}
}
