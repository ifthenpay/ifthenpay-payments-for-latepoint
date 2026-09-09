<?php
/**
 * Surfaces a deferred payment's own entity/reference/amount/deadline/token to the customer — on
 * the booking confirmation step, in the confirmation email, and in the customer dashboard, so a
 * customer who loses the email can still recover the reference. The token is
 * shown too (both states, not just pending): it's our own correlation handle, but also what
 * ifthenpay itself was given as the order id (Pay By Link's `id`, the Multibanco reference's
 * `orderId`) — the identifier ifthenpay support would recognise for either flow. One lookup, one
 * render, reused by every surface's own hook callback in the main plugin file.
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
	 * Every string both renderers show, in one place — a wording change here reaches the
	 * confirmation step, the email, and the dashboard tile together, rather than risking one
	 * surface being edited and the other forgotten.
	 *
	 * @param bool $is_paid Whether $record is PAID (vs. still pending).
	 * @return string
	 */
	private static function title_for( bool $is_paid ): string {
		return $is_paid
			? __( 'Multibanco payment', 'ifthenpay-payments-for-latepoint' )
			: __( 'Pay by Multibanco reference', 'ifthenpay-payments-for-latepoint' );
	}

	/**
	 * The label for the token row — shown in both states, unlike the entity/reference/amount rows.
	 *
	 * @return string
	 */
	private static function order_id_label(): string {
		return __( 'ifthenpay Order ID', 'ifthenpay-payments-for-latepoint' );
	}

	/**
	 * Shown once a record is PAID, in place of the payment instructions.
	 *
	 * @return string
	 */
	private static function paid_message(): string {
		return __( 'Paid.', 'ifthenpay-payments-for-latepoint' );
	}

	/**
	 * Shown while a record is still pending, above the entity/reference/amount details.
	 *
	 * @return string
	 */
	private static function payment_instructions(): string {
		return __( 'Pay at any Multibanco ATM or via your bank\'s homebanking app, using the Entity and Reference below.', 'ifthenpay-payments-for-latepoint' );
	}

	/**
	 * The Entity/Reference/Amount/Pay-by rows — only shown while pending (render_html()'s own
	 * $is_paid branch already hides all of this once paid, same as render_email_html()). Each
	 * carries its own `key`, so render_html() can build its per-row CSS class
	 * (`ifthenpay-reference-box-row-{key}`) from the same data instead of hardcoding it once per
	 * field on top of the field's own label/value.
	 *
	 * @param object $record As returned by for_order()/for_booking().
	 * @return array<int,array{key:string,label:string,value:string}>
	 */
	private static function detail_rows( object $record ): array {
		$rows = array(
			array(
				'key'   => 'entity',
				'label' => __( 'Entity', 'ifthenpay-payments-for-latepoint' ),
				'value' => (string) $record->entity,
			),
			array(
				'key'   => 'reference',
				'label' => __( 'Reference', 'ifthenpay-payments-for-latepoint' ),
				'value' => (string) $record->reference,
			),
			array(
				'key'   => 'amount',
				'label' => __( 'Amount', 'ifthenpay-payments-for-latepoint' ),
				'value' => OsMoneyHelper::format_price( $record->amount, true, false ),
			),
		);

		$deadline = self::deadline_for( $record );
		if ( '' !== $deadline ) {
			$rows[] = array(
				'key'   => 'deadline',
				'label' => __( 'Pay by', 'ifthenpay-payments-for-latepoint' ),
				'value' => $deadline,
			);
		}

		return $rows;
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
		$is_paid = 'PAID' === $record->status;

		ob_start();
		?>
		<div class="ifthenpay-reference-box <?php echo $is_paid ? 'is-paid' : 'is-pending'; ?>">
			<div class="ifthenpay-reference-box-title">
				<?php echo esc_html( self::title_for( $is_paid ) ); ?>
			</div>
			<div class="ifthenpay-reference-box-row ifthenpay-reference-box-row-order-id">
				<span class="ifthenpay-reference-box-label"><?php echo esc_html( self::order_id_label() ); ?></span>
				<span class="ifthenpay-reference-box-value"><?php echo esc_html( (string) $record->token ); ?></span>
			</div>
			<?php if ( $is_paid ) : ?>
				<p class="ifthenpay-reference-box-paid-message"><?php echo esc_html( self::paid_message() ); ?></p>
			<?php else : ?>
				<p class="ifthenpay-reference-box-instructions">
					<?php echo esc_html( self::payment_instructions() ); ?>
				</p>
				<div class="ifthenpay-reference-box-details">
					<?php foreach ( self::detail_rows( $record ) as $row ) : ?>
						<div class="ifthenpay-reference-box-row ifthenpay-reference-box-row-<?php echo esc_attr( $row['key'] ); ?>">
							<span class="ifthenpay-reference-box-label"><?php echo esc_html( $row['label'] ); ?></span>
							<span class="ifthenpay-reference-box-value"><?php echo esc_html( $row['value'] ); ?></span>
						</div>
					<?php endforeach; ?>
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
	 * file). Confirmed directly against LatePoint core (mailers/layouts/default.html,
	 * mailers/customer/order_created.html, and OsPriceBreakdownHelper::output_price_breakdown_row())
	 * that LatePoint's own emails are 100% hand-inlined too — there is no CSS inliner anywhere in
	 * its mail pipeline (OsMailer::render() just includes the layout and hands the result straight
	 * to wp_mail()), so a class-based approach would not survive a real inbox either. This reuses
	 * output_price_breakdown_row( ..., true ) — the same inline-styled row primitive LatePoint's own
	 * "Order Summary" section already renders with, `style => 'total'` included — rather than
	 * hand-rolling separate styling for the one row that matters most (Amount).
	 *
	 * Wrapped in the exact same two-level structure as mailers/layouts/default.html — the outer
	 * grey page gutter (`padding: 20px; background-color: #f0f0f0`) *and* the inner white card —
	 * copied by value, not read from that file: it's a stored, merchant-editable option
	 * (`email_layout_template`) by the time this runs, not a template this plugin can hook into.
	 * $action->prepared_data_for_run['content'] (append_reference_to_email_content()) is that
	 * layout already fully rendered, outer gutter included — so appending after it lands on the
	 * plain <body> background with no gutter of its own unless this reproduces one. Confirmed live
	 * in Mailpit: without the outer wrapper, this card sits flush against the inbox edges instead of
	 * inset like every other section of the same email.
	 *
	 * @param object $record As returned by for_order()/for_booking().
	 */
	public static function render_email_html( object $record ): string {
		$is_paid = 'PAID' === $record->status;

		ob_start();
		?>
		<div style="padding: 20px; background-color: #f0f0f0; font-family: -apple-system, system-ui, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;">
			<div style="background-color: #fff; padding: 30px; margin: 0px auto; max-width: 450px; box-shadow: 0px 2px 6px -1px rgba(0,0,0,0.2); border-radius: 6px; font-size: 16px; line-height: 1.5;">
				<h4 style="margin-bottom: 10px; margin-top: 0; font-size: 16px; font-weight: bold;">
					<?php echo esc_html( self::title_for( $is_paid ) ); ?>
				</h4>
				<?php
				OsPriceBreakdownHelper::output_price_breakdown_row(
					array(
						'label' => self::order_id_label(),
						'value' => (string) $record->token,
					),
					true
				);
				?>
				<?php if ( $is_paid ) : ?>
					<p><?php echo esc_html( self::paid_message() ); ?></p>
				<?php else : ?>
					<p><?php echo esc_html( self::payment_instructions() ); ?></p>
					<?php
					foreach ( self::detail_rows( $record ) as $row ) {
						// Matches LatePoint's own "Balance Due" treatment in the same email: the thick
						// top border output_price_breakdown_row() already draws for style => 'total',
						// so the one figure the customer actually needs (Amount) stands out the same
						// way LatePoint's own total row does, not a bespoke style of our own.
						if ( 'amount' === $row['key'] ) {
							$row['style'] = 'total';
						}
						OsPriceBreakdownHelper::output_price_breakdown_row( $row, true );
					}
					?>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
