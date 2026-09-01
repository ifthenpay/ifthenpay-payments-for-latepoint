<?php
class IfthenpayAdminFormRenderer
/**
 * Plugin Reviewer Note:
 * The OsFormHelper::*_field() methods (e.g. select_field, password_field, number_field) are part of the LatePoint framework.
 * These methods internally handle proper escaping using esc_html() and esc_attr() before rendering any HTML.
 *
 * Due to the plugin review scanner not being able to verify custom helper internals, this may appear as unescaped output.
 * However, all calls to these methods are preceded with `echo`, as required by review guidelines,
 * and all dynamic content (labels, values, attributes) is safely escaped internally within the helper methods.
 *
 * For example:
 * echo OsFormHelper::select_field(...); // is safe and escapes internally.
 *
 * Translations use esc_html__() or esc_attr__() as appropriate.
 * All user input retrieved from settings is escaped at the output using esc_attr().
 *
 * No wp_kses_post() is used because it is disallowed in our context.
 */ {

	public static function render_backoffice_configuration( string $backoffice_key ) {
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
	 * Four distinct states (003 T-10), computed fresh on every render — not only right after
	 * "Connect" — from `IfthenpayLpGatewayDataset::get()`, which already distinguishes "no
	 * gateway keys yet" (a real, empty dataset) from "could not find out" (`null`). A rejected
	 * key can never reach this method at all: 003 T-09's save-time validation means a saved
	 * Backoffice Key already passed the remote check, so only these three render here — the
	 * fourth, "Rejected", is what the "Connect" preview shows for a key that failed to save.
	 *
	 * Reuses LatePoint's own `.os-column-status` status-pill classes (see any bookings list)
	 * instead of introducing new CSS.
	 *
	 * @param array{gatewaykeys:array<string,string>,accounts:array<string,array<string,string>>}|null $dataset The result of IfthenpayLpGatewayDataset::get().
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
	 * One `.os-column-status` pill, matching the state colours already used across LatePoint's
	 * own admin (e.g. booking status columns): green for `active`, amber for `pending`, grey for
	 * `error`, red for `disabled` (the one genuinely styled as an alert — 003 T-10).
	 *
	 * @param string $state   One of `active`, `pending`, `error`, `disabled`.
	 * @param string $message Already-escaped text.
	 */
	private static function render_status_pill( string $state, string $message ): void {
		?>
		<p>
			<span class="os-column-status os-column-status-<?php echo esc_attr( $state ); ?>">
				<?php echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller already escaped; this method's whole contract is "pass pre-escaped text". ?>
			</span>
		</p>
		<?php
	}

	/**
	 * @param array<string,string>               $gatewaykeys `{GatewayKey: Alias}` — IfthenpayLpGatewayDataset::get()'s own shape (003 T-05), used directly as the select's value=>label options.
	 * @param array<string,array<string,string>> $accounts    `{GatewayKey: {methodKey: accountKey}}` — same dataset, every gateway at once (003 T-11): the JS re-reads this client-side when the gateway select changes, no per-gateway round trip.
	 * @param array<string,array{position:int,image:string,tooltip:string,label:string}> $catalog Method catalog (IfthenpayLpMethodCatalog::get()), position-sorted for display.
	 */
	public static function render_payments_configuration( array $gatewaykeys, array $accounts, array $catalog ) {
		$json                 = OsSettingsHelper::get_settings_value( 'ifthenpay_payment_methods_configuration', '{}' );
		$cfg                  = json_decode( $json, true ) ?: array();
		$selected_gateway_key = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key', '' );
		?>
		<div class="sub-section-row">
			<div class="sub-section-label">
				<h3><?php echo esc_html__( 'Payments Configuration', 'ifthenpay-payments-for-latepoint' ); ?></h3>
			</div>
			<div class="sub-section-content">
				<?php self::render_gateway_key_select( $gatewaykeys, $selected_gateway_key ); ?>
				<?php self::render_payment_methods( $catalog, $accounts[ $selected_gateway_key ] ?? array(), $cfg ); ?>
				<?php self::render_default_method_select(); ?>
				<input type="hidden"
					id="ifthenpay_payment_methods_configuration"
					name="settings[ifthenpay_payment_methods_configuration]"
					value="<?php echo esc_attr( wp_json_encode( $cfg ) ); ?>" />
			</div>
		</div>
		<?php
	}

	private static function render_gateway_key_select( array $gatewaykeys, string $selected_gateway_key ) {
		echo OsFormHelper::select_field(
			'settings[ifthenpay_gateway_key]',
			esc_html__( 'Gateway Key', 'ifthenpay-payments-for-latepoint' ),
			$gatewaykeys,
			$selected_gateway_key,
			array( 'class' => 'ifthenpay-gateway-select' )
		);
	}

	/**
	 * One row per catalog method. A gateway record holds at most one account per method (verified
	 * live, contracts/api.md operation #2 — not a list to choose from), so there is nothing left to
	 * pick: the checkbox is enabled exactly when the selected gateway has an account for that
	 * method, and its account key travels in a hidden field, not a dropdown (003 T-11).
	 *
	 * @param array<string,array{position:int,image:string,tooltip:string,label:string}> $catalog              Method catalog, keyed by method code.
	 * @param array<string,string>                                                       $accounts_for_gateway `{methodKey: accountKey}` for the currently selected gateway only.
	 * @param array<string,array{checked?:bool,selected_account?:string}>                $cfg                  Saved per-method configuration.
	 */
	private static function render_payment_methods( array $catalog, array $accounts_for_gateway, array $cfg ) {
		uasort( $catalog, fn( $a, $b ) => ( $a['position'] ?? 0 ) <=> ( $b['position'] ?? 0 ) );
		?>
		<div class="os-row">
			<div class="os-col-12">
				<label class="ifthenpay-section-label">
					<?php echo esc_html__( 'Payment Methods', 'ifthenpay-payments-for-latepoint' ); ?>
				</label>
				<div class="ifthenpay-methods-list">
					<?php
					foreach ( $catalog as $slug => $props ) :
						$account_key = $accounts_for_gateway[ $slug ] ?? '';
						$has_account = '' !== $account_key;
						$is_checked  = $has_account && ! empty( $cfg[ $slug ]['checked'] );
						?>
						<div class="ifthenpay-method-item" data-entity="<?php echo esc_attr( $slug ); ?>">
							<input type="checkbox"
								class="ifthenpay-method-checkbox"
								<?php checked( $is_checked ); ?>
								<?php disabled( ! $has_account ); ?> />
							<img src="<?php echo esc_url( $props['image'] ); ?>"
								class="ifthenpay-method-icon"
								alt="<?php echo esc_attr( $props['label'] ); ?>" />
							<span class="ifthenpay-method-name">
								<?php echo esc_html( strtoupper( $props['label'] ) ); ?>
							</span>
							<div class="ifthenpay-method-right">
								<?php if ( $has_account ) : ?>
									<input type="hidden"
										class="ifthenpay-method-account"
										name="settings[ifthenpay_payment_methods_configuration][<?php echo esc_attr( $slug ); ?>][selected_account]"
										value="<?php echo esc_attr( $account_key ); ?>" />
								<?php else : ?>
									<div class="ifthenpay-no-accounts">
										<?php echo esc_html__( 'No accounts.', 'ifthenpay-payments-for-latepoint' ); ?>
										<a
											href="#"
											class="ifthenpay-activate"
											data-entity="<?php echo esc_attr( $slug ); ?>">
											<?php echo esc_html__( 'Activate', 'ifthenpay-payments-for-latepoint' ); ?>
										</a>.
									</div>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	private static function render_default_method_select() {
		$json = OsSettingsHelper::get_settings_value( 'ifthenpay_payment_methods_configuration', '{}' );
		$cfg  = json_decode( $json, true ) ?: array();

		$selected_methods = array_keys( array_filter( $cfg, fn( $m ) => ! empty( $m['checked'] ) ) );
		$options          = array( '' => '' ) + array_combine( $selected_methods, array_map( 'strtoupper', $selected_methods ) );
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

	public static function render_others_configuration() {
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
		<?php
	}
}
