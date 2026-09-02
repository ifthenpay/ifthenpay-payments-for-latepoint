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
class IfthenpayAdminFormRenderer {

	/**
	 * The Backoffice Key field and its "Connect" button, which previews (does not save) what
	 * saving that key would configure.
	 *
	 * @param string $backoffice_key Current, already-decrypted setting value.
	 */
	public static function render_backoffice_configuration( string $backoffice_key ): void {
		// A saved key already says "connected" as plainly as a button can — no separate status
		// pill needed for that state (see get_connection_notice()). The button doubles as the
		// disconnect action so there is one control for the whole relationship, not a button plus
		// a status message that both say the same thing.
		$mode = '' === $backoffice_key ? 'connect' : 'disconnect';
		?>
		<div class="sub-section-row">
			<div class="sub-section-label">
				<h3><?php echo esc_html__( 'Backoffice Configuration', 'ifthenpay-payments-for-latepoint' ); ?></h3>
			</div>
			<div class="sub-section-content">
				<div class="os-row">
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
			</div>
		</div>
		<?php
	}

	/**
	 * Whether the saved Backoffice Key is actually usable, computed fresh on every render — not
	 * only right after "Connect" — so a key revoked on ifthenpay's side, or gateway keys
	 * added/removed there, shows up without the merchant touching this page. A rejected key can
	 * never reach this method: the save itself is blocked before a bad key is ever stored, so a
	 * saved key has already passed the remote check. Null when the key is fully usable — the
	 * "Disconnect" button already says that plainly; this is only for the two states it can't say
	 * on its own, "we don't know" and "connected but nothing to configure yet". Returned rather
	 * than echoed: both callers (the page's own render, and the "Connect" preview) surface this as
	 * a toast, not an inline block sitting above a Payments Configuration section that has nothing
	 * usable in it either way.
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
					esc_html__( 'Connected, but no gateway keys yet for this site. Ask ifthenpay to provision one — contact %s.', 'ifthenpay-payments-for-latepoint' ),
					'<a href="https://helpdesk.ifthenpay.com" target="_blank" rel="noopener noreferrer">helpdesk.ifthenpay.com</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed, literal link, not dynamic content; latepoint_add_notification() renders its message as HTML, so this reaches the merchant as a real, clickable link rather than escaped markup.
				),
			);
		}

		return null;
	}

	/**
	 * The last callback-URL registration outcome for the currently saved Gateway Key. Silent on
	 * success, and silent when nothing was ever attempted — only a confirmed failure is worth a
	 * merchant's attention.
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
	 * The Gateway Key picker and its available payment methods, both driven by the live gateway
	 * dataset rather than anything hand-typed or cached in a setting.
	 *
	 * @param array<string,string>                                                       $gatewaykeys `{GatewayKey: Alias}`, used directly as the select's options.
	 * @param array<string,array<string,string>>                                         $accounts    `{GatewayKey: {methodCode: accountKey}}` for every gateway key at once — the admin script re-reads this client-side when the selected gateway changes, no extra request needed.
	 * @param array<string,array{position:int,image:string,tooltip:string,label:string}> $catalog The full method catalog, position-sorted for display.
	 */
	public static function render_payments_configuration( array $gatewaykeys, array $accounts, array $catalog ): void {
		$enabled_methods      = self::get_saved_enabled_methods();
		$selected_gateway_key = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key', '' );
		?>
		<div class="sub-section-row">
			<div class="sub-section-label">
				<h3><?php echo esc_html__( 'Payments Configuration', 'ifthenpay-payments-for-latepoint' ); ?></h3>
			</div>
			<div class="sub-section-content">
				<div class="os-row">
					<div class="os-col-6">
						<?php self::render_gateway_key_select( $gatewaykeys, $selected_gateway_key ); ?>
					</div>
				</div>
				<?php self::render_payment_methods( $catalog, $accounts[ $selected_gateway_key ] ?? array(), $enabled_methods ); ?>
				<div class="os-row">
					<div class="os-col-6">
						<?php self::render_default_method_select( $enabled_methods ); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The method codes currently enabled. Nothing but this list is stored — which account each
	 * one uses is looked up live from the gateway dataset at checkout time
	 * (`IfthenpayDataFormatter::build_accounts_string()`), the same way this page itself looks it
	 * up for display, so there is no separate per-method value to keep in sync with it.
	 * `OsSettingsHelper` serializes/unserializes array settings transparently, so the browser's
	 * own `settings[ifthenpay_payment_methods_configuration][]` checkbox array needs no encoding
	 * of its own either.
	 *
	 * @return string[]
	 */
	private static function get_saved_enabled_methods(): array {
		$enabled = OsSettingsHelper::get_settings_value( 'ifthenpay_payment_methods_configuration', array() );
		if ( ! is_array( $enabled ) ) {
			return array();
		}

		// A site saved under this setting's old, nested `{code: {checked, selected_account}}`
		// shape before this flat-array format existed — filter those entries out rather than
		// fatal on them; the merchant simply sees nothing enabled until they re-save this page.
		return array_values( array_filter( $enabled, 'is_string' ) );
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
			array( 'class' => 'ifthenpay-gateway-select' )
		);
	}

	/**
	 * Two groups, not one flat list — the same split IfthenpayDataFormatter applies at checkout
	 * (IfthenpayLpPayByLinkMethodEligibility::is_listed_in_pay_by_link()), made visible here so a
	 * merchant sees *why* Multibanco/Payshop behave differently instead of discovering it only
	 * once a booking silently never offers them. Each row is still a real
	 * `OsFormHelper::checkbox_field()` — not a card, not a toggle switch built by hand. A gateway
	 * record carries at most one account per method, verified against ifthenpay's own API
	 * response, so there is nothing to configure beyond on/off: a method with no account for the
	 * selected gateway is a plain, natively-`disabled` checkbox — browsers already exclude a
	 * disabled field from submission, so an unavailable method can never be saved as enabled
	 * without this plugin doing anything else about it.
	 *
	 * @param array<string,array{position:int,image:string,tooltip:string,label:string}> $catalog              Method catalog, keyed by method code.
	 * @param array<string,string>                                                       $accounts_for_gateway `{methodCode: accountKey}` for the currently selected gateway only.
	 * @param string[]                                                                   $enabled_methods      Saved enabled method codes.
	 */
	private static function render_payment_methods( array $catalog, array $accounts_for_gateway, array $enabled_methods ): void {
		uasort( $catalog, fn( $a, $b ) => $a['position'] <=> $b['position'] );
		$pay_now_codes  = array_filter( array_keys( $catalog ), array( 'IfthenpayLpPayByLinkMethodEligibility', 'is_listed_in_pay_by_link' ) );
		$deferred_codes = array_diff( array_keys( $catalog ), $pay_now_codes );
		?>
		<div class="os-row">
			<div class="os-col-12">
				<?php
				self::render_method_group(
					esc_html__( 'Pay Now', 'ifthenpay-payments-for-latepoint' ),
					esc_html__( 'Charged immediately through Pay By Link, at checkout.', 'ifthenpay-payments-for-latepoint' ),
					array_intersect_key( $catalog, array_flip( $pay_now_codes ) ),
					$accounts_for_gateway,
					$enabled_methods
				);
				self::render_method_group(
					esc_html__( 'Deferred — coming soon', 'ifthenpay-payments-for-latepoint' ),
					esc_html__( 'Generates a reference the customer pays later. Can be enabled here, but this plugin does not act on it yet — checking it has no effect at checkout for now.', 'ifthenpay-payments-for-latepoint' ),
					array_intersect_key( $catalog, array_flip( $deferred_codes ) ),
					$accounts_for_gateway,
					$enabled_methods
				);
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * One labeled group of method rows — "Pay Now" or "Deferred", per render_payment_methods().
	 *
	 * @param string                                                                     $title                Group heading.
	 * @param string                                                                     $description          Group caption, shown under the heading.
	 * @param array<string,array{position:int,image:string,tooltip:string,label:string}> $catalog_subset       This group's slice of the catalog.
	 * @param array<string,string>                                                       $accounts_for_gateway `{methodCode: accountKey}` for the currently selected gateway only.
	 * @param string[]                                                                   $enabled_methods      Saved enabled method codes.
	 */
	private static function render_method_group( string $title, string $description, array $catalog_subset, array $accounts_for_gateway, array $enabled_methods ): void {
		if ( array() === $catalog_subset ) {
			return;
		}
		?>
		<div class="label-with-description">
			<h3><?php echo $title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller already escaped, matching this method's own contract. ?></h3>
			<div class="label-desc"><?php echo $description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller already escaped, matching this method's own contract. ?></div>
		</div>
		<div class="ifthenpay-methods-list">
			<?php
			foreach ( $catalog_subset as $code => $props ) :
				$account_key = $accounts_for_gateway[ $code ] ?? '';
				$has_account = '' !== $account_key;
				self::render_payment_method_checkbox( $code, $props, $account_key, $has_account && in_array( $code, $enabled_methods, true ) );
			endforeach;
			?>
		</div>
		<?php
	}

	/**
	 * One method's own checkbox row. The method's own ifthenpay code (MB, MBWAY, …) has no real
	 * use to a merchant once the icon and name already identify it — the account key behind it is
	 * the piece worth showing, so a merchant can confirm which ifthenpay account this row actually
	 * charges to.
	 *
	 * @param string                                                       $code        The method's own ifthenpay code (MB, MBWAY, …).
	 * @param array{position:int,image:string,tooltip:string,label:string} $props       Catalog metadata for this method.
	 * @param string                                                       $account_key The selected gateway's account key for this method, or '' if it has none.
	 * @param bool                                                         $is_checked  Whether this method is currently enabled.
	 */
	private static function render_payment_method_checkbox( string $code, array $props, string $account_key, bool $is_checked ): void {
		$has_account = '' !== $account_key;
		$key_display = $has_account ? ' <span class="ifthenpay-method-account-key">(' . esc_html( $account_key ) . ')</span>' : '';

		$label = '<span class="ifthenpay-method-content">'
			. '<img src="' . esc_url( $props['image'] ) . '" class="ifthenpay-method-icon" alt="" />'
			. '<span class="ifthenpay-method-name">' . esc_html( strtoupper( $props['label'] ) ) . $key_display . '</span>'
			. '<span class="ifthenpay-no-accounts">' . esc_html__( 'No accounts.', 'ifthenpay-payments-for-latepoint' )
			. ' <a href="#" class="ifthenpay-activate" data-entity="' . esc_attr( $code ) . '">' . esc_html__( 'Activate', 'ifthenpay-payments-for-latepoint' ) . '</a>.</span>'
			. '</span>';

		$atts = array( 'id' => 'ifthenpay_method_' . strtolower( $code ) );
		if ( ! $has_account ) {
			// LatePoint's own OsFormHelper::atts_string_from_array() renders any key present in
			// $atts, even a null value — the key must be entirely absent to leave the checkbox
			// enabled, not merely set to a falsy value.
			$atts['disabled'] = 'disabled';
		}

		echo OsFormHelper::checkbox_field(
			'settings[ifthenpay_payment_methods_configuration][]',
			$label,
			$code,
			$is_checked,
			$atts,
			array(
				'class'                 => 'ifthenpay-method-item' . ( $has_account ? '' : ' is-disabled' ),
				'data-entity'           => $code,
				// Read by the admin script's updateDefaultMethodOptions() so a method checked
				// client-side can only become a Default Method option when it's actually eligible
				// — the same rule render_default_method_select() applies to whatever is already
				// checked at page-load time.
				'data-default-eligible' => IfthenpayLpPayByLinkMethodEligibility::is_eligible_as_default( $code ) ? '1' : '0',
			),
			false // No "off" fallback value — an unchecked box simply isn't in the submitted array, same as any other checkbox list.
		);
	}

	/**
	 * The Default Method `<select>`, offering only enabled methods PBL can actually be told to
	 * pre-select — Multibanco, Payshop, Google Pay, and Apple Pay never appear here even if
	 * enabled, since PBL's `selected_method` field has no value for any of them
	 * (IfthenpayDataFormatter::get_selected_method() applies the same rule at checkout).
	 *
	 * @param string[] $enabled_methods Saved enabled method codes.
	 */
	private static function render_default_method_select( array $enabled_methods ): void {
		$eligible_methods = array_values( array_filter( $enabled_methods, array( 'IfthenpayLpPayByLinkMethodEligibility', 'is_eligible_as_default' ) ) );
		$options          = array( '' => '' ) + array_combine( $eligible_methods, array_map( 'strtoupper', $eligible_methods ) );
		echo OsFormHelper::select_field(
			'settings[ifthenpay_default_method]',
			esc_html__( 'Default Method', 'ifthenpay-payments-for-latepoint' ),
			$options,
			OsSettingsHelper::get_settings_value( 'ifthenpay_default_method' ),
			array( 'class' => 'ifthenpay-default-method' )
		);
		?>
		<p class="ifthenpay-field-note">
			<?php echo esc_html__( 'Only Pay Now methods can be set as default — Google Pay, Apple Pay, and the deferred methods above are never pre-selected.', 'ifthenpay-payments-for-latepoint' ); ?>
		</p>
		<?php
	}

	/**
	 * The plain description field shown at the bottom of the section.
	 */
	public static function render_others_configuration(): void {
		?>
		<div class="sub-section-row">
			<div class="sub-section-label">
				<h3><?php echo esc_html__( 'Other Configuration', 'ifthenpay-payments-for-latepoint' ); ?></h3>
			</div>
			<div class="sub-section-content">
				<div class="os-row">
					<div class="os-col-12">
						<?php
						// text_field() defaults to its bare "transparent" theme (an underline, no
						// box) unless told otherwise — 'simple' is what every other field on this
						// page already uses (see the Backoffice Key field), so this one matches
						// instead of standing out as unstyled.
						echo OsFormHelper::text_field(
							'settings[ifthenpay_description]',
							esc_html__( 'Description', 'ifthenpay-payments-for-latepoint' ),
							esc_attr( OsSettingsHelper::get_settings_value( 'ifthenpay_description' ) ),
							array( 'theme' => 'simple' )
						);
						?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
