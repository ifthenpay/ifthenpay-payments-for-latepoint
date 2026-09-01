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

			$dataset = IfthenpayLpGatewayDataset::get( $key );
			$catalog = IfthenpayLpMethodCatalog::get() ?? array();

			ob_start();
			IfthenpayAdminFormRenderer::render_connection_status( $dataset );
			IfthenpayAdminFormRenderer::render_payments_configuration(
				$dataset['gatewaykeys'] ?? array(),
				$dataset['accounts'] ?? array(),
				$catalog
			);
			IfthenpayAdminFormRenderer::render_others_configuration();
			$html = ob_get_clean();

			$this->send_json(
				array(
					'status'      => LATEPOINT_STATUS_SUCCESS,
					'html'        => $html,
					'inline_data' => array(
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
		 * Send activation payment method email to ifthenpay Heldesk.
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

			$sent = IfthenpayEmailHelper::send_activation_email( $payload );

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
	}

endif;
