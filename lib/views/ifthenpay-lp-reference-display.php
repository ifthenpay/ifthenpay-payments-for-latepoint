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
	 * The method's own brand mark — the header's only way of identifying Multibanco vs. Payshop
	 * now, in both renderers; neither one names the method in text anymore, and neither wraps it in
	 * a chip any more either, so the mark itself needs to read clearly at a glance on its own.
	 * Width/height are given explicitly (not just left to CSS) because render_email_html() has no
	 * stylesheet to fall back on — both images are a fixed 200px-tall source (692×200 Multibanco,
	 * 756×200 Payshop), so the width here is that same source ratio at a shared 24px display height,
	 * rather than one image getting stretched or squashed to match the other's box.
	 *
	 * @param string $method $record->method — 'MB' or 'PAYSHOP'.
	 * @return array{label:string,url:string,width:int,height:int}
	 */
	private static function method_icon( string $method ): array {
		if ( 'PAYSHOP' === $method ) {
			return array(
				'label'  => 'Payshop',
				'url'    => IfthenpayPaymentsForLatepoint::images_url() . 'payshop-brand.png',
				'width'  => 91,
				'height' => 24,
			);
		}

		return array(
			'label'  => 'Multibanco',
			'url'    => IfthenpayPaymentsForLatepoint::images_url() . 'multibanco-brand.png',
			'width'  => 83,
			'height' => 24,
		);
	}

	/**
	 * The compact "Order: #{token}" badge shown top-right of both renderers' own header row, next
	 * to method_icon() — the token's only surviving label now that neither renderer gives it a
	 * full labeled row of its own.
	 *
	 * @param string $token $record->token.
	 * @return string
	 */
	private static function order_badge_text( string $token ): string {
		return sprintf(
			/* translators: %s: ifthenpay order id/token. */
			__( 'Order: #%s', 'ifthenpay-payments-for-latepoint' ),
			$token
		);
	}

	/**
	 * Groups a reference into 3-character chunks ("123456789" -> "123 456 789") for on-screen
	 * readability, in both renderers now that render_email_html() mirrors render_html()'s own card
	 * layout. detail_rows() itself stays ungrouped — grouping is a display concern, not a storage
	 * one. Generic str_split(), not a hardcoded 9-digit assumption: Multibanco references are 9
	 * digits in practice, but nothing guarantees that length (the `reference` column is a plain
	 * VARCHAR), so this groups whatever length shows up rather than mis-rendering a longer/shorter
	 * one.
	 *
	 * @param string $reference As stored in $record->reference — never empty (for_order()/
	 *                          for_booking() already reject records with an empty reference).
	 * @return string
	 */
	private static function group_reference( string $reference ): string {
		return implode( ' ', str_split( $reference, 3 ) );
	}

	/**
	 * Splits detail_rows() into the rows the details grid shows and the deadline that instead moves
	 * down into the card's own footer, with 'reference' grouped for readability — shared by
	 * render_html() and render_email_html() now that both use the same card layout.
	 *
	 * @param object $record As returned by for_order()/for_booking().
	 * @return array{0: array<int,array{key:string,label:string,value:string}>, 1: string} [$details, $deadline]
	 */
	private static function split_detail_rows( object $record ): array {
		$deadline = '';
		$details  = array();
		foreach ( self::detail_rows( $record ) as $row ) {
			if ( 'deadline' === $row['key'] ) {
				$deadline = $row['value'];
				continue;
			}
			if ( 'reference' === $row['key'] ) {
				$row['value'] = self::group_reference( $row['value'] );
			}
			$details[] = $row;
		}
		return array( $details, $deadline );
	}

	/**
	 * Shown once a record is PAID, in place of the payment instructions — carries the amount that
	 * was actually paid, not just the bare word "Paid.": a merchant's own reconciliation question
	 * ("paid how much?") shouldn't need opening the order just to answer what this card already
	 * knows.
	 *
	 * @param string $formatted_amount Already formatted with its currency symbol, e.g. "0.10€" —
	 *                                  same shape as detail_rows()'s own 'amount' row.
	 * @return string
	 */
	private static function paid_message( string $formatted_amount ): string {
		return sprintf(
			/* translators: %s: the amount that was paid, already formatted with its currency symbol. */
			__( 'Paid %s', 'ifthenpay-payments-for-latepoint' ),
			$formatted_amount
		);
	}

	/**
	 * Shown while a record is still pending, above the reference/amount details. Payshop has no
	 * entity — a reference stands alone at any Payshop agent or CTT counter — so its own copy
	 * doesn't mention one, rather than reusing Multibanco's sentence with "Entity and" removed.
	 *
	 * @param string $method $record->method — 'MB' or 'PAYSHOP'.
	 * @return string
	 */
	private static function payment_instructions( string $method ): string {
		if ( 'PAYSHOP' === $method ) {
			return __( 'Pay at a Payshop agent or CTT, using the Reference below.', 'ifthenpay-payments-for-latepoint' );
		}

		return __( 'Pay at any Multibanco ATM or via your bank\'s homebanking app, using the Entity and Reference below.', 'ifthenpay-payments-for-latepoint' );
	}

	/**
	 * The Reference/Amount/Pay-by rows (Multibanco also gets an Entity row; Payshop's own
	 * reference stands alone) — only shown while pending (render_html()'s own $is_paid branch
	 * already hides all of this once paid, same as render_email_html()). Each carries its own
	 * `key`, so render_html() can build its per-row CSS class
	 * (`ifthenpay-reference-box-row-{key}`) from the same data instead of hardcoding it once per
	 * field on top of the field's own label/value.
	 *
	 * @param object $record As returned by for_order()/for_booking().
	 * @return array<int,array{key:string,label:string,value:string}>
	 */
	private static function detail_rows( object $record ): array {
		$rows = array();

		if ( 'PAYSHOP' !== $record->method ) {
			$rows[] = array(
				'key'   => 'entity',
				'label' => __( 'Entity', 'ifthenpay-payments-for-latepoint' ),
				'value' => (string) $record->entity,
			);
		}

		$rows[] = array(
			'key'   => 'reference',
			'label' => __( 'Reference', 'ifthenpay-payments-for-latepoint' ),
			'value' => (string) $record->reference,
		);
		$rows[] = array(
			'key'   => 'amount',
			'label' => __( 'Amount', 'ifthenpay-payments-for-latepoint' ),
			'value' => OsMoneyHelper::format_price( $record->amount, true, false ),
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

		// The 'deadline' row moves down into the footer (next to "Powered by", below the dashed
		// divider) instead of sitting in the entity/reference/amount grid — see split_detail_rows().
		list( $details, $deadline ) = self::split_detail_rows( $record );

		ob_start();
		?>
		<div class="ifthenpay-reference-box <?php echo $is_paid ? 'is-paid' : 'is-pending'; ?>">
			<?php $icon = self::method_icon( $record->method ); ?>
			<div class="ifthenpay-reference-box-header">
				<img
					src="<?php echo esc_url( $icon['url'] ); ?>"
					alt="<?php echo esc_attr( $icon['label'] ); ?>"
					width="<?php echo esc_attr( (string) $icon['width'] ); ?>"
					height="<?php echo esc_attr( (string) $icon['height'] ); ?>"
					class="ifthenpay-reference-box-mark"
				/>
				<span class="ifthenpay-reference-box-order-id">
					<?php echo esc_html( self::order_badge_text( (string) $record->token ) ); ?>
				</span>
			</div>
			<?php if ( $is_paid ) : ?>
				<p class="ifthenpay-reference-box-paid-message">
					<span class="ifthenpay-reference-box-paid-icon" aria-hidden="true">&#10003;</span>
					<?php echo esc_html( self::paid_message( OsMoneyHelper::format_price( $record->amount, true, false ) ) ); ?>
				</p>
			<?php else : ?>
				<p class="ifthenpay-reference-box-instructions">
					<?php echo esc_html( self::payment_instructions( $record->method ) ); ?>
				</p>
				<div class="ifthenpay-reference-box-details">
					<?php foreach ( $details as $row ) : ?>
						<div class="ifthenpay-reference-box-row ifthenpay-reference-box-row-<?php echo esc_attr( $row['key'] ); ?>">
							<span class="ifthenpay-reference-box-label"><?php echo esc_html( $row['label'] ); ?></span>
							<span class="ifthenpay-reference-box-value"><?php echo esc_html( $row['value'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div class="ifthenpay-reference-box-footer">
				<?php if ( ! $is_paid && '' !== $deadline ) : ?>
					<span class="ifthenpay-reference-box-deadline">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: human-readable "pay by" deadline date. */
								__( 'Pay by: %s', 'ifthenpay-payments-for-latepoint' ),
								$deadline
							)
						);
						?>
					</span>
				<?php endif; ?>
				<span class="ifthenpay-reference-box-powered-by">
					<span><?php echo esc_html__( 'Powered by', 'ifthenpay-payments-for-latepoint' ); ?></span>
					<img src="<?php echo esc_url( IfthenpayPaymentsForLatepoint::images_url() . 'ifthenpay-brand.png' ); ?>" alt="ifthenpay" class="ifthenpay-reference-box-brand" />
				</span>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * One details-grid row, email-safe — a table instead of render_html()'s flex row, styled to
	 * match its `.ifthenpay-reference-box-row`/`-label`/`-value` counterparts inline since email
	 * clients never load this plugin's own stylesheet. 'reference' and 'amount' each get the same
	 * per-key emphasis their CSS counterpart does (monospace; green and larger, respectively).
	 *
	 * @param array{key:string,label:string,value:string} $row One of split_detail_rows()'s $details.
	 */
	private static function render_email_detail_row( array $row ): void {
		$value_style = 'font-weight: 700; font-size: 15px; color: #18181b; text-align: right;';
		if ( 'reference' === $row['key'] ) {
			$value_style = 'font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, monospace; font-weight: 700; font-size: 17px; letter-spacing: 1px; color: #18181b; text-align: right;';
		} elseif ( 'amount' === $row['key'] ) {
			$value_style = 'font-weight: 700; font-size: 19px; color: #059669; text-align: right;';
		}
		?>
		<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 10px;">
			<tr>
				<td style="text-align: left; color: #71717a; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.6px;">
					<?php echo esc_html( $row['label'] ); ?>
				</td>
				<td style="<?php echo esc_attr( $value_style ); ?>">
					<?php echo esc_html( $row['value'] ); ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Email-safe rendering of the same data, styled to match render_html()'s own card (method
	 * badge + order-id header, grouped reference, footer deadline/"Powered by") rather than
	 * LatePoint's own email look — so a customer sees one consistent design between the
	 * confirmation step and the confirmation email, not two different-looking reference boxes.
	 * HTML email clients never load this plugin's own stylesheet, so render_html()'s CSS-class
	 * markup reaches an inbox as bare, unstyled text once appended to a LatePoint notification
	 * (append_reference_to_email_content(), main plugin file); every rule here is inlined, and
	 * layout uses tables rather than render_html()'s flexbox, for the same reason.
	 *
	 * Wrapped in the exact same outer structure as mailers/layouts/default.html's own page gutter
	 * (`padding: 20px; background-color: #f0f0f0`) — copied by value, not read from that file: it's
	 * a stored, merchant-editable option (`email_layout_template`) by the time this runs, not a
	 * template this plugin can hook into. $action->prepared_data_for_run['content']
	 * (append_reference_to_email_content()) is that layout already fully rendered, outer gutter
	 * included — so appending after it lands on the plain <body> background with no gutter of its
	 * own unless this reproduces one. Confirmed live in Mailpit: without the outer wrapper, this
	 * card sits flush against the inbox edges instead of inset like every other section of the same
	 * email.
	 *
	 * @param object $record As returned by for_order()/for_booking().
	 */
	public static function render_email_html( object $record ): string {
		$is_paid = 'PAID' === $record->status;
		$icon    = self::method_icon( $record->method );

		list( $details, $deadline ) = self::split_detail_rows( $record );

		$card_style = $is_paid
			? 'background-color: #ecfdf5; padding: 20px 22px; margin: 0px auto; max-width: 450px; border: 1px solid #a7f3d0; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); font-size: 16px; line-height: 1.5;'
			: 'background-color: #fff; padding: 20px 22px; margin: 0px auto; max-width: 450px; border: 1px solid #ececef; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); font-size: 16px; line-height: 1.5;';

		ob_start();
		?>
		<div style="padding: 20px; background-color: #f0f0f0; font-family: -apple-system, system-ui, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;">
			<div style="<?php echo esc_attr( $card_style ); ?>">
				<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: <?php echo $is_paid ? '4px' : '16px'; ?>;">
					<tr>
						<td style="text-align: left; vertical-align: middle;">
							<img
								src="<?php echo esc_url( $icon['url'] ); ?>"
								alt="<?php echo esc_attr( $icon['label'] ); ?>"
								width="<?php echo esc_attr( (string) $icon['width'] ); ?>"
								height="<?php echo esc_attr( (string) $icon['height'] ); ?>"
								style="display: block; vertical-align: middle;"
							/>
						</td>
						<td style="text-align: right; vertical-align: middle; font-size: 12px; font-weight: 500; color: #a1a1aa; white-space: nowrap;">
							<?php echo esc_html( self::order_badge_text( (string) $record->token ) ); ?>
						</td>
					</tr>
				</table>
				<?php if ( $is_paid ) : ?>
					<p style="margin: 0; color: #047857; font-weight: 600;">
						<span style="display: inline-block; width: 18px; height: 18px; line-height: 18px; text-align: center; border-radius: 50%; background-color: #10b981; color: #fff; font-size: 11px; margin-right: 6px; vertical-align: middle;">&#10003;</span>
						<?php echo esc_html( self::paid_message( OsMoneyHelper::format_price( $record->amount, true, false ) ) ); ?>
					</p>
				<?php else : ?>
					<p style="margin: 0 0 14px; font-size: 12px; color: #71717a; line-height: 1.4;">
						<?php echo esc_html( self::payment_instructions( $record->method ) ); ?>
					</p>
					<?php foreach ( $details as $row ) : ?>
						<?php self::render_email_detail_row( $row ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
				<table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 14px; padding-top: 12px; border-top: 1px dashed #d4d4d8;">
					<tr>
						<td style="text-align: left; vertical-align: middle;">
							<?php if ( ! $is_paid && '' !== $deadline ) : ?>
								<span style="font-size: 12px; font-weight: 600; color: #dc2626;">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: human-readable "pay by" deadline date. */
											__( 'Pay by: %s', 'ifthenpay-payments-for-latepoint' ),
											$deadline
										)
									);
									?>
								</span>
							<?php endif; ?>
						</td>
						<td style="text-align: right; vertical-align: middle; white-space: nowrap;">
							<span style="font-size: 11px; color: #a1a1aa;"><?php echo esc_html__( 'Powered by', 'ifthenpay-payments-for-latepoint' ); ?></span>
							<img src="<?php echo esc_url( IfthenpayPaymentsForLatepoint::images_url() . 'ifthenpay-brand.png' ); ?>" alt="ifthenpay" style="height: 14px; width: auto; vertical-align: middle; margin-left: 4px;" />
						</td>
					</tr>
				</table>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
