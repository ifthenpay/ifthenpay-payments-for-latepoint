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

		/**
		 * Cache-busting query arg for a public asset: the file's own last-modified time when it can
		 * be read, falling back to the plugin version. A static version string across many commits
		 * within the same unreleased version (as during active development) means the browser never
		 * sees a reason to refetch an edited .css/.js file — this changes on every edit instead, in
		 * both dev and production, without needing a manual version bump per asset tweak.
		 *
		 * @param string $relative_path Path under the plugin root, e.g. `public/stylesheets/x.css`.
		 */
		public function asset_version( string $relative_path ): string {
			$path  = plugin_dir_path( __FILE__ ) . $relative_path;
			$mtime = file_exists( $path ) ? filemtime( $path ) : false;

			return false !== $mtime ? (string) $mtime : $this->version;
		}

		public function define( $name, $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}

		public function includes() {
			include_once __DIR__ . '/lib/controllers/payments-ifthenpay-checkout-controller.php';
			include_once __DIR__ . '/lib/controllers/payments-ifthenpay-settings-controller.php';
			include_once __DIR__ . '/lib/controllers/ifthenpay-lp-callback-rest-controller.php';

			include_once __DIR__ . '/lib/models/api/ifthenpay-lp-exceptions.php';
			include_once __DIR__ . '/lib/models/api/ifthenpay-lp-api-client.php';
			include_once __DIR__ . '/lib/models/api/ifthenpay-lp-key-validator.php';
			include_once __DIR__ . '/lib/models/validation/ifthenpay-lp-backoffice-key-validation.php';
			include_once __DIR__ . '/lib/models/validation/ifthenpay-lp-multibanco-validity-validation.php';
			include_once __DIR__ . '/lib/models/ifthenpay-lp-data-formatter.php';
			include_once __DIR__ . '/lib/models/api/ifthenpay-lp-method-catalog.php';
			include_once __DIR__ . '/lib/models/api/ifthenpay-lp-gateway-dataset.php';
			include_once __DIR__ . '/lib/models/ifthenpay-lp-enabled-method-gate.php';
			include_once __DIR__ . '/lib/models/api/ifthenpay-lp-pay-by-link.php';
			include_once __DIR__ . '/lib/models/ifthenpay-lp-pay-by-link-method-eligibility.php';
			include_once __DIR__ . '/lib/models/api/ifthenpay-lp-multibanco-reference.php';
			include_once __DIR__ . '/lib/models/settlement/ifthenpay-lp-expiry.php';
			include_once __DIR__ . '/lib/models/ifthenpay-lp-payment-times.php';
			include_once __DIR__ . '/lib/models/api/ifthenpay-lp-callback-registration.php';
			include_once __DIR__ . '/lib/models/ifthenpay-lp-callback-params.php';
			include_once __DIR__ . '/lib/models/settlement/ifthenpay-lp-settlement-lock.php';
			include_once __DIR__ . '/lib/models/settlement/ifthenpay-lp-settlement-result.php';
			include_once __DIR__ . '/lib/models/ifthenpay-lp-legacy-settings-cleanup.php';
			include_once __DIR__ . '/lib/models/ifthenpay-lp-payment-processor.php';
			include_once __DIR__ . '/lib/models/ifthenpay-lp-payment-method-availability.php';

			include_once __DIR__ . '/lib/views/ifthenpay-lp-admin-form-renderer.php';
			include_once __DIR__ . '/lib/views/ifthenpay-lp-email-helper.php';

			include_once __DIR__ . '/lib/models/ifthenpay-lp-transaction-repository.php';

			include_once __DIR__ . '/lib/models/settlement/ifthenpay-lp-settlement.php';
			include_once __DIR__ . '/lib/models/api/ifthenpay-lp-transaction-status.php';
			include_once __DIR__ . '/lib/models/settlement/ifthenpay-lp-manual-recheck.php';
			include_once __DIR__ . '/lib/views/ifthenpay-lp-reference-display.php';
			include_once __DIR__ . '/lib/models/settlement/ifthenpay-lp-expiry-sweep.php';

			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				include_once __DIR__ . '/lib/controllers/ifthenpay-lp-cli-commands.php';
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

			add_filter( 'latepoint_payment_processors', array( 'IfthenpayLpPaymentMethodAvailability', 'register_payment_processor' ), 10, 2 );
			add_filter( 'latepoint_all_payment_methods', array( 'IfthenpayLpPaymentMethodAvailability', 'register_payment_methods' ) );
			add_filter( 'latepoint_enabled_payment_methods', array( 'IfthenpayLpPaymentMethodAvailability', 'register_enabled_payment_methods' ) );
			add_action( 'latepoint_payment_processor_settings', array( $this, 'add_settings_fields' ), 10 );
			add_filter( 'latepoint_encrypted_settings', array( $this, 'add_encrypted_settings' ) );
			// Fires for every OsModel save — see validate_backoffice_key_on_save()'s own docblock.
			add_action( 'latepoint_model_validate', array( $this, 'validate_backoffice_key_on_save' ), 10, 3 );
			add_action( 'latepoint_model_validate', array( $this, 'validate_multibanco_validity_on_save' ), 10, 3 );
			// Post-save and non-blocking — see register_callback_on_settings_updated()'s own docblock.
			add_action( 'latepoint_settings_updated', array( $this, 'register_callback_on_settings_updated' ) );

			add_filter( 'latepoint_get_all_payment_times', array( 'IfthenpayLpPaymentMethodAvailability', 'add_all_payment_methods_to_payment_times' ) );
			add_filter( 'latepoint_get_enabled_payment_times', array( 'IfthenpayLpPaymentMethodAvailability', 'add_enabled_payment_methods_to_payment_times' ) );

			add_filter( 'latepoint_process_payment_for_order_intent', array( 'IfthenpayLpPaymentProcessor', 'process_payments_for_order_intent' ), 10, 2 );
			add_filter( 'latepoint_process_payment_for_transaction_intent', array( 'IfthenpayLpPaymentProcessor', 'process_payment_for_transaction_intent' ), 10, 2 );
			add_action( 'latepoint_transaction_created', array( 'IfthenpayLpPaymentProcessor', 'backfill_realtime_transaction_notes' ) );

			add_action( 'rest_api_init', array( 'IfthenpayLpCallbackRestController', 'register_routes' ) );
			// Not IfthenpayLpExpirySweep::HOOK here — that class doesn't exist yet at this point
			// (init_hooks() runs from this addon's own constructor, before includes() has loaded
			// any lib/ file; see this method's own callable-array registrations above, which are
			// resolved lazily when their hooks actually fire, unlike a class constant reference).
			add_action( 'ifthenpay_lp_expiry_sweep', array( 'IfthenpayLpExpirySweep', 'run' ) );

			// Customer-facing surfaces for a deferred payment's own reference (T-13, spec 001) —
			// see IfthenpayLpReferenceDisplay's own docblock for what each hook receives.
			add_action( 'latepoint_step_confirmation_head_info_after', array( $this, 'render_reference_on_confirmation_step' ) );
			add_action( 'latepoint_customer_dashboard_after_booking_info_tile', array( $this, 'render_reference_on_dashboard_tile' ) );
			add_action( 'latepoint_order_full_summary_head_info_after', array( $this, 'render_reference_on_order_summary' ) );
			add_action( 'latepoint_booking_full_summary_head_info_after', array( $this, 'render_reference_on_booking_summary' ) );
			add_filter( 'latepoint_process_prepare_data_for_run', array( $this, 'append_reference_to_email_content' ) );

			add_action( 'init', array( $this, 'init' ), 0 );

			register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
			register_deactivation_hook( __FILE__, array( $this, 'on_deactivate' ) );
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
		 * Same hook, same shape as validate_backoffice_key_on_save() — a separate method rather
		 * than one big branch, since this setting isn't encrypted and needs no decrypt step.
		 * Rejects the save only for a value outside the accepted range (T-14, spec 001): "Invalid
		 * validity is rejected at save, not at payment time."
		 *
		 * @param mixed $model The model instance being saved; only OsSettingsModel is relevant here.
		 */
		public function validate_multibanco_validity_on_save( $model ) {
			if ( ! ( $model instanceof OsSettingsModel ) || 'ifthenpay_multibanco_validity_days' !== $model->name ) {
				return;
			}

			$error = IfthenpayLpMultibancoValidityValidation::check( (string) $model->value );
			if ( null !== $error ) {
				$model->add_error( 'validation', $error );
			}
		}

		/**
		 * Fires after every settings save (see SettingsController::update()) — registers the
		 * callback URL only when a Gateway Key was actually part of this save. Outcome is stored
		 * by IfthenpayLpCallbackRegistration itself, for add_settings_fields() to surface on the
		 * next render; never blocks or unwinds the settings save that just happened.
		 *
		 * Also clears IfthenpayLpGatewayDataset's cache for this Backoffice Key, keyed separately
		 * from the Gateway Key check below — a merchant editing settings must never wait out that
		 * cache's own short TTL to see the save just made.
		 *
		 * @param array<string,mixed> $settings The submitted settings, keyed by setting name.
		 */
		public function register_callback_on_settings_updated( $settings ) {
			if ( isset( $settings['ifthenpay_backoffice_key'] ) ) {
				IfthenpayLpGatewayDataset::invalidate( sanitize_text_field( $settings['ifthenpay_backoffice_key'] ) );
			}

			if ( ! isset( $settings['ifthenpay_gateway_key'] ) ) {
				return;
			}

			$gateway_key = sanitize_text_field( $settings['ifthenpay_gateway_key'] );
			if ( '' === $gateway_key ) {
				return;
			}

			IfthenpayLpCallbackRegistration::register( $gateway_key );
		}

		/**
		 * Prints the reference box on the booking confirmation step.
		 *
		 * @param OsOrderModel $order As passed by the hook.
		 */
		public function render_reference_on_confirmation_step( $order ) {
			if ( ! ( $order instanceof OsOrderModel ) ) {
				return;
			}
			$record = IfthenpayLpReferenceDisplay::for_order( (int) $order->id );
			if ( $record ) {
				echo IfthenpayLpReferenceDisplay::render_html( $record ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_html() escapes internally.
			}
		}

		/**
		 * Prints the reference box on a customer dashboard booking tile.
		 *
		 * @param OsBookingModel $booking As passed by the hook.
		 */
		public function render_reference_on_dashboard_tile( $booking ) {
			if ( ! ( $booking instanceof OsBookingModel ) ) {
				return;
			}
			$record = IfthenpayLpReferenceDisplay::for_booking( (int) $booking->id );
			if ( $record ) {
				echo IfthenpayLpReferenceDisplay::render_html( $record ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_html() escapes internally.
			}
		}

		/**
		 * Prints the reference box on the order summary lightbox.
		 *
		 * @param OsOrderModel $order As passed by the hook.
		 */
		public function render_reference_on_order_summary( $order ) {
			$this->render_reference_on_confirmation_step( $order );
		}

		/**
		 * Prints the reference box on the booking summary lightbox.
		 *
		 * @param OsBookingModel $booking As passed by the hook.
		 */
		public function render_reference_on_booking_summary( $booking ) {
			$this->render_reference_on_dashboard_tile( $booking );
		}

		/**
		 * Appends the reference box to the confirmation email's own already-prepared content —
		 * fires after ProcessAction::prepare_data_for_run() has already built
		 * `prepared_data_for_run['content']` from the merchant's own template (process_action.php),
		 * so this only ever adds to it, never replaces it.
		 *
		 * Deliberately does not gate on `$action->event->type`: verified live that LatePoint never
		 * actually sets `ProcessAction::$event` on the real objects this filter receives — every
		 * real caller (OsProcessJobsHelper::create_jobs_for_process(), OsProcessJobModel::get_actions())
		 * constructs ProcessAction with only type/id/status/settings/prepared_data_for_run, never
		 * `event`. Reading the typed, uninitialized property via `?? ''` doesn't throw (PHP treats
		 * an uninitialized typed property as unset for `??`/`isset()`), so the original event-type
		 * check silently evaluated to '' and always failed closed — the whole callback was a
		 * no-op in production despite passing tests (the tests construct ProcessEvent by hand,
		 * which nothing in real LatePoint code does). `selected_data_objects` alone already scopes
		 * this correctly: it's reliably populated on every real ProcessAction, and for_order()/
		 * for_booking() already return null for anything that isn't a deferred Multibanco payment.
		 *
		 * @param \LatePoint\Misc\ProcessAction $action The action about to run.
		 * @return \LatePoint\Misc\ProcessAction
		 */
		public function append_reference_to_email_content( $action ) {
			if ( ! ( $action instanceof \LatePoint\Misc\ProcessAction ) || 'send_email' !== $action->type ) {
				return $action;
			}
			if ( ! isset( $action->prepared_data_for_run['content'] ) ) {
				return $action;
			}

			$record = null;
			foreach ( $action->selected_data_objects as $data_object ) {
				if ( 'order' === ( $data_object['model'] ?? '' ) ) {
					$record = IfthenpayLpReferenceDisplay::for_order( (int) $data_object['id'] );
				} elseif ( 'booking' === ( $data_object['model'] ?? '' ) ) {
					$record = IfthenpayLpReferenceDisplay::for_booking( (int) $data_object['id'] );
				}
				if ( $record ) {
					break;
				}
			}

			if ( $record ) {
				$action->prepared_data_for_run['content'] .= IfthenpayLpReferenceDisplay::render_html( $record );
			}

			return $action;
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
			$localized_vars['ifthenpay_connection_notice'] = $backoffice_key ? IfthenpayLpAdminFormRenderer::get_connection_notice( $dataset ) : null;

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
					'warning'              => __( '⚠️ Please do not close this window until your payment completes. You’ll be redirected to the store page automatically.', 'ifthenpay-payments-for-latepoint' ),
					'request_failed'       => __( 'Request failed.', 'ifthenpay-payments-for-latepoint' ),
					'verification_timeout' => __( 'We could not confirm your payment in time, so your booking was not completed. If a payment was taken, contact us with your reference and we will resolve it — otherwise, please try again.', 'ifthenpay-payments-for-latepoint' ),
				);
			} else {
				$localized_vars['is_ifthenpay_active'] = false;
			}
			return $localized_vars;
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
			$selected_gateway_key = IfthenpayLpAdminFormRenderer::resolve_selected_gateway_key( $gatewaykeys );

			IfthenpayLpAdminFormRenderer::render_backoffice_configuration( $backoffice_key, $gatewaykeys, $selected_gateway_key );

			// Nothing here below a missing gateway key: a Payment Methods list that can only ever
			// say "No accounts" has nothing a merchant can act on — the connection notice
			// (localized_vars_for_admin(), surfaced as a toast) already says why.
			if ( array() !== $gatewaykeys ) {
				IfthenpayLpAdminFormRenderer::render_payments_configuration(
					$selected_gateway_key,
					$dataset['accounts'] ?? array(),
					IfthenpayLpMethodCatalog::get() ?? array()
				);

				// The last registration outcome for the currently saved Gateway Key —
				// stored by register_callback_on_settings_updated() after a save, surfaced here
				// so a merchant sees a failure without re-entering the form.
				$gateway_key = OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key' );
				if ( $gateway_key ) {
					IfthenpayLpAdminFormRenderer::render_callback_status( IfthenpayLpCallbackRegistration::get_status( $gateway_key ) );
				}
			}
		}

		public function load_front_scripts_and_styles() {
			wp_enqueue_style( 'ifthenpay-payments-for-latepoint-front', $this->public_stylesheets() . 'ifthenpay-payments-for-latepoint-front.css', false, $this->asset_version( 'public/stylesheets/ifthenpay-payments-for-latepoint-front.css' ) );
			wp_enqueue_script( 'ifthenpay-payments-for-latepoint-front', $this->public_javascripts() . 'ifthenpay-payments-for-latepoint-front.js', array( 'jquery' ), $this->asset_version( 'public/javascripts/ifthenpay-payments-for-latepoint-front.js' ), true );
		}

		public function load_admin_scripts_and_styles() {
			wp_enqueue_style( 'ifthenpay-payments-for-latepoint', $this->public_stylesheets() . 'ifthenpay-payments-for-latepoint-admin.css', false, $this->asset_version( 'public/stylesheets/ifthenpay-payments-for-latepoint-admin.css' ) );
			wp_enqueue_script( 'ifthenpay-payments-for-latepoint', $this->public_javascripts() . 'ifthenpay-payments-for-latepoint-admin.js', array( 'jquery' ), $this->asset_version( 'public/javascripts/ifthenpay-payments-for-latepoint-admin.js' ), true );
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

			// Same reasoning again: an in-place update from a version that never scheduled this
			// cron must still end up with it scheduled, not only a fresh (re)activation.
			if ( ! wp_next_scheduled( IfthenpayLpExpirySweep::HOOK ) ) {
				wp_schedule_event( time(), 'hourly', IfthenpayLpExpirySweep::HOOK );
			}
		}

		public function latepoint_init() {
			LatePoint\Cerber\Router::init_addon();
		}

		/**
		 * Loads a class this plugin owns, if it isn't already — activation/deactivation hooks can
		 * fire before includes() has run, unlike everything else in this file.
		 *
		 * @param string $class_name    The class this call needs.
		 * @param string $relative_path Its file, relative to this file's own directory.
		 */
		private function ensure_loaded( string $class_name, string $relative_path ): void {
			if ( ! class_exists( $class_name ) ) {
				require_once __DIR__ . $relative_path;
			}
		}

		public function on_deactivate() {
			$this->ensure_loaded( 'IfthenpayLpExpirySweep', '/lib/models/settlement/ifthenpay-lp-expiry-sweep.php' );
			wp_clear_scheduled_hook( IfthenpayLpExpirySweep::HOOK );
		}

		public function on_activate() {
			do_action( 'latepoint_on_addon_activate', $this->addon_name, $this->version );

			// Create/upgrade the ifthenpay_transactions table. On a site still running the old
			// single-purpose ifthenpay_payments table, this also migrates its PENDING rows and
			// renames it to _legacy — see IfthenpayLpTransactionRepository::migrate_legacy_pending_and_retire().
			$this->ensure_loaded( 'IfthenpayLpTransactionRepository', '/lib/models/ifthenpay-lp-transaction-repository.php' );
			IfthenpayLpTransactionRepository::maybe_upgrade_schema();

			$this->ensure_loaded( 'IfthenpayLpLegacySettingsCleanup', '/lib/models/ifthenpay-lp-legacy-settings-cleanup.php' );
			IfthenpayLpLegacySettingsCleanup::maybe_run();

			$this->ensure_loaded( 'IfthenpayLpExpirySweep', '/lib/models/settlement/ifthenpay-lp-expiry-sweep.php' );
			if ( ! wp_next_scheduled( IfthenpayLpExpirySweep::HOOK ) ) {
				wp_schedule_event( time(), 'hourly', IfthenpayLpExpirySweep::HOOK );
			}

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
