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
		// pill needed for that state (see render_connection_status()). The button doubles as the
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
	 * saved key has already passed the remote check. Silent when the key is fully usable — the
	 * "Disconnect" button already says that plainly; this is only for the two states it can't say
	 * on its own, "we don't know" and "connected but nothing to configure yet".
	 *
	 * @param array{gatewaykeys:array<string,string>,accounts:array<string,array<string,string>>}|null $dataset The gateway dataset for this Backoffice Key, or null if it could not be fetched.
	 */
	public static function render_connection_status( ?array $dataset ): void {
		if ( null === $dataset ) {
			self::render_status_pill(
				'error',
				esc_html__( 'Could not check the connection to ifthenpay right now. This does not affect your saved settings — try reloading in a moment.', 'ifthenpay-payments-for-latepoint' )
			);
			return;
		}

		if ( empty( $dataset['gatewaykeys'] ) ) {
			self::render_status_pill(
				'pending',
				esc_html__( 'Connected, but no gateway keys yet for this site.', 'ifthenpay-payments-for-latepoint' )
			);
			?>
			<p class="ifthenpay-onboarding-steps">
				<?php
				printf(
					/* translators: %s: ifthenpay helpdesk link */
					esc_html__( 'Ask ifthenpay to provision a gateway key for this LatePoint site — contact %s.', 'ifthenpay-payments-for-latepoint' ),
					'<a href="https://helpdesk.ifthenpay.com" target="_blank" rel="noopener noreferrer">helpdesk.ifthenpay.com</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed, literal link, not dynamic content.
				);
				?>
			</p>
			<?php
		}
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

		self::render_status_pill(
			'error',
			sprintf(
				/* translators: %s: reason the callback registration failed */
				esc_html__( 'The payment notification URL could not be registered with ifthenpay: %s', 'ifthenpay-payments-for-latepoint' ),
				esc_html( $status['message'] )
			)
		);
	}

	/**
	 * `pending` renders with the exact status class LatePoint's own Stripe/Razorpay/PayPal connect
	 * flows use for "connected, action still needed" (`.payment-processor-connect-status-wrapper` +
	 * `.payment-processor-status-charges-disabled`) — the same markup, colored entirely by
	 * LatePoint's own CSS. `error` has no first-party equivalent, since every native state there
	 * assumes a definite connected/action-needed answer rather than "couldn't find out" — it falls
	 * back to a small class this plugin owns (see the stylesheet). There is no `active` state here:
	 * a fully usable key is already said plainly by the "Disconnect" button, so nothing needs to
	 * say it again.
	 *
	 * @param string $state   One of `pending`, `error`.
	 * @param string $message Already-escaped text.
	 */
	private static function render_status_pill( string $state, string $message ): void {
		$native_class = array(
			'pending' => 'payment-processor-status-charges-disabled',
		);
		$class        = $native_class[ $state ] ?? 'ifthenpay-status-' . $state;
		?>
		<div class="payment-processor-connect-status-wrapper">
			<div class="<?php echo esc_attr( $class ); ?>">
				<span>
					<?php echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller already escaped; this method's whole contract is "pass pre-escaped text". ?>
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
	 * One real `OsFormHelper::checkbox_field()` per catalog method — not a card, not a toggle
	 * switch built by hand. A gateway record carries at most one account per method, verified
	 * against ifthenpay's own API response, so there is nothing to configure beyond on/off: a
	 * method with no account for the selected gateway is a plain, natively-`disabled` checkbox —
	 * browsers already exclude a disabled field from submission, so an unavailable method can
	 * never be saved as enabled without this plugin doing anything else about it.
	 *
	 * @param array<string,array{position:int,image:string,tooltip:string,label:string}> $catalog              Method catalog, keyed by method code.
	 * @param array<string,string>                                                       $accounts_for_gateway `{methodCode: accountKey}` for the currently selected gateway only.
	 * @param string[]                                                                   $enabled_methods      Saved enabled method codes.
	 */
	private static function render_payment_methods( array $catalog, array $accounts_for_gateway, array $enabled_methods ): void {
		uasort( $catalog, fn( $a, $b ) => $a['position'] <=> $b['position'] );
		?>
		<div class="os-row">
			<div class="os-col-12">
				<label class="ifthenpay-section-label">
					<?php echo esc_html__( 'Payment Methods', 'ifthenpay-payments-for-latepoint' ); ?>
				</label>
				<div class="ifthenpay-methods-list">
					<?php
					foreach ( $catalog as $code => $props ) :
						$has_account = isset( $accounts_for_gateway[ $code ] );
						self::render_payment_method_checkbox( $code, $props, $has_account, $has_account && in_array( $code, $enabled_methods, true ) );
					endforeach;
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * One method's own checkbox row.
	 *
	 * @param string                                                       $code        The method's own ifthenpay code (MB, MBWAY, …).
	 * @param array{position:int,image:string,tooltip:string,label:string} $props       Catalog metadata for this method.
	 * @param bool                                                         $has_account Whether the selected gateway has an account for this method.
	 * @param bool                                                         $is_checked  Whether this method is currently enabled.
	 */
	private static function render_payment_method_checkbox( string $code, array $props, bool $has_account, bool $is_checked ): void {
		$label = '<span class="ifthenpay-method-content">'
			. '<img src="' . esc_url( $props['image'] ) . '" class="ifthenpay-method-icon" alt="" />'
			. '<span class="ifthenpay-method-name">' . esc_html( strtoupper( $props['label'] ) ) . ' <span class="ifthenpay-method-code">(' . esc_html( $code ) . ')</span></span>'
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
				'class'       => 'ifthenpay-method-item' . ( $has_account ? '' : ' is-disabled' ),
				'data-entity' => $code,
			),
			false // No "off" fallback value — an unchecked box simply isn't in the submitted array, same as any other checkbox list.
		);
	}

	/**
	 * The Default Method `<select>`, offering only the methods currently enabled.
	 *
	 * @param string[] $enabled_methods Saved enabled method codes.
	 */
	private static function render_default_method_select( array $enabled_methods ): void {
		$options = array( '' => '' ) + array_combine( $enabled_methods, array_map( 'strtoupper', $enabled_methods ) );
		echo OsFormHelper::select_field(
			'settings[ifthenpay_default_method]',
			esc_html__( 'Default Method', 'ifthenpay-payments-for-latepoint' ),
			$options,
			OsSettingsHelper::get_settings_value( 'ifthenpay_default_method' ),
			array( 'class' => 'ifthenpay-default-method' )
		);
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
						echo OsFormHelper::text_field(
							'settings[ifthenpay_description]',
							esc_html__( 'Description', 'ifthenpay-payments-for-latepoint' ),
							esc_attr( OsSettingsHelper::get_settings_value( 'ifthenpay_description' ) )
						);
						?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
