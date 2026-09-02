<?php

/**
 * Plugin Name:         ifthenpay | Payments for LatePoint
 * Plugin URI:          https://github.com/ifthenpay/ifthenpay-payments-for-latepoint
 * Description:         LatePoint addon for payments with ifthenpay
 * Version:             3.0.0
 * Requires at least:   6.5
 * Tested up to:        6.9
 * Requires PHP:        7.4
 * Author:              ifthenpay
 * Author URI:          https://ifthenpay.com/
 * License:             GPL v3
 * License URI:         https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:         ifthenpay-payments-for-latepoint
 * Domain Path:         /languages
 * Requires Plugins:    latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Guards against a fatal redeclaration if this file is ever loaded twice.
if ( ! class_exists( 'IfthenpayPaymentsForLatepoint' ) ) :

	/**
	 * Main Addon Class.
	 */
	class IfthenpayPaymentsForLatepoint {

		public $version    = '3.0.0';
		public $db_version = '2.0.0';
		public $addon_name = 'ifthenpay-payments-for-latepoint';

		public $processor_code = 'ifthenpay';

		public function __construct() {
			$this->define_constants();
			$this->init_hooks();
		}

		public function define_constants() {
			$this->define( 'IFTHENPAY_PLUGIN_VERSION', $this->version );
			$this->define( 'IFTHENPAY_TABLE_VERSION', $this->db_version );
		}

		public static function public_stylesheets() {
			return plugin_dir_url( __FILE__ ) . 'public/stylesheets/';
		}

		public static function public_javascripts() {
			return plugin_dir_url( __FILE__ ) . 'public/javascripts/';
		}

		public static function images_url() {
			return plugin_dir_url( __FILE__ ) . 'public/images/';
		}

		public function define( $name, $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}

		public function includes() {
			include_once __DIR__ . '/lib/controllers/payments-ifthenpay-checkout-controller.php';
			include_once __DIR__ . '/lib/controllers/payments-ifthenpay-settings-controller.php';

			include_once __DIR__ . '/lib/helpers/ifthenpay-api-client.php';
			include_once __DIR__ . '/lib/helpers/ifthenpay-lp-api-exception.php';
			include_once __DIR__ . '/lib/helpers/ifthenpay-lp-credential-exception.php';
			include_once __DIR__ . '/lib/helpers/ifthenpay-lp-transport-exception.php';
			include_once __DIR__ . '/lib/helpers/ifthenpay-lp-api-client.php';
			include_once __DIR__ . '/lib/helpers/ifthenpay-lp-key-validator.php';
			include_once __DIR__ . '/lib/helpers/ifthenpay-lp-backoffice-key-validation.php';
			include_once __DIR__ . '/lib/helpers/ifthenpay-data-formatter.php';
			include_once __DIR__ . '/lib/helpers/ifthenpay-lp-method-catalog.php';
			include_once __DIR__ . '/lib/helpers/ifthenpay-lp-gateway-dataset.php';
			include_once __DIR__ . '/lib/helpers/ifthenpay-lp-enabled-method-gate.php';
			include_once __DIR__ . '/lib/helpers/ifthenpay-lp-pay-by-link.php';
			include_once __DIR__ . '/lib/helpers/ifthenpay-lp-pay-by-link-method-eligibility.php';
			include_once __DIR__ . '/lib/helpers/ifthenpay-lp-multibanco-reference.php';
			include_once __DIR__ . '/lib/helpers/ifthenpay-lp-expiry.php';
			include_once __DIR__ . '/lib/helpers/ifthenpay-lp-callback-registration.php';
			include_once __DIR__ . '/lib/helpers/ifthenpay-lp-legacy-settings-cleanup.php';
			include_once __DIR__ . '/lib/helpers/ifthenpay-email-helper.php';

			include_once __DIR__ . '/lib/views/ifthenpay-admin-form-renderer.php';

			include_once __DIR__ . '/lib/models/ifthenpay-transaction-repository.php';

			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				include_once __DIR__ . '/lib/cli/ifthenpay-lp-cli-commands.php';
			}
		}

		public function init_hooks() {
			add_action( 'latepoint_init', array( $this, 'latepoint_init' ) );
			add_action( 'latepoint_includes', array( $this, 'includes' ) );
			add_filter( 'latepoint_installed_addons', array( $this, 'register_addon' ) );

			add_action( 'latepoint_admin_enqueue_scripts', array( $this, 'load_admin_scripts_and_styles' ) );
			add_filter( 'latepoint_localized_vars_admin', array( $this, 'localized_vars_for_admin' ) );

			add_action( 'latepoint_wp_enqueue_scripts', array( $this, 'load_front_scripts_and_styles' ) );
			add_filter( 'latepoint_localized_vars_front', array( $this, 'localized_vars_for_front' ) );

			add_filter( 'latepoint_clean_layout_js_files', array( $this, 'add_scripts_to_clean_layout' ), 10 );
			add_filter( 'latepoint_clean_layout_css_files', array( $this, 'add_styles_to_clean_layout' ), 10 );

			add_filter( 'latepoint_payment_processors', array( $this, 'register_payment_processor' ), 10, 2 );
			add_filter( 'latepoint_all_payment_methods', array( $this, 'register_payment_methods' ) );
			add_filter( 'latepoint_enabled_payment_methods', array( $this, 'register_enabled_payment_methods' ) );
			add_action( 'latepoint_payment_processor_settings', array( $this, 'add_settings_fields' ), 10 );
			add_filter( 'latepoint_encrypted_settings', array( $this, 'add_encrypted_settings' ) );
			// Fires for every OsModel save — see validate_backoffice_key_on_save()'s own docblock.
			add_action( 'latepoint_model_validate', array( $this, 'validate_backoffice_key_on_save' ), 10, 3 );
			// Post-save and non-blocking — see register_callback_on_settings_updated()'s own docblock.
			add_action( 'latepoint_settings_updated', array( $this, 'register_callback_on_settings_updated' ) );

			add_filter( 'latepoint_get_all_payment_times', array( $this, 'add_all_payment_methods_to_payment_times' ) );
			add_filter( 'latepoint_get_enabled_payment_times', array( $this, 'add_enabled_payment_methods_to_payment_times' ) );

			add_filter( 'latepoint_process_payment_for_order_intent', array( $this, 'process_payments_for_order_intent' ), 10, 2 );
			add_filter( 'latepoint_process_payment_for_transaction_intent', array( $this, 'process_payment_for_transaction_intent' ), 10, 2 );

			add_action( 'init', array( $this, 'init' ), 0 );

			register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
			register_deactivation_hook( __FILE__, array( $this, 'on_deactivate' ) );
		}

		public function process_payments_for_order_intent( array $result, OsOrderIntentModel $order_intent ): array {
			if (
				! OsPaymentsHelper::should_processor_handle_payment_for_order_intent( $this->processor_code, $order_intent )
				|| $order_intent->get_payment_data_value( 'method' ) !== 'ifthenpay_gateway'
			) {
				return $result;
			}
			return $this->process_payment_by_intent( $order_intent );
		}

		public function process_payment_for_transaction_intent( array $result, OsTransactionIntentModel $transaction_intent ): array {
			if (
				! OsPaymentsHelper::should_processor_handle_payment_for_transaction_intent( $this->processor_code, $transaction_intent )
				|| $transaction_intent->get_payment_data_value( 'method' ) !== 'ifthenpay_gateway'
			) {
				return $result;
			}
			return $this->process_payment_by_intent( $transaction_intent );
		}

		/**
		 * Shared intent‐processing logic for both ORDER and TRANSACTION.
		 *
		 * @param OsOrderIntentModel|OsTransactionIntentModel $intent
		 * @return array
		 */
		private function process_payment_by_intent( $intent_model ): array {
			$token = $intent_model->get_payment_data_value( 'token' );
			if ( ! $token ) {
				$msg = __( 'Missing payment token', 'ifthenpay-payments-for-latepoint' );
				$intent_model->add_error( 'payment_error', $msg );
				return array(
					'status'  => LATEPOINT_STATUS_ERROR,
					'message' => $msg,
				);
			}

			$payment = IfthenpayLpTransactionRepository::find_by_token( $token );
			if ( ! $payment ) {
				$msg = __( 'Payment record not found', 'ifthenpay-payments-for-latepoint' );
				$intent_model->add_error( 'payment_error', $msg );
				return array(
					'status'  => LATEPOINT_STATUS_ERROR,
					'message' => $msg,
				);
			}

			if ( $payment->status === 'PAID' ) {
				return array(
					'status'    => LATEPOINT_STATUS_SUCCESS,
					'processor' => $this->processor_code,
					'charge_id' => $token,
					'kind'      => LATEPOINT_TRANSACTION_KIND_CAPTURE,
				);
			}

			$msg = $payment->status === 'CANCELLED'
				? __( 'Payment was cancelled', 'ifthenpay-payments-for-latepoint' )
				: __( 'Payment failed', 'ifthenpay-payments-for-latepoint' );

			$intent_model->add_error( 'payment_error', $msg );
			return array(
				'status'  => LATEPOINT_STATUS_ERROR,
				'message' => $msg,
			);
		}

		public function add_all_payment_methods_to_payment_times( array $payment_times ): array {
			$payment_methods = $this->get_supported_payment_methods();
			foreach ( $payment_methods as $payment_method_code => $payment_method_info ) {
				$payment_times[ LATEPOINT_PAYMENT_TIME_NOW ][ $payment_method_code ][ $this->processor_code ] = $payment_method_info;
			}

			return $payment_times;
		}

		public function add_enabled_payment_methods_to_payment_times( array $payment_times ): array {
			if ( OsPaymentsHelper::is_payment_processor_enabled( $this->processor_code ) && $this->is_gateway_key_usable() ) {
				$payment_times = $this->add_all_payment_methods_to_payment_times( $payment_times );
			}

			return $payment_times;
		}

		/**
		 * The processor toggle alone is not enough — the saved Gateway Key must still be a
		 * real, live one for the current Backoffice Key, checked fresh every time (no caching of
		 * its own; IfthenpayLpGatewayDataset::get() already caches per request).
		 */
		private function is_gateway_key_usable(): bool {
			return IfthenpayLpEnabledMethodGate::is_usable(
				(string) OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key', '' ),
				(string) OsSettingsHelper::get_settings_value( 'ifthenpay_backoffice_key', '' )
			);
		}

		public function add_encrypted_settings( $encrypted_settings ) {
			$encrypted_settings[] = 'ifthenpay_backoffice_key';
			return $encrypted_settings;
		}

		/**
		 * Fires for every OsModel save (see OsModel::validate()) — filters down to the one
		 * setting this addon cares about, decrypts it (SettingsController::update() and
		 * OsSettingsHelper::save_setting_by_name() both encrypt before save() is ever called,
		 * since this setting is registered via add_encrypted_settings() above), and rejects the
		 * save only for a confirmed bad key — never for a transport failure.
		 *
		 * @param mixed $model The model instance being saved; only OsSettingsModel is relevant here.
		 */
		public function validate_backoffice_key_on_save( $model ) {
			if ( ! ( $model instanceof OsSettingsModel ) || 'ifthenpay_backoffice_key' !== $model->name ) {
				return;
			}

			$key = OsEncryptHelper::decrypt_value( $model->value );
			$key = false === $key ? '' : $key;

			$error = IfthenpayLpBackofficeKeyValidation::check( $key );
			if ( null !== $error ) {
				// 'validation' is not a label — OsModel::has_validation_error() checks for that
				// exact error code (lib/models/model.php:1046), matching every one of LatePoint's
				// own built-in property validators. A different code stores the message but never
				// blocks the save.
				$model->add_error( 'validation', $error );
			}
		}

		/**
		 * Fires after every settings save (see SettingsController::update()) — registers the
		 * callback URL only when a Gateway Key was actually part of this save. Outcome is stored
		 * by IfthenpayLpCallbackRegistration itself, for add_settings_fields() to surface on the
		 * next render; never blocks or unwinds the settings save that just happened.
		 *
		 * @param array<string,mixed> $settings The submitted settings, keyed by setting name.
		 */
		public function register_callback_on_settings_updated( $settings ) {
			if ( ! isset( $settings['ifthenpay_gateway_key'] ) ) {
				return;
			}

			$gateway_key = sanitize_text_field( $settings['ifthenpay_gateway_key'] );
			if ( '' === $gateway_key ) {
				return;
			}

			IfthenpayLpCallbackRegistration::register( $gateway_key );
		}

		public function localized_vars_for_admin( $localized_vars ) {
			$localized_vars['ifthenpay_validate_key_route']     = OsRouterHelper::build_route_name( 'payments_ifthenpay_settings', 'validate_key' );
			$localized_vars['ifthenpay_disconnect_route']       = OsRouterHelper::build_route_name( 'payments_ifthenpay_settings', 'disconnect' );
			$localized_vars['ifthenpay_activate_account_route'] = OsRouterHelper::build_route_name( 'payments_ifthenpay_settings', 'activate_account_by_entity' );

			// The full {gatewayKey: {methodKey: accountKey}} map, every gateway at once — the
			// select's own "change" handler looks up into this client-side, no AJAX round trip
			// per gateway.
			$backoffice_key = OsSettingsHelper::get_settings_value( 'ifthenpay_backoffice_key' );
			$dataset        = $backoffice_key ? IfthenpayLpGatewayDataset::get( $backoffice_key ) : null;

			$localized_vars['ifthenpay_accounts'] = $dataset['accounts'] ?? array();
			// Toasted once on page load by the admin script — the only place this state is shown at
			// all, since add_settings_fields() renders nothing else below a missing gateway key.
			$localized_vars['ifthenpay_connection_notice'] = $backoffice_key ? IfthenpayAdminFormRenderer::get_connection_notice( $dataset ) : null;

			$localized_vars['ifthenpay_translations'] = array(
				'no_accounts'        => __( 'No accounts.', 'ifthenpay-payments-for-latepoint' ),
				'activate'           => __( 'Activate', 'ifthenpay-payments-for-latepoint' ),
				'validation_failed'  => __( 'Validation failed.', 'ifthenpay-payments-for-latepoint' ),
				'server_error'       => __( 'Server error. Please try again.', 'ifthenpay-payments-for-latepoint' ),
				'activation_sent'    => __( 'Your activation request has been sent to support.', 'ifthenpay-payments-for-latepoint' ),
				'activation_failed'  => __( 'Failed to send activation request.', 'ifthenpay-payments-for-latepoint' ),
				'confirm_disconnect' => __( 'Disconnect this Backoffice Key? This clears the Gateway Key and Payment Methods configured under it right away.', 'ifthenpay-payments-for-latepoint' ),
			);

			return $localized_vars;
		}

		public function localized_vars_for_front( $localized_vars ) {
			if ( OsPaymentsHelper::is_payment_processor_enabled( $this->processor_code ) ) {
				$localized_vars['is_ifthenpay_active']                         = true;
				$localized_vars['ifthenpay_order_payment_options_route']       = OsRouterHelper::build_route_name( 'payments_ifthenpay_checkout', 'get_order_ifthenpay_options' );
				$localized_vars['ifthenpay_transaction_payment_options_route'] = OsRouterHelper::build_route_name( 'payments_ifthenpay_checkout', 'get_transaction_ifthenpay_options' );
				$localized_vars['ifthenpay_check_status_route']                = OsRouterHelper::build_route_name( 'payments_ifthenpay_checkout', 'update_payment_repo_by_modal_url' );

				$localized_vars['ifthenpay_translations'] = array(
					'warning' => __( '⚠️ Please do not close this window until your payment completes. You’ll be redirected to the store page automatically.', 'ifthenpay-payments-for-latepoint' ),
				);
			} else {
				$localized_vars['is_ifthenpay_active'] = false;
			}
			return $localized_vars;
		}

		public function get_supported_payment_methods() {
			return array(
				'ifthenpay_gateway' => array(
					'name'      => 'ifthenpay Gateway',
					'label'     => 'ifthenpay Gateway',
					'image_url' => $this->images_url() . 'ifthenpay_simbolo.png',
					'code'      => 'ifthenpay_checkout',
					'time_type' => 'later',
				),
			);
		}

		public function register_payment_processor( $payment_processors ) {
			$payment_processors[ $this->processor_code ] = array(
				'code'      => $this->processor_code,
				'name'      => __( 'ifthenpay', 'ifthenpay-payments-for-latepoint' ),
				'image_url' => $this->images_url() . 'processor-logo.png',
			);
			return $payment_processors;
		}

		public function register_payment_methods( $payment_methods ) {
			$payment_methods = array_merge( $payment_methods, $this->get_supported_payment_methods() );
			return $payment_methods;
		}

		public function register_enabled_payment_methods( $enabled_payment_methods ) {
			if ( OsPaymentsHelper::is_payment_processor_enabled( $this->processor_code ) && $this->is_gateway_key_usable() ) {
				$enabled_payment_methods = array_merge( $enabled_payment_methods, $this->get_supported_payment_methods() );
			}
			return $enabled_payment_methods;
		}

		public function add_settings_fields( $processor_code ) {
			if ( $processor_code !== $this->processor_code ) {
				return;
			}

			// Re-fetched on every render, not only right after "Connect" — a key can be revoked
			// after it was stored, or gateway keys added/removed on ifthenpay's side, independently
			// of this site. The dataset is cached per request, so this and
			// localized_vars_for_admin()'s use of the same key cost one HTTP call, not two.
			$backoffice_key       = OsSettingsHelper::get_settings_value( 'ifthenpay_backoffice_key' );
			$dataset              = $backoffice_key ? IfthenpayLpGatewayDataset::get( $backoffice_key ) : null;
			$gatewaykeys          = $dataset['gatewaykeys'] ?? array();
			$selected_gateway_key = IfthenpayAdminFormRenderer::resolve_selected_gateway_key( $gatewaykeys );

			IfthenpayAdminFormRenderer::render_backoffice_configuration( $backoffice_key, $gatewaykeys, $selected_gateway_key );

			// Nothing here below a missing gateway key: a Payment Methods list that can only ever
			// say "No accounts" has nothing a merchant can act on — the connection notice
			// (localized_vars_for_admin(), surfaced as a toast) already says why.
			if ( array() !== $gatewaykeys ) {
				IfthenpayAdminFormRenderer::render_payments_configuration(
					$selected_gateway_key,
					$dataset['accounts'] ?? array(),
					IfthenpayLpMethodCatalog::get() ?? array()
				);

				// The last registration outcome for the currently saved Gateway Key —
				// stored by register_callback_on_settings_updated() after a save, surfaced here
				// so a merchant sees a failure without re-entering the form.
				$gateway_key = OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key' );
				if ( $gateway_key ) {
					IfthenpayAdminFormRenderer::render_callback_status( IfthenpayLpCallbackRegistration::get_status( $gateway_key ) );
				}
			}
		}

		public function load_front_scripts_and_styles() {
			wp_enqueue_style( 'ifthenpay-payments-for-latepoint-front', $this->public_stylesheets() . 'ifthenpay-payments-for-latepoint-front.css', false, $this->version );
			wp_enqueue_script( 'ifthenpay-payments-for-latepoint-front', $this->public_javascripts() . 'ifthenpay-payments-for-latepoint-front.js', array( 'jquery' ), true, $this->version );
		}

		public function load_admin_scripts_and_styles() {
			wp_enqueue_style( 'ifthenpay-payments-for-latepoint', $this->public_stylesheets() . 'ifthenpay-payments-for-latepoint-admin.css', false, $this->version );
			wp_enqueue_script( 'ifthenpay-payments-for-latepoint', $this->public_javascripts() . 'ifthenpay-payments-for-latepoint-admin.js', array( 'jquery' ), true, $this->version );
		}

		public function add_scripts_to_clean_layout( array $js_files ): array {
			$js_files[] = 'ifthenpay-payments-for-latepoint-front';

			return $js_files;
		}

		public function add_styles_to_clean_layout( array $css_files ): array {
			$css_files[] = 'ifthenpay-payments-for-latepoint-front';

			return $css_files;
		}

		public function init() {
			// Domain Path in the plugin header (see the top of this file) is not itself enough —
			// nothing loads the compiled .mo files without this call.
			load_plugin_textdomain( 'ifthenpay-payments-for-latepoint', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

			// Cheap on every request when already current; upgrades the schema on an in-place
			// plugin update, not only on (re)activation.
			IfthenpayLpTransactionRepository::maybe_upgrade_schema();

			// Same reasoning as above: a site upgrading from an older version of this plugin has a
			// Gateway Key that's stale the moment this version is active, not only after the
			// merchant next saves settings.
			IfthenpayLpLegacySettingsCleanup::maybe_run();
		}

		public function latepoint_init() {
			LatePoint\Cerber\Router::init_addon();
		}

		public function on_deactivate() {}

		public function on_activate() {
			do_action( 'latepoint_on_addon_activate', $this->addon_name, $this->version );

			// Create/upgrade the ifthenpay_transactions table. On a site still running the old
			// single-purpose ifthenpay_payments table, this also migrates its PENDING rows and
			// renames it to _legacy — see IfthenpayLpTransactionRepository::migrate_legacy_pending_and_retire().
			if ( ! class_exists( 'IfthenpayLpTransactionRepository' ) ) {
				require_once __DIR__ . '/lib/models/ifthenpay-transaction-repository.php';
			}
			IfthenpayLpTransactionRepository::maybe_upgrade_schema();

			if ( ! class_exists( 'IfthenpayLpLegacySettingsCleanup' ) ) {
				require_once __DIR__ . '/lib/helpers/ifthenpay-lp-legacy-settings-cleanup.php';
			}
			IfthenpayLpLegacySettingsCleanup::maybe_run();

			update_option( 'latepoint-payments-ifthenpay_addon_db_version', $this->db_version );
		}

		public function register_addon( $installed_addons ) {
			$installed_addons[] = array(
				'name'       => $this->addon_name,
				'db_version' => $this->db_version,
				'version'    => $this->version,
			);
			return $installed_addons;
		}
	}

endif;

if ( in_array( 'latepoint/latepoint.php', get_option( 'active_plugins', array() ) ) || array_key_exists( 'latepoint/latepoint.php', get_site_option( 'active_sitewide_plugins', array() ) ) ) {
	$LATEPOINT_ADDON_PAYMENTS_IFTHENPAY = new IfthenpayPaymentsForLatepoint();
}
