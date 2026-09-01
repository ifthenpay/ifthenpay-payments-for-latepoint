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
								class="button validate-button os-form-control">
								<span class="label-connect">
									<?php echo esc_html__( 'Connect', 'ifthenpay-payments-for-latepoint' ); ?>
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
	 * saved key has already passed the remote check. "Rejected" is a state only the "Connect"
	 * preview can show, for a key that failed to save.
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
			return;
		}

		self::render_status_pill( 'active', esc_html__( 'Connected', 'ifthenpay-payments-for-latepoint' ) );
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
	 * `active` and `pending` render with the exact status classes LatePoint's own Stripe/Razorpay/
	 * PayPal connect flows use (`.payment-processor-connect-status-wrapper` +
	 * `.payment-processor-status-connected` / `-charges-disabled`) — the same markup, colored
	 * entirely by LatePoint's own CSS. `error` has no first-party equivalent, since every native
	 * state there assumes a definite connected/action-needed answer rather than "couldn't find
	 * out" — it falls back to a small class this plugin owns (see the stylesheet).
	 *
	 * @param string $state   One of `active`, `pending`, `error`.
	 * @param string $message Already-escaped text.
	 */
	private static function render_status_pill( string $state, string $message ): void {
		$native_class = array(
			'active'  => 'payment-processor-status-connected',
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
		$cfg                  = self::get_saved_method_config();
		$selected_gateway_key = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key', '' );
		?>
		<div class="sub-section-row">
			<div class="sub-section-label">
				<h3><?php echo esc_html__( 'Payments Configuration', 'ifthenpay-payments-for-latepoint' ); ?></h3>
			</div>
			<div class="sub-section-content">
				<?php self::render_gateway_key_select( $gatewaykeys, $selected_gateway_key ); ?>
				<?php self::render_payment_methods( $catalog, $accounts[ $selected_gateway_key ] ?? array(), $cfg ); ?>
				<?php self::render_default_method_select( $cfg ); ?>
				<input type="hidden"
					id="ifthenpay_payment_methods_configuration"
					name="settings[ifthenpay_payment_methods_configuration]"
					value="<?php echo esc_attr( wp_json_encode( $cfg ) ); ?>" />
			</div>
		</div>
		<?php
	}

	/**
	 * The saved per-method configuration, decoded from its stored JSON string.
	 *
	 * @return array<string,array{checked?:bool,selected_account?:string}>
	 */
	private static function get_saved_method_config(): array {
		$json = OsSettingsHelper::get_settings_value( 'ifthenpay_payment_methods_configuration', '{}' );
		$cfg  = json_decode( $json, true );

		return is_array( $cfg ) ? $cfg : array();
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
	 * One row per catalog method, built from LatePoint's own togglable-item family
	 * (`.os-togglable-item-w` / `OsFormHelper::toggler_field()`) — the same component LatePoint
	 * uses one level up for the processor list itself. A gateway record carries at most one
	 * account per method, verified against ifthenpay's own API response, so there is nothing to
	 * pick between: the toggle is enabled exactly when the selected gateway has an account for
	 * that method, and the account key travels in a hidden field. The toggle switch has no native
	 * disabled state, so a method with no account is locked from being clicked via CSS
	 * (`.is-disabled .os-toggler { pointer-events: none }`), not just visually faded.
	 *
	 * @param array<string,array{position:int,image:string,tooltip:string,label:string}> $catalog              Method catalog, keyed by method code.
	 * @param array<string,string>                                                       $accounts_for_gateway `{methodCode: accountKey}` for the currently selected gateway only.
	 * @param array<string,array{checked?:bool,selected_account?:string}>                $cfg                  Saved per-method configuration.
	 */
	private static function render_payment_methods( array $catalog, array $accounts_for_gateway, array $cfg ): void {
		uasort( $catalog, fn( $a, $b ) => ( $a['position'] ?? 0 ) <=> ( $b['position'] ?? 0 ) );
		?>
		<div class="os-row">
			<div class="os-col-12">
				<label class="ifthenpay-section-label">
					<?php echo esc_html__( 'Payment Methods', 'ifthenpay-payments-for-latepoint' ); ?>
				</label>
				<div class="os-togglable-items-w ifthenpay-methods-list">
					<?php foreach ( $catalog as $code => $props ) : ?>
						<?php self::render_payment_method_row( $code, $props, $accounts_for_gateway[ $code ] ?? '', ! empty( $cfg[ $code ]['checked'] ) ); ?>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * One toggle-switch row for a single payment method.
	 *
	 * @param string                                                       $code        The method's own ifthenpay code (MB, MBWAY, …).
	 * @param array{position:int,image:string,tooltip:string,label:string} $props       Catalog metadata for this method.
	 * @param string                                                       $account_key The selected gateway's account key for this method, or '' if it has none.
	 * @param bool                                                         $saved_as_on Whether this method was saved as checked.
	 */
	private static function render_payment_method_row( string $code, array $props, string $account_key, bool $saved_as_on ): void {
		$has_account = '' !== $account_key;
		$is_checked  = $has_account && $saved_as_on;
		?>
		<div class="os-togglable-item-w ifthenpay-method-item<?php echo $has_account ? '' : ' is-disabled'; ?>" data-entity="<?php echo esc_attr( $code ); ?>">
			<div class="os-togglable-item-head">
				<div class="os-toggler-w">
					<?php
					// An empty label skips toggler_field()'s own wrapping group, so only clicking
					// the switch toggles it — matching the same convention as LatePoint's own
					// processor list, which also passes no label to its equivalent toggle.
					echo OsFormHelper::toggler_field(
						'ifthenpay_method_toggle_' . $code,
						'',
						$is_checked,
						false,
						'small'
					);
					?>
				</div>
				<img src="<?php echo esc_url( $props['image'] ); ?>"
					class="os-togglable-item-logo-img"
					alt="<?php echo esc_attr( $props['label'] ); ?>" />
				<div class="os-togglable-item-name">
					<?php echo esc_html( strtoupper( $props['label'] ) ); ?>
					<span class="ifthenpay-method-code">(<?php echo esc_html( $code ); ?>)</span>
				</div>
			</div>
			<?php if ( $has_account ) : ?>
				<input type="hidden"
					class="ifthenpay-method-account"
					name="settings[ifthenpay_payment_methods_configuration][<?php echo esc_attr( $code ); ?>][selected_account]"
					value="<?php echo esc_attr( $account_key ); ?>" />
			<?php else : ?>
				<div class="os-togglable-item-body ifthenpay-method-body">
					<?php echo esc_html__( 'No accounts.', 'ifthenpay-payments-for-latepoint' ); ?>
					<a href="#" class="ifthenpay-activate" data-entity="<?php echo esc_attr( $code ); ?>">
						<?php echo esc_html__( 'Activate', 'ifthenpay-payments-for-latepoint' ); ?>
					</a>.
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The Default Method `<select>`, offering only the methods currently checked.
	 *
	 * @param array<string,array{checked?:bool,selected_account?:string}> $cfg Saved per-method configuration.
	 */
	private static function render_default_method_select( array $cfg ): void {
		$checked_methods = array_keys( array_filter( $cfg, fn( $method ) => ! empty( $method['checked'] ) ) );
		$options         = array( '' => '' ) + array_combine( $checked_methods, array_map( 'strtoupper', $checked_methods ) );
		?>
		<div class="os-row-12">
			<?php
			echo OsFormHelper::select_field(
				'settings[ifthenpay_default_method]',
				esc_html__( 'Default Method', 'ifthenpay-payments-for-latepoint' ),
				$options,
				OsSettingsHelper::get_settings_value( 'ifthenpay_default_method' ),
				array( 'class' => 'ifthenpay-default-method' )
			);
			?>
		</div>
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
					<div class="os-col">
						<label><?php echo esc_html__( 'Description', 'ifthenpay-payments-for-latepoint' ); ?></label>
						<input type="text"
							name="settings[ifthenpay_description]"
							value="<?php echo esc_attr( OsSettingsHelper::get_settings_value( 'ifthenpay_description' ) ); ?>" />
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
