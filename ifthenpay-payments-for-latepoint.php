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
	exit;
}

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

		// --- Bootstrap: constants and file includes -----------------------

		/**
		 * The two constants every version of this addon has always defined.
		 */
		public function define_constants() {
			$this->define( 'IFTHENPAY_PLUGIN_VERSION', $this->version );
			$this->define( 'IFTHENPAY_TABLE_VERSION', $this->db_version );
		}

		public function define( $name, $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}

		/**
		 * Hooked to `latepoint_includes` (see register_bootstrap_hooks()) — grouped by concern, not
		 * load order: none of these files reference each other at the top level (only from inside a
		 * method body, resolved lazily when it's actually called), so only files that genuinely
		 * belong together are grouped together.
		 */
		public function includes() {
			$this->include_controllers();
			$this->include_api_client();
			$this->include_validation();
			$this->include_payment_processing();
			$this->include_views();
			$this->include_settlement();
			$this->include_cli();
		}

		private function include_controllers(): void {
			include_once __DIR__ . '/lib/controllers/payments-ifthenpay-checkout-controller.php';
			include_once __DIR__ . '/lib/controllers/payments-ifthenpay-settings-controller.php';
			include_once __DIR__ . '/lib/controllers/ifthenpay-lp-callback-rest-controller.php';
		}

		/**
		 * The ifthenpay HTTP layer itself and every operation built on it.
		 */
		private function include_api_client(): void {
			include_once __DIR__ . '/lib/models/api/ifthenpay-lp-exceptions.php';
			include_once __DIR__ . '/lib/models/api/ifthenpay-lp-api-client.php';
			include_once __DIR__ . '/lib/models/api/ifthenpay-lp-key-validator.php';
			include_once __DIR__ . '/lib/models/api/ifthenpay-lp-method-catalog.php';
			include_once __DIR__ . '/lib/models/api/ifthenpay-lp-gateway-dataset.php';
			include_once __DIR__ . '/lib/models/api/ifthenpay-lp-pay-by-link.php';
			include_once __DIR__ . '/lib/models/api/ifthenpay-lp-multibanco-reference.php';
			include_once __DIR__ . '/lib/models/api/ifthenpay-lp-payshop-reference.php';
			include_once __DIR__ . '/lib/models/api/ifthenpay-lp-callback-registration.php';
		}

		/**
		 * Save-time validators for this addon's own settings — see each hook glue method
		 * (validate_*_on_save()) for how they're wired to `latepoint_model_validate`.
		 */
		private function include_validation(): void {
			include_once __DIR__ . '/lib/models/validation/ifthenpay-lp-backoffice-key-validation.php';
			include_once __DIR__ . '/lib/models/validation/ifthenpay-lp-whole-days-setting-validation.php';
			include_once __DIR__ . '/lib/models/validation/ifthenpay-lp-multibanco-validity-validation.php';
			include_once __DIR__ . '/lib/models/validation/ifthenpay-lp-multibanco-lead-time-validation.php';
			include_once __DIR__ . '/lib/models/validation/ifthenpay-lp-payshop-validity-validation.php';
			include_once __DIR__ . '/lib/models/validation/ifthenpay-lp-payshop-lead-time-validation.php';
		}

		/**
		 * Deciding which methods are offered, and turning a checkout into an actual payment attempt.
		 */
		private function include_payment_processing(): void {
			include_once __DIR__ . '/lib/models/ifthenpay-lp-data-formatter.php';
			include_once __DIR__ . '/lib/models/ifthenpay-lp-enabled-method-gate.php';
			include_once __DIR__ . '/lib/models/ifthenpay-lp-pay-by-link-method-eligibility.php';
			include_once __DIR__ . '/lib/models/ifthenpay-lp-payment-times.php';
			include_once __DIR__ . '/lib/models/ifthenpay-lp-callback-params.php';
			include_once __DIR__ . '/lib/models/ifthenpay-lp-legacy-settings-cleanup.php';
			include_once __DIR__ . '/lib/models/ifthenpay-lp-appointment-lead-time.php';
			include_once __DIR__ . '/lib/models/settlement/ifthenpay-lp-expiry.php';
			include_once __DIR__ . '/lib/models/settlement/ifthenpay-lp-settlement-lock.php';
			include_once __DIR__ . '/lib/models/settlement/ifthenpay-lp-settlement-result.php';
			include_once __DIR__ . '/lib/models/ifthenpay-lp-payment-processor.php';
			include_once __DIR__ . '/lib/models/ifthenpay-lp-payment-method-availability.php';
		}

		private function include_views(): void {
			include_once __DIR__ . '/lib/views/ifthenpay-lp-admin-form-renderer.php';
			include_once __DIR__ . '/lib/views/ifthenpay-lp-email-helper.php';
			include_once __DIR__ . '/lib/views/ifthenpay-lp-reference-display.php';
		}

		/**
		 * Settling a payment, tracking it, and the crons that keep it from being left stale.
		 */
		private function include_settlement(): void {
			include_once __DIR__ . '/lib/models/ifthenpay-lp-transaction-repository.php';
			include_once __DIR__ . '/lib/models/settlement/ifthenpay-lp-settlement.php';
			include_once __DIR__ . '/lib/models/api/ifthenpay-lp-transaction-status.php';
			include_once __DIR__ . '/lib/models/settlement/ifthenpay-lp-manual-recheck.php';
			include_once __DIR__ . '/lib/models/settlement/ifthenpay-lp-expiry-sweep.php';
			include_once __DIR__ . '/lib/models/settlement/ifthenpay-lp-lapsed-appointment-digest.php';
			include_once __DIR__ . '/lib/models/ifthenpay-lp-process-seeder.php';
		}

		private function include_cli(): void {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				include_once __DIR__ . '/lib/controllers/ifthenpay-lp-cli-commands.php';
			}
		}

		// --- Bootstrap: hook registration -----------------------------

		/**
		 * Runs from the constructor, before includes() has loaded any lib/ file — every callback
		 * below is either a method on $this, or a callable array naming a class that isn't defined
		 * yet. Both are resolved lazily when the hook actually fires, not now, so this is safe; a
		 * class *constant* (e.g. `IfthenpayLpExpirySweep::HOOK`) would not be, since PHP resolves
		 * that immediately — see register_cron_hooks()'s own note.
		 */
		public function init_hooks() {
			$this->register_bootstrap_hooks();
			$this->register_asset_hooks();
			$this->register_settings_hooks();
			$this->register_payment_hooks();
			$this->register_cron_hooks();
			$this->register_reference_display_hooks();
			$this->register_lifecycle_hooks();
		}

		private function register_bootstrap_hooks(): void {
			add_action( 'latepoint_init', array( $this, 'latepoint_init' ) );
			add_action( 'latepoint_includes', array( $this, 'includes' ) );
			add_filter( 'latepoint_installed_addons', array( $this, 'register_addon' ) );
		}

		private function register_asset_hooks(): void {
			add_action( 'latepoint_admin_enqueue_scripts', array( $this, 'load_admin_scripts_and_styles' ) );
			add_filter( 'latepoint_localized_vars_admin', array( $this, 'localized_vars_for_admin' ) );

			add_action( 'latepoint_wp_enqueue_scripts', array( $this, 'load_front_scripts_and_styles' ) );
			add_filter( 'latepoint_localized_vars_front', array( $this, 'localized_vars_for_front' ) );

			add_filter( 'latepoint_clean_layout_js_files', array( $this, 'add_scripts_to_clean_layout' ), 10 );
			add_filter( 'latepoint_clean_layout_css_files', array( $this, 'add_styles_to_clean_layout' ), 10 );
		}

		private function register_settings_hooks(): void {
			add_filter( 'latepoint_payment_processors', array( 'IfthenpayLpPaymentMethodAvailability', 'register_payment_processor' ) );
			add_action( 'latepoint_payment_processor_settings', array( $this, 'add_settings_fields' ), 10 );
			add_filter( 'latepoint_encrypted_settings', array( $this, 'add_encrypted_settings' ) );

			// Fires for every OsModel save — each validate_*_on_save() filters down to its own
			// setting by name, so one hook can carry all of this addon's save-time validation.
			add_action( 'latepoint_model_validate', array( $this, 'validate_backoffice_key_on_save' ), 10, 3 );
			add_action( 'latepoint_model_validate', array( $this, 'validate_multibanco_validity_on_save' ), 10, 3 );
			add_action( 'latepoint_model_validate', array( $this, 'validate_multibanco_lead_time_on_save' ), 10, 3 );
			add_action( 'latepoint_model_validate', array( $this, 'validate_payshop_validity_on_save' ), 10, 3 );
			add_action( 'latepoint_model_validate', array( $this, 'validate_payshop_lead_time_on_save' ), 10, 3 );

			// Post-save and non-blocking — see register_callback_on_settings_updated()'s own docblock.
			add_action( 'latepoint_settings_updated', array( $this, 'register_callback_on_settings_updated' ) );
		}

		private function register_payment_hooks(): void {
			add_filter( 'latepoint_get_all_payment_times', array( 'IfthenpayLpPaymentMethodAvailability', 'add_all_payment_methods_to_payment_times' ) );
			add_filter( 'latepoint_get_enabled_payment_times', array( 'IfthenpayLpPaymentMethodAvailability', 'add_enabled_payment_methods_to_payment_times' ) );

			add_filter( 'latepoint_process_payment_for_order_intent', array( 'IfthenpayLpPaymentProcessor', 'process_payments_for_order_intent' ), 10, 2 );
			add_filter( 'latepoint_process_payment_for_transaction_intent', array( 'IfthenpayLpPaymentProcessor', 'process_payment_for_transaction_intent' ), 10, 2 );
			add_action( 'latepoint_transaction_created', array( 'IfthenpayLpPaymentProcessor', 'backfill_realtime_transaction_notes' ) );
		}

		/**
		 * The callback route and the two WP-Cron jobs — the one place in this file where a class
		 * *constant* reference isn't safe (see init_hooks()'s own note on why). The literal
		 * hook-name strings below must match `IfthenpayLpExpirySweep::HOOK` and
		 * `IfthenpayLpLapsedAppointmentDigest::HOOK`'s own values exactly.
		 */
		private function register_cron_hooks(): void {
			add_action( 'rest_api_init', array( 'IfthenpayLpCallbackRestController', 'register_routes' ) );
			add_action( 'ifthenpay_lp_expiry_sweep', array( 'IfthenpayLpExpirySweep', 'run' ) );
			add_action( 'ifthenpay_lp_lapsed_appointment_digest', array( 'IfthenpayLpLapsedAppointmentDigest', 'run' ) );
		}

		/**
		 * Customer-facing surfaces for a deferred payment's own reference — see
		 * IfthenpayLpReferenceDisplay's own docblock for what each hook receives. Two of these
		 * hooks are a confirmation-step/dashboard-tile pair, the other two a full-summary-lightbox
		 * pair — both pairs fire with the exact same single argument (OsOrderModel or
		 * OsBookingModel, confirmed against LatePoint core's own do_action() calls), so one
		 * callback per model type covers both call sites in each pair.
		 */
		private function register_reference_display_hooks(): void {
			add_action( 'latepoint_customer_dashboard_before_appointments', array( $this, 'prime_reference_cache_for_dashboard' ) );
			add_action( 'latepoint_step_confirmation_head_info_after', array( $this, 'render_reference_on_confirmation_step' ) );
			add_action( 'latepoint_order_full_summary_head_info_after', array( $this, 'render_reference_on_confirmation_step' ) );
			add_action( 'latepoint_customer_dashboard_after_booking_info_tile', array( $this, 'render_reference_on_dashboard_tile' ) );
			add_action( 'latepoint_booking_full_summary_head_info_after', array( $this, 'render_reference_on_dashboard_tile' ) );
			add_filter( 'latepoint_process_prepare_data_for_run', array( $this, 'append_reference_to_email_content' ) );
		}

		private function register_lifecycle_hooks(): void {
			add_action( 'init', array( $this, 'init' ), 0 );

			register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
			register_deactivation_hook( __FILE__, array( $this, 'on_deactivate' ) );

			add_action( 'admin_notices', array( $this, 'maybe_show_process_seeded_notice' ) );
		}

		// --- Settings: save-time validation and post-save side effects ----

		/**
		 * The `latepoint_encrypted_settings` filter callback — this addon's only setting that
		 * needs encryption at rest.
		 *
		 * @param string[] $encrypted_settings The filter's own accumulator.
		 * @return string[]
		 */
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
		 * Rejects the save only for a value outside the accepted range: invalid validity is rejected
		 * at save time, not silently discovered later at the moment of payment.
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
		 * Same hook, same shape as validate_multibanco_validity_on_save().
		 *
		 * @param mixed $model The model instance being saved; only OsSettingsModel is relevant here.
		 */
		public function validate_multibanco_lead_time_on_save( $model ) {
			if ( ! ( $model instanceof OsSettingsModel ) || 'ifthenpay_multibanco_lead_time_days' !== $model->name ) {
				return;
			}

			$error = IfthenpayLpMultibancoLeadTimeValidation::check( (string) $model->value );
			if ( null !== $error ) {
				$model->add_error( 'validation', $error );
			}
		}

		/**
		 * Same hook, same shape as validate_multibanco_validity_on_save() — Payshop's own setting,
		 * not shared with Multibanco's.
		 *
		 * @param mixed $model The model instance being saved; only OsSettingsModel is relevant here.
		 */
		public function validate_payshop_validity_on_save( $model ) {
			if ( ! ( $model instanceof OsSettingsModel ) || 'ifthenpay_payshop_validity_days' !== $model->name ) {
				return;
			}

			$error = IfthenpayLpPayshopValidityValidation::check( (string) $model->value );
			if ( null !== $error ) {
				$model->add_error( 'validation', $error );
			}
		}

		/**
		 * Same hook, same shape as validate_multibanco_lead_time_on_save() — Payshop's own setting,
		 * not shared with Multibanco's.
		 *
		 * @param mixed $model The model instance being saved; only OsSettingsModel is relevant here.
		 */
		public function validate_payshop_lead_time_on_save( $model ) {
			if ( ! ( $model instanceof OsSettingsModel ) || 'ifthenpay_payshop_lead_time_days' !== $model->name ) {
				return;
			}

			$error = IfthenpayLpPayshopLeadTimeValidation::check( (string) $model->value );
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

		// --- Reference display: confirmation step, dashboard, summaries, email ---

		/**
		 * Fires once, before LatePoint's own per-booking dashboard tile loop starts — warms this
		 * add-on's own transaction-lookup cache for every one of this customer's outstanding deferred
		 * rows in 2 queries total, so render_reference_on_dashboard_tile()'s own per-tile lookup
		 * (fired once per booking, right after this) hits cache instead of the database. See
		 * IfthenpayLpTransactionRepository::prime_cache_for_customer()'s own docblock for what this
		 * does and doesn't cover.
		 *
		 * @param OsCustomerModel $customer As passed by the hook.
		 */
		public function prime_reference_cache_for_dashboard( $customer ) {
			if ( ! ( $customer instanceof OsCustomerModel ) ) {
				return;
			}
			IfthenpayLpTransactionRepository::prime_cache_for_customer( (int) $customer->id );
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
				} elseif ( 'transaction' === ( $data_object['model'] ?? '' ) ) {
					// The transaction_created process (IfthenpayLpProcessSeeder) selects its email
					// action against a transaction, not an order/booking — resolve to the order
					// behind it so the same reference box (now in its paid state) reaches that email
					// too, reusing for_order() rather than a second lookup path.
					$transaction = new OsTransactionModel( (int) $data_object['id'] );
					$record      = $transaction->order_id ? IfthenpayLpReferenceDisplay::for_order( (int) $transaction->order_id ) : null;
				}
				if ( $record ) {
					break;
				}
			}

			if ( $record ) {
				$action->prepared_data_for_run['content'] .= IfthenpayLpReferenceDisplay::render_email_html( $record );
			}

			return $action;
		}

		// --- Assets, localization, and the settings page's own field rendering ---

		/**
		 * The `latepoint_localized_vars_admin` filter callback — everything the admin script
		 * needs up front, so it never has to round-trip for state the page render already knows.
		 *
		 * @param array<string,mixed> $localized_vars The filter's own accumulator.
		 * @return array<string,mixed>
		 */
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
		 * be read, falling back to the plugin version — so an edited .css/.js file is refetched
		 * immediately, without needing a manual version bump per tweak.
		 *
		 * @param string $relative_path Path under the plugin root, e.g. `public/stylesheets/x.css`.
		 */
		public function asset_version( string $relative_path ): string {
			$path  = plugin_dir_path( __FILE__ ) . $relative_path;
			$mtime = file_exists( $path ) ? filemtime( $path ) : false;

			return false !== $mtime ? (string) $mtime : $this->version;
		}

		// --- WordPress lifecycle: init, activation, deactivation ----------

		/**
		 * Runs on every request (priority 0, before LatePoint's own `init`) — the in-place-update
		 * catch-up work that on_activate() also does, so a site that merely updated the plugin
		 * (never re-activating it) still ends up in the same state as a fresh install.
		 */
		public function init() {
			// Domain Path in the plugin header alone doesn't load the compiled .mo files.
			load_plugin_textdomain( 'ifthenpay-payments-for-latepoint', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

			// Cheap when already current; also upgrades the schema on an in-place update, not only
			// on (re)activation. Same reasoning for the legacy-settings cleanup and the two
			// wp_schedule_event() calls below — an update must reach the same end state as a fresh
			// activation, not just whatever on_activate() did the first time this site installed it.
			IfthenpayLpTransactionRepository::maybe_upgrade_schema();
			IfthenpayLpLegacySettingsCleanup::maybe_run();

			if ( ! wp_next_scheduled( IfthenpayLpExpirySweep::HOOK ) ) {
				wp_schedule_event( time(), 'hourly', IfthenpayLpExpirySweep::HOOK );
			}
			if ( ! wp_next_scheduled( IfthenpayLpLapsedAppointmentDigest::HOOK ) ) {
				wp_schedule_event( time(), 'daily', IfthenpayLpLapsedAppointmentDigest::HOOK );
			}

			// Same reasoning as above: a site that already had this add-on active before the
			// transaction_created process existed would otherwise never get it, since on_activate()
			// only fires on a fresh (re)activation, not an in-place plugin update. Unlike the two
			// calls above, though, this one has no cheap internal early-out of its own — every one of
			// its three outcomes is terminal, so once one has been recorded there is nothing left to
			// seed, ever again, and the get_option() check below is what keeps this from running a
			// real OsProcessModel query on every single request forever, including unauthenticated
			// REST callback requests.
			if ( ! get_option( 'ifthenpay_lp_process_seed_completed' ) ) {
				$this->record_process_seed_outcome( IfthenpayLpProcessSeeder::seed_transaction_created_process() );
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

			$this->ensure_loaded( 'IfthenpayLpLapsedAppointmentDigest', '/lib/models/settlement/ifthenpay-lp-lapsed-appointment-digest.php' );
			wp_clear_scheduled_hook( IfthenpayLpLapsedAppointmentDigest::HOOK );
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

			$this->ensure_loaded( 'IfthenpayLpLapsedAppointmentDigest', '/lib/models/settlement/ifthenpay-lp-lapsed-appointment-digest.php' );
			if ( ! wp_next_scheduled( IfthenpayLpLapsedAppointmentDigest::HOOK ) ) {
				wp_schedule_event( time(), 'daily', IfthenpayLpLapsedAppointmentDigest::HOOK );
			}

			// Idempotent — see IfthenpayLpProcessSeeder's own docblock. record_process_seed_outcome()
			// only sets a notice option the first time a process is actually created, not on every
			// reactivation that finds one (ours or the merchant's own) already there.
			$this->ensure_loaded( 'IfthenpayLpProcessSeeder', '/lib/models/ifthenpay-lp-process-seeder.php' );
			$this->record_process_seed_outcome( IfthenpayLpProcessSeeder::seed_transaction_created_process() );

			update_option( 'latepoint-payments-ifthenpay_addon_db_version', $this->db_version );
		}

		/**
		 * Maps a seed_transaction_created_process() outcome to which one-time admin notice should
		 * show next render — 'already_exists' sets nothing, so a reactivation that finds either row
		 * already there stays silent, same as before this method existed. Also stamps the
		 * `ifthenpay_lp_process_seed_completed` option every time, regardless of outcome: all three
		 * outcomes are terminal (see IfthenpayLpProcessSeeder's own docblock), so init()'s own
		 * get_option() guard can skip calling the seeder at all from here on.
		 *
		 * @param string $outcome As returned by IfthenpayLpProcessSeeder::seed_transaction_created_process().
		 */
		private function record_process_seed_outcome( string $outcome ): void {
			if ( 'created' === $outcome ) {
				update_option( 'ifthenpay_lp_show_process_seeded_notice', true );
			} elseif ( 'created_disabled' === $outcome ) {
				update_option( 'ifthenpay_lp_show_process_conflict_notice', true );
			}
			update_option( 'ifthenpay_lp_process_seed_completed', true );
		}

		/**
		 * A one-time, dismiss-on-render notice — no ongoing state to track, unlike a real
		 * dismissible-forever notice, since both options record_process_seed_outcome() sets are only
		 * ever set true right after a process was actually created just now (never on a reactivation
		 * that found one already there), so each option is deleted the first time its own notice
		 * renders and never set again on its own.
		 */
		public function maybe_show_process_seeded_notice(): void {
			if ( get_option( 'ifthenpay_lp_show_process_seeded_notice' ) ) {
				delete_option( 'ifthenpay_lp_show_process_seeded_notice' );
				printf(
					'<div class="notice notice-info is-dismissible"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
					esc_html__( 'ifthenpay: a "Payment Received Notification" process was added so customers hear back once a Multibanco or Payshop reference is actually paid.', 'ifthenpay-payments-for-latepoint' ),
					esc_url( OsRouterHelper::build_link( OsRouterHelper::build_route_name( 'processes', 'index' ) ) ),
					esc_html__( 'Customize it', 'ifthenpay-payments-for-latepoint' )
				);
			}

			if ( get_option( 'ifthenpay_lp_show_process_conflict_notice' ) ) {
				delete_option( 'ifthenpay_lp_show_process_conflict_notice' );
				printf(
					'<div class="notice notice-warning is-dismissible"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
					esc_html__( 'ifthenpay: you already have an automation set up for payment events, so we added our own "Payment Received Notification" without turning it on — enable it in Automation if you\'d rather use it instead of yours.', 'ifthenpay-payments-for-latepoint' ),
					esc_url( OsRouterHelper::build_link( OsRouterHelper::build_route_name( 'processes', 'index' ) ) ),
					esc_html__( 'Review both', 'ifthenpay-payments-for-latepoint' )
				);
			}
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
