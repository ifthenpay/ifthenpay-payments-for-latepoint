<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'OsPaymentsIfthenpaySettingsController' ) ) :

	/**
	 * The Payments settings page's own AJAX endpoints — previewing and clearing a Backoffice Key,
	 * and requesting a method's activation. Admin-only: nothing here is added to `action_access`,
	 * so it keeps LatePoint's default settings-page access control.
	 *
	 * @package ifthenpay-payments-for-latepoint
	 */
	class OsPaymentsIfthenpaySettingsController extends OsController {

		/**
		 * Preview a Backoffice Key before it is saved: format-check, remote verification, then
		 * render the gateway/method config exactly as it would look once saved. Saves nothing —
		 * the real, authoritative save runs through the settings page's own save, validated by
		 * `validate_backoffice_key_on_save()` in the main plugin file. This is why there is no
		 * failure-path settings cleanup here: nothing was written, so there is nothing to unwind.
		 *
		 * @return void Sends JSON with status, message, and form HTML.
		 */
		public function validate_key() {
			$key = sanitize_text_field( $this->params['backoffice_key'] ?? '' );

			if ( '' === $key ) {
				$this->send_json(
					array(
						'status'  => LATEPOINT_STATUS_ERROR,
						'message' => __( 'Enter a Backoffice Key first.', 'ifthenpay-payments-for-latepoint' ),
					)
				);
				return;
			}

			$rejection = IfthenpayLpBackofficeKeyValidation::check( $key );
			if ( null !== $rejection ) {
				$this->send_json(
					array(
						'status'  => LATEPOINT_STATUS_ERROR,
						'message' => $rejection,
					)
				);
				return;
			}

			$dataset     = IfthenpayLpGatewayDataset::get( $key );
			$notice      = IfthenpayLpAdminFormRenderer::get_connection_notice( $dataset );
			$gatewaykeys = $dataset['gatewaykeys'] ?? array();

			// Nothing to configure without a gateway key — same as the page's own render, an empty
			// Gateway Key row and an all-"No accounts" method list would only repeat what the
			// notice above already says.
			$html                 = '';
			$selected_gateway_key = IfthenpayLpAdminFormRenderer::resolve_selected_gateway_key( $gatewaykeys );
			if ( array() !== $gatewaykeys ) {
				ob_start();
				IfthenpayLpAdminFormRenderer::render_payments_configuration(
					$selected_gateway_key,
					$dataset['accounts'] ?? array(),
					IfthenpayLpMethodCatalog::get() ?? array()
				);
				$html = ob_get_clean();
			}

			$this->send_json(
				array(
					'status'           => LATEPOINT_STATUS_SUCCESS,
					'html'             => $html,
					// The Gateway Key row lives inside the Backoffice Configuration section this
					// response never otherwise touches — sent separately so the admin script can
					// refresh just that row instead of the whole section.
					'gateway_key_html' => IfthenpayLpAdminFormRenderer::render_gateway_key_row( $gatewaykeys, $selected_gateway_key ),
					'notice'           => $notice,
					'inline_data'      => array(
						'accounts' => $dataset['accounts'] ?? array(),
					),
				)
			);
		}

		/**
		 * Clears every setting derived from the Backoffice Key — the key itself, the selected
		 * Gateway Key, the enabled payment methods, and the Default Method — so "Disconnect"
		 * actually starts the merchant over instead of leaving the previous configuration sitting
		 * in the database, invisible until the next key happens to reuse the same method codes.
		 *
		 * @return void Sends JSON with status and message.
		 */
		public function disconnect() {
			foreach (
				array(
					'ifthenpay_backoffice_key',
					'ifthenpay_gateway_key',
					'ifthenpay_payment_methods_configuration',
					'ifthenpay_default_method',
				) as $setting_name
			) {
				OsSettingsHelper::remove_setting_by_name( $setting_name );
			}

			$this->send_json(
				array(
					'status'  => LATEPOINT_STATUS_SUCCESS,
					'message' => __( 'Disconnected. The Backoffice Key and everything configured under it have been cleared.', 'ifthenpay-payments-for-latepoint' ),
				)
			);
		}

		/**
		 * Send activation payment method email to ifthenpay Helpdesk.
		 *
		 * @return void Sends JSON with status and message.
		 */
		public function activate_account_by_entity() {
			$wp_user = wp_get_current_user();

			$payload = array(
				'gateway_key'       => sanitize_text_field( $this->params['gateway_key'] ),
				'entity'            => sanitize_text_field( $this->params['entity'] ),
				'backoffice_key'    => OsSettingsHelper::get_settings_value( 'ifthenpay_backoffice_key' ),
				'customer_email'    => $wp_user->data->user_email,
				'site_url'          => home_url( '/' ),
				'site_name'         => get_bloginfo( 'name' ),
				'wp_version'        => get_bloginfo( 'version' ),
				'latepoint_version' => LATEPOINT_VERSION,
				'plugin_version'    => IFTHENPAY_PLUGIN_VERSION,
			);

			$sent = IfthenpayLpEmailHelper::send_activation_email( $payload );

			if ( $sent ) {
				$this->send_json(
					array(
						'status'  => LATEPOINT_STATUS_SUCCESS,
						'message' => __( 'Your activation request has been sent to support.', 'ifthenpay-payments-for-latepoint' ),
					)
				);
			} else {
				$this->send_json(
					array(
						'status'  => LATEPOINT_STATUS_ERROR,
						'message' => __( 'Failed to send the activation email. Please try again later.', 'ifthenpay-payments-for-latepoint' ),
					)
				);
			}
		}

		/**
		 * Manual recovery for a missed or failed inbound callback (D-5, spec 001) — not in
		 * `action_access['public']`, so this keeps this controller's own default (LatePoint's
		 * `settings__edit` capability), same as every other action here. The actual decision is
		 * IfthenpayLpManualRecheck::run() — shared with the WP-CLI equivalent — this method only
		 * maps its outcome to a merchant-facing message.
		 *
		 * @return void Sends JSON with status and message.
		 */
		public function recheck_payment() {
			$outcome = IfthenpayLpManualRecheck::run( sanitize_text_field( $this->params['token'] ?? '' ) );

			$this->send_json( self::recheck_response_for( $outcome['outcome'] ) );
		}

		/**
		 * Maps an IfthenpayLpManualRecheck::run() outcome to a response.
		 *
		 * @param string $outcome One of IfthenpayLpManualRecheck's own constants.
		 * @return array{status:string,message:string}
		 */
		private static function recheck_response_for( string $outcome ): array {
			switch ( $outcome ) {
				case IfthenpayLpManualRecheck::SETTLED:
					return array(
						'status'  => LATEPOINT_STATUS_SUCCESS,
						'message' => __( 'Payment confirmed and settled.', 'ifthenpay-payments-for-latepoint' ),
					);
				case IfthenpayLpManualRecheck::MISSING_ARGUMENT:
					return array(
						'status'  => LATEPOINT_STATUS_ERROR,
						'message' => __( 'Missing payment reference.', 'ifthenpay-payments-for-latepoint' ),
					);
				case IfthenpayLpManualRecheck::NOT_FOUND:
					return array(
						'status'  => LATEPOINT_STATUS_ERROR,
						'message' => __( 'Payment record not found.', 'ifthenpay-payments-for-latepoint' ),
					);
				case IfthenpayLpManualRecheck::UNCONFIRMED:
					return array(
						'status'  => LATEPOINT_STATUS_ERROR,
						'message' => __( 'ifthenpay does not recognise this payment as completed — nothing was settled.', 'ifthenpay-payments-for-latepoint' ),
					);
				case IfthenpayLpManualRecheck::MISMATCH:
					return array(
						'status'  => LATEPOINT_STATUS_ERROR,
						'message' => __( 'ifthenpay confirms this transaction, but it belongs to a different booking — nothing was settled.', 'ifthenpay-payments-for-latepoint' ),
					);
				case IfthenpayLpManualRecheck::REJECTED:
					return array(
						'status'  => LATEPOINT_STATUS_ERROR,
						'message' => __( 'This payment could not be settled — the stored details no longer match (amount, or the order is no longer open).', 'ifthenpay-payments-for-latepoint' ),
					);
				default:
					return array(
						'status'  => LATEPOINT_STATUS_ERROR,
						'message' => __( 'Could not settle this payment right now. Please try again shortly.', 'ifthenpay-payments-for-latepoint' ),
					);
			}
		}
	}

endif;
