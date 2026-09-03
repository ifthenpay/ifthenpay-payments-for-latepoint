<?php
/**
 * Renders the ifthenpay section of LatePoint's Payments settings tab.
 *
 * Plugin Reviewer Note: the OsFormHelper::*_field() and toggler_field() methods are part of the
 * LatePoint framework and escape their own output internally (esc_html()/esc_attr()). A review
 * scanner that can't see inside those methods may flag calls to them as unescaped output; every
 * call here is preceded by `echo` as required, and the escaping happens inside the helper. No
 * wp_kses_post() is used because it is disallowed in this context.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders every field on the ifthenpay payment processor's settings section.
 */
class IfthenpayLpAdminFormRenderer {

	/**
	 * The Backoffice Key field, its Connect/Disconnect button, and the Gateway Key row — a Gateway
	 * Key only makes sense once a Backoffice Key is connected, so it lives here rather than with
	 * the Pay Now methods that merely depend on it.
	 *
	 * @param string               $backoffice_key       Current, already-decrypted setting value.
	 * @param array<string,string> $gatewaykeys          `{GatewayKey: Alias}` for this Backoffice Key, or `array()` if there are none yet.
	 * @param string               $selected_gateway_key Currently saved (or defaulted) gateway key, or '' if `$gatewaykeys` is empty.
	 */
	public static function render_backoffice_configuration( string $backoffice_key, array $gatewaykeys = array(), string $selected_gateway_key = '' ): void {
		// Doubles as the disconnect action, so there is one control for the whole relationship
		// rather than a button plus a separate status message saying the same thing.
		$mode = '' === $backoffice_key ? 'connect' : 'disconnect';
		?>
		<div class="sub-section-row">
			<div class="sub-section-label">
				<h3><?php echo esc_html__( 'Backoffice Configuration', 'ifthenpay-payments-for-latepoint' ); ?></h3>
			</div>
			<div class="sub-section-content">
				<div class="os-row os-mb-2">
					<div class="os-col-6">
						<?php
						echo OsFormHelper::password_field(
							'settings[ifthenpay_backoffice_key]',
							esc_html__( 'Backoffice Key', 'ifthenpay-payments-for-latepoint' ),
							esc_attr( $backoffice_key ),
							array(
								'theme' => 'simple',
								'class' => 'custom-backoffice-key',
							)
						);
						?>
					</div>
					<div class="os-col-6">
						<div class="os-form-group">
							<label for="validate_button">&nbsp;</label>
							<button
								type="button"
								id="validate_button"
								class="button validate-button os-form-control mode-<?php echo esc_attr( $mode ); ?>"
								data-mode="<?php echo esc_attr( $mode ); ?>">
								<span class="label-connect">
									<?php echo esc_html__( 'Connect', 'ifthenpay-payments-for-latepoint' ); ?>
								</span>
								<span class="label-disconnect">
									<?php echo esc_html__( 'Disconnect', 'ifthenpay-payments-for-latepoint' ); ?>
								</span>
								<span class="label-connecting" style="display: none;">
									<?php echo esc_html__( 'Connecting...', 'ifthenpay-payments-for-latepoint' ); ?>
								</span>
							</button>
						</div>
					</div>
				</div>
				<div id="ifthenpay_gateway_key_row">
					<?php
					echo self::render_gateway_key_row( $gatewaykeys, $selected_gateway_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- own method, already escapes internally, matches this file's own contract (see Plugin Reviewer Note above).
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The Gateway Key `<select>`'s own row, wrapped in a container — nothing when there are no
	 * gateway keys yet. Returned rather than echoed: the "Connect" preview
	 * (`OsPaymentsIfthenpaySettingsController::validate_key()`) sends this back as its own JSON
	 * field so the admin script can refresh just this row.
	 *
	 * @param array<string,string> $gatewaykeys          `{GatewayKey: Alias}`, or `array()` if there are none yet.
	 * @param string               $selected_gateway_key Currently saved (or defaulted) gateway key, or '' if `$gatewaykeys` is empty.
	 * @return string
	 */
	public static function render_gateway_key_row( array $gatewaykeys, string $selected_gateway_key ): string {
		if ( array() === $gatewaykeys ) {
			return '';
		}

		ob_start();
		?>
		<div class="os-row os-mb-2">
			<div class="os-col-12">
				<?php self::render_gateway_key_select( $gatewaykeys, $selected_gateway_key ); ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The Gateway Key actually in effect: the saved setting when it still names one of the current
	 * gateway keys, the first one otherwise. A `<select>` with no `selected` option shows its first
	 * `<option>` regardless of what the rest of the page computes — without this fallback, that
	 * visual default and the accounts looked up elsewhere would silently disagree.
	 *
	 * @param array<string,string> $gatewaykeys `{GatewayKey: Alias}`, or `array()` if there are none yet.
	 * @return string The gateway key to treat as selected, or '' if `$gatewaykeys` is empty.
	 */
	public static function resolve_selected_gateway_key( array $gatewaykeys ): string {
		$saved = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key', '' );
		if ( isset( $gatewaykeys[ $saved ] ) ) {
			return $saved;
		}

		return array() !== $gatewaykeys ? array_key_first( $gatewaykeys ) : '';
	}

	/**
	 * Whether the saved Backoffice Key is usable, computed fresh on every render so a key revoked
	 * on ifthenpay's side shows up without the merchant touching this page. A rejected key never
	 * reaches this method — save-time validation already blocks that. `null` means fully usable
	 * (the "Disconnect" button already says that); the two non-null states are what it can't say on
	 * its own.
	 *
	 * @param array{gatewaykeys:array<string,string>,accounts:array<string,array<string,string>>}|null $dataset The gateway dataset for this Backoffice Key, or null if it could not be fetched.
	 * @return array{type:string,message:string}|null
	 */
	public static function get_connection_notice( ?array $dataset ): ?array {
		if ( null === $dataset ) {
			return array(
				'type'    => 'error',
				'message' => esc_html__( 'Could not check the connection to ifthenpay right now. This does not affect your saved settings — try reloading in a moment.', 'ifthenpay-payments-for-latepoint' ),
			);
		}

		if ( empty( $dataset['gatewaykeys'] ) ) {
			return array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: %s: ifthenpay helpdesk link */
					esc_html__( 'Connected, but no gateway keys yet for LatePoint context. Ask ifthenpay to provision one — contact %s.', 'ifthenpay-payments-for-latepoint' ),
					'<a href="https://helpdesk.ifthenpay.com" target="_blank" rel="noopener noreferrer">helpdesk.ifthenpay.com</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed, literal link, not dynamic content; latepoint_add_notification() renders its message as HTML, so this reaches the merchant as a real, clickable link rather than escaped markup.
				),
			);
		}

		return null;
	}

	/**
	 * The last callback-URL registration outcome for the currently saved Gateway Key. Silent on
	 * success and on "never attempted" — only a confirmed failure is worth a merchant's attention.
	 *
	 * @param array{success:bool,message:string,registered_at:int}|null $status The stored registration outcome, or null if none was ever recorded.
	 */
	public static function render_callback_status( ?array $status ): void {
		if ( null === $status || $status['success'] ) {
			return;
		}
		?>
		<div class="payment-processor-connect-status-wrapper">
			<div class="ifthenpay-status-error">
				<span>
					<?php
					printf(
						/* translators: %s: reason the callback registration failed */
						esc_html__( 'The payment notification URL could not be registered with ifthenpay: %s', 'ifthenpay-payments-for-latepoint' ),
						esc_html( $status['message'] )
					);
					?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * The selected gateway's available payment methods, split into "Pay Now Configuration" and
	 * "Pay Later Configuration" — the same split IfthenpayLpPayByLinkMethodEligibility::is_listed_in_pay_by_link()
	 * applies at checkout, made visible here so a merchant sees why Multibanco/Payshop behave
	 * differently instead of discovering it only once a booking never offers them.
	 *
	 * @param string                                                                     $selected_gateway_key The Gateway Key to show methods for — see resolve_selected_gateway_key().
	 * @param array<string,array<string,string>>                                         $accounts             `{GatewayKey: {methodCode: accountKey}}` for every gateway key at once — the admin script re-reads this client-side when the selected gateway changes.
	 * @param array<string,array{position:int,image:string,tooltip:string,label:string}> $catalog              The full method catalog, position-sorted for display.
	 */
	public static function render_payments_configuration( string $selected_gateway_key, array $accounts, array $catalog ): void {
		$enabled_methods      = self::get_saved_enabled_methods();
		$accounts_for_gateway = $accounts[ $selected_gateway_key ] ?? array();

		uasort( $catalog, fn( $a, $b ) => $a['position'] <=> $b['position'] );
		$pay_now_catalog  = array_filter( $catalog, array( 'IfthenpayLpPayByLinkMethodEligibility', 'is_listed_in_pay_by_link' ), ARRAY_FILTER_USE_KEY );
		$deferred_catalog = array_diff_key( $catalog, $pay_now_catalog );

		self::render_pay_now_configuration( $pay_now_catalog, $accounts_for_gateway, $enabled_methods );
		self::render_pay_later_configuration( $deferred_catalog, $accounts_for_gateway, $enabled_methods );
	}

	/**
	 * The Pay Now methods, which one PBL pre-selects, and the order description PBL sends — one
	 * section, since all three are meaningless without each other.
	 *
	 * @param array<string,array{position:int,image:string,tooltip:string,label:string}> $pay_now_catalog      Pay Now-eligible slice of the catalog.
	 * @param array<string,string>                                                       $accounts_for_gateway `{methodCode: accountKey}` for the currently selected gateway only.
	 * @param string[]                                                                   $enabled_methods      Saved enabled method codes.
	 */
	private static function render_pay_now_configuration( array $pay_now_catalog, array $accounts_for_gateway, array $enabled_methods ): void {
		?>
		<div class="sub-section-row">
			<div class="sub-section-label">
				<h3><?php echo esc_html__( 'Pay Now Configuration', 'ifthenpay-payments-for-latepoint' ); ?></h3>
			</div>
			<div class="sub-section-content">
				<?php
				// Unchecking every method leaves this field entirely absent from the submitted
				// form — no checkbox means no `[]` entry — and LatePoint's own
				// SettingsController::update() only saves setting names actually present in the
				// request, so the previous value would silently survive. This always-present
				// entry keeps the field's key in the request even then;
				// get_saved_enabled_methods() filters its empty value back out on read. Kept
				// inside a `.sub-section-row` rather than as a bare sibling of one: LatePoint's
				// own `.os-togglable-item-body:has(> :not(.sub-section-row))` rule pads every
				// side of the whole card the moment any direct child isn't a `.sub-section-row`,
				// insetting every section divider from the card's edges.
				echo '<input type="hidden" name="settings[ifthenpay_payment_methods_configuration][]" value="" />';
				?>
				<div class="label-with-description">
					<h3><?php echo esc_html__( 'Payment Methods', 'ifthenpay-payments-for-latepoint' ); ?></h3>
					<div class="label-desc"><?php echo esc_html__( 'Enable at least one to accept payments.', 'ifthenpay-payments-for-latepoint' ); ?></div>
				</div>
				<?php
				self::render_methods_list( $pay_now_catalog, $accounts_for_gateway, $enabled_methods );
				self::render_default_method_select( $pay_now_catalog, $accounts_for_gateway, $enabled_methods );
				self::render_description_field();
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Multibanco (functional) and Payshop (not yet — spec 002) — their own section, since nothing
	 * in Pay Now Configuration (Gateway Key, Default Method, Description) applies to them, and
	 * Multibanco has its own setting (reference validity) nothing else needs.
	 *
	 * @param array<string,array{position:int,image:string,tooltip:string,label:string}> $deferred_catalog     Deferred slice of the catalog.
	 * @param array<string,string>                                                       $accounts_for_gateway `{methodCode: accountKey}` for the currently selected gateway only.
	 * @param string[]                                                                   $enabled_methods      Saved enabled method codes.
	 */
	private static function render_pay_later_configuration( array $deferred_catalog, array $accounts_for_gateway, array $enabled_methods ): void {
		if ( array() === $deferred_catalog ) {
			return;
		}
		?>
		<div class="sub-section-row">
			<div class="sub-section-label">
				<h3><?php echo esc_html__( 'Pay Later Configuration', 'ifthenpay-payments-for-latepoint' ); ?></h3>
			</div>
			<div class="sub-section-content">
				<div class="label-with-description">
					<h3><?php echo esc_html__( 'Payment Methods', 'ifthenpay-payments-for-latepoint' ); ?></h3>
					<div class="label-desc"><?php echo esc_html__( 'Multibanco lets customers pay by reference instead of on the spot. Other methods here are not yet functional.', 'ifthenpay-payments-for-latepoint' ); ?></div>
				</div>
				<?php
				self::render_methods_list( $deferred_catalog, $accounts_for_gateway, $enabled_methods );
				self::render_multibanco_validity_field();
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * How many days a Multibanco reference stays payable — always sent to the reference API
	 * (D-2: the API's own default is no expiry at all, which would hold a booking slot forever).
	 * Save-time range validation is IfthenpayLpMultibancoValidityValidation, wired up in the main
	 * plugin file; left blank, IfthenpayLpPaymentProcessor::DEFAULT_MULTIBANCO_VALIDITY_DAYS
	 * applies at payment time.
	 */
	private static function render_multibanco_validity_field(): void {
		?>
		<div class="os-row">
			<div class="os-col-12">
				<?php
				echo OsFormHelper::number_field(
					'settings[ifthenpay_multibanco_validity_days]',
					esc_html__( 'Reference Validity (days)', 'ifthenpay-payments-for-latepoint' ),
					esc_attr( OsSettingsHelper::get_settings_value( 'ifthenpay_multibanco_validity_days' ) ),
					IfthenpayLpMultibancoValidityValidation::MIN_DAYS,
					IfthenpayLpMultibancoValidityValidation::MAX_DAYS,
					array(
						'theme'       => 'simple',
						'placeholder' => (string) IfthenpayLpPaymentProcessor::DEFAULT_MULTIBANCO_VALIDITY_DAYS,
					)
				);
				?>
				<p class="ifthenpay-field-note">
					<?php
					printf(
						/* translators: %d: default number of days, when the field is left blank */
						esc_html__( 'How many days a customer has to pay before the reference expires and the booking is released. Leave blank to use the default of %d days.', 'ifthenpay-payments-for-latepoint' ),
						IfthenpayLpPaymentProcessor::DEFAULT_MULTIBANCO_VALIDITY_DAYS
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * The method codes currently enabled. Which account each one uses is looked up live from the
	 * gateway dataset at checkout time (IfthenpayLpDataFormatter::build_accounts_string()) instead of
	 * being stored here too. Public: checkout-time gating (see the main plugin file's
	 * is_multibanco_usable()) reads the same saved list this form renders checkboxes from — one
	 * setting covers both "Pay Now" and "Pay Later Configuration", the split is display-only.
	 *
	 * @return string[]
	 */
	public static function get_saved_enabled_methods(): array {
		$enabled = OsSettingsHelper::get_settings_value( 'ifthenpay_payment_methods_configuration', array() );
		if ( ! is_array( $enabled ) ) {
			return array();
		}

		// Drops the always-present hidden field's own empty value (see render_payments_configuration())
		// and any entry from this setting's old, nested `{code: {checked, selected_account}}` shape —
		// a real method code is never empty or an array.
		return array_values( array_filter( $enabled, static fn( $value ) => is_string( $value ) && '' !== $value ) );
	}

	/**
	 * The Gateway Key `<select>`.
	 *
	 * @param array<string,string> $gatewaykeys          `{GatewayKey: Alias}`.
	 * @param string               $selected_gateway_key Currently saved gateway key, or ''.
	 */
	private static function render_gateway_key_select( array $gatewaykeys, string $selected_gateway_key ): void {
		echo OsFormHelper::select_field(
			'settings[ifthenpay_gateway_key]',
			esc_html__( 'Gateway Key', 'ifthenpay-payments-for-latepoint' ),
			$gatewaykeys,
			$selected_gateway_key,
			array(
				'class' => 'ifthenpay-gateway-select',
				'theme' => 'simple',
			)
		);
	}

	/**
	 * The method-checkboxes wrapper, shared by Pay Now and Pay Later Configuration.
	 *
	 * @param array<string,array{position:int,image:string,tooltip:string,label:string}> $catalog_subset       Pay Now or Deferred slice of the catalog.
	 * @param array<string,string>                                                       $accounts_for_gateway `{methodCode: accountKey}` for the currently selected gateway only.
	 * @param string[]                                                                   $enabled_methods      Saved enabled method codes.
	 */
	private static function render_methods_list( array $catalog_subset, array $accounts_for_gateway, array $enabled_methods ): void {
		?>
		<div class="os-row os-mb-2">
			<div class="os-col-12">
				<div class="ifthenpay-methods-list">
					<?php self::render_method_checkboxes( $catalog_subset, $accounts_for_gateway, $enabled_methods ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * A gateway record carries at most one account per method, verified against ifthenpay's own
	 * API — so a method with no account for the selected gateway is a plain, natively-`disabled`
	 * checkbox.
	 *
	 * @param array<string,array{position:int,image:string,tooltip:string,label:string}> $catalog_subset       Pay Now or Deferred slice of the catalog.
	 * @param array<string,string>                                                       $accounts_for_gateway `{methodCode: accountKey}` for the currently selected gateway only.
	 * @param string[]                                                                   $enabled_methods      Saved enabled method codes.
	 */
	private static function render_method_checkboxes( array $catalog_subset, array $accounts_for_gateway, array $enabled_methods ): void {
		foreach ( $catalog_subset as $code => $props ) {
			$account_key = $accounts_for_gateway[ $code ] ?? '';
			$has_account = '' !== $account_key;
			self::render_payment_method_checkbox( $code, $props, $account_key, $has_account && in_array( $code, $enabled_methods, true ) );
		}
	}

	/**
	 * One method's own checkbox row.
	 *
	 * @param string                                                       $code        The method's own ifthenpay code (MB, MBWAY, …).
	 * @param array{position:int,image:string,tooltip:string,label:string} $props       Catalog metadata for this method.
	 * @param string                                                       $account_key The selected gateway's account key for this method, or '' if it has none.
	 * @param bool                                                         $is_checked  Whether this method is currently enabled.
	 */
	private static function render_payment_method_checkbox( string $code, array $props, string $account_key, bool $is_checked ): void {
		$has_account = '' !== $account_key;
		// A sibling of `.ifthenpay-method-name`, not nested inside it, so the admin script can add,
		// update, or remove it on a gateway change without touching the name.
		$key_display = $has_account ? '<span class="ifthenpay-method-account-key">' . esc_html( $account_key ) . '</span>' : '';

		$label = '<span class="ifthenpay-method-content">'
			. '<img src="' . esc_url( $props['image'] ) . '" class="ifthenpay-method-icon" alt="" />'
			. '<span class="ifthenpay-method-name">' . esc_html( strtoupper( $props['label'] ) ) . '</span>'
			. $key_display
			. '<span class="ifthenpay-no-accounts">' . esc_html__( 'No accounts.', 'ifthenpay-payments-for-latepoint' )
			. ' <a href="#" class="ifthenpay-activate" data-entity="' . esc_attr( $code ) . '">' . esc_html__( 'Activate', 'ifthenpay-payments-for-latepoint' ) . '</a>.</span>'
			. '</span>';

		$atts = array( 'id' => 'ifthenpay_method_' . strtolower( $code ) );
		if ( ! $has_account ) {
			// OsFormHelper::atts_string_from_array() renders any key present in $atts, even a null
			// value — the key must be entirely absent to leave the checkbox enabled.
			$atts['disabled'] = 'disabled';
		}

		echo OsFormHelper::checkbox_field(
			'settings[ifthenpay_payment_methods_configuration][]',
			$label,
			$code,
			$is_checked,
			$atts,
			array(
				'class'       => 'ifthenpay-method-item' . ( $has_account ? '' : ' is-disabled' ),
				'data-entity' => $code,
			),
			false // No "off" fallback value — an unchecked box simply isn't in the submitted array.
		);
	}

	/**
	 * The Default Method `<select>` always lists every method PBL can be told to pre-select (MBWAY,
	 * credit card, Pix — never Multibanco, Payshop, Google Pay, or Apple Pay, per
	 * IfthenpayLpPayByLinkMethodEligibility::is_eligible_as_default()). An option is only `disabled`
	 * while its own checkbox above isn't checked, rather than disappearing entirely, so a merchant
	 * sees the full set of possible defaults up front.
	 *
	 * @param array<string,array{position:int,image:string,tooltip:string,label:string}> $catalog              Pay Now catalog slice, already position-sorted by the only caller (render_pay_now_configuration()).
	 * @param array<string,string>                                                       $accounts_for_gateway `{methodCode: accountKey}` for the currently selected gateway only.
	 * @param string[]                                                                   $enabled_methods      Saved enabled method codes.
	 */
	private static function render_default_method_select( array $catalog, array $accounts_for_gateway, array $enabled_methods ): void {
		$saved_default = OsSettingsHelper::get_settings_value( 'ifthenpay_default_method' );

		$options_html = '<option value="">' . esc_html__( 'Select Method', 'ifthenpay-payments-for-latepoint' ) . '</option>';
		foreach ( $catalog as $code => $props ) {
			if ( ! IfthenpayLpPayByLinkMethodEligibility::is_eligible_as_default( $code ) ) {
				continue;
			}

			$account_key = $accounts_for_gateway[ $code ] ?? '';
			$is_active   = '' !== $account_key && in_array( $code, $enabled_methods, true );

			$options_html .= '<option value="' . esc_attr( $code ) . '"'
				. ( ! $is_active ? ' disabled' : '' )
				. ( $is_active && $code === $saved_default ? ' selected' : '' )
				. '>' . esc_html( strtoupper( $props['label'] ) ) . '</option>';
		}

		?>
		<div class="os-row os-mb-2">
			<div class="os-col-12">
				<?php
				echo OsFormHelper::select_field(
					'settings[ifthenpay_default_method]',
					esc_html__( 'Default Method', 'ifthenpay-payments-for-latepoint' ),
					$options_html,
					'',
					array(
						'class' => 'ifthenpay-default-method',
						'theme' => 'simple', // Every field on this page uses 'simple'; select_field() defaults to a bare, boxless theme otherwise.
					)
				);
				?>
				<p class="ifthenpay-field-note">
					<?php echo esc_html__( 'Google Pay and Apple Pay can’t be set as default.', 'ifthenpay-payments-for-latepoint' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * The order description text sent to PBL (IfthenpayLpDataFormatter::build_pay_by_link_payload()'s
	 * `description` field) — a Pay Now concern, not its own section.
	 */
	private static function render_description_field(): void {
		?>
		<div class="os-row">
			<div class="os-col-12">
				<?php
				echo OsFormHelper::text_field(
					'settings[ifthenpay_description]',
					esc_html__( 'Description', 'ifthenpay-payments-for-latepoint' ),
					esc_attr( OsSettingsHelper::get_settings_value( 'ifthenpay_description' ) ),
					array( 'theme' => 'simple' )
				);
				?>
			</div>
		</div>
		<?php
	}
}
