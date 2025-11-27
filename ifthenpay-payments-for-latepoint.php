<?php

/**
 * Plugin Name:         ifthenpay | Payments for LatePoint
 * Plugin URI:          https://github.com/ifthenpay/ifthenpay-payments-for-latepoint
 * Description:         LatePoint addon for payments with ifthenpay
 * Version:             2.0.3
 * Requires at least:   6.5
 * Requires PHP:        7.4
 * Author:              ifthenpay
 * Author URI:          https://ifthenpay.com/
 * License:             GPL v3
 * License URI:         https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:         ifthenpay-payments-for-latepoint
 * Domain Path:         /languages
 * Requires Plugins:    latepoint
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// If no LatePoint class exists - exit, because LatePoint plugin is required for this addon
if (! class_exists('IfthenpayPaymentsForLatepoint')) :

    /**
     * Main Addon Class.
     *
     */
    class IfthenpayPaymentsForLatepoint
    {

        /**
         * Addon version.
         *
         */
        public $version = '2.0.3';
        public $db_version = '2.0.0';
        public $addon_name = 'ifthenpay-payments-for-latepoint';

        public $processor_code = 'ifthenpay';

        /**
         * LatePoint Constructor.
         */
        public function __construct()
        {
            $this->define_constants();
            $this->init_hooks();
        }

        /**
         * Define LatePoint Constants.
         */
        public function define_constants()
        {
            $this->define('IFTHENPAY_PLUGIN_VERSION', $this->version);
            $this->define('IFTHENPAY_TABLE_VERSION', $this->db_version);
        }

        public static function public_stylesheets()
        {
            return plugin_dir_url(__FILE__) . 'public/stylesheets/';
        }

        public static function public_javascripts()
        {
            return plugin_dir_url(__FILE__) . 'public/javascripts/';
        }

        public static function images_url()
        {
            return plugin_dir_url(__FILE__) . 'public/images/';
        }

        /**
         * Define constant if not already set.
         *
         */
        public function define($name, $value)
        {
            if (! defined($name)) {
                define($name, $value);
            }
        }

        /**
         * Include required core files used in admin and on the frontend.
         */
        public function includes()
        {
            // CONTROLLERS
            include_once(dirname(__FILE__) . '/lib/controllers/payments-ifthenpay-controller.php');

            // HELPERS
            include_once(dirname(__FILE__) . '/lib/helpers/ifthenpay-api-client.php');
            include_once(dirname(__FILE__) . '/lib/helpers/ifthenpay-data-formatter.php');
            include_once(dirname(__FILE__) . '/lib/helpers/ifthenpay-payments-repository.php');
            include_once(dirname(__FILE__) . '/lib/helpers/ifthenpay-email-helper.php');

            // VIEWS (renderers)
            include_once(dirname(__FILE__) . '/lib/views/ifthenpay-admin-form-renderer.php');

            // MODELS
            // include_once(dirname( __FILE__ ) . '/lib/models/example_model.php' );
        }

        public function init_hooks()
        {
            // Hook into the latepoint initialization action and initialize this addon
            add_action('latepoint_init', [$this, 'latepoint_init']);

            // Include additional helpers and controllers 
            add_action('latepoint_includes', [$this, 'includes']);

            // Modify a list of installed add-ons
            add_filter('latepoint_installed_addons', [$this, 'register_addon']);

            // Include JS and CSS for the admin panel
            add_action('latepoint_admin_enqueue_scripts', [$this, 'load_admin_scripts_and_styles']);
            add_filter('latepoint_localized_vars_admin', [$this, 'localized_vars_for_admin']);

            // Include JS and CSS for the frontend site
            add_action('latepoint_wp_enqueue_scripts', [$this, 'load_front_scripts_and_styles']);
            add_filter('latepoint_localized_vars_front', [$this, 'localized_vars_for_front']);

            // Add the scripts to the clean layout 
            add_filter('latepoint_clean_layout_js_files', [$this, 'add_scripts_to_clean_layout'], 10);
            add_filter('latepoint_clean_layout_css_files', [$this, 'add_styles_to_clean_layout'], 10);

            // Register ifthenpay as a payment processor
            add_filter('latepoint_payment_processors', [$this, 'register_payment_processor'], 10, 2);
            // Register ifthenpay available payment methods
            add_filter('latepoint_all_payment_methods', [$this, 'register_payment_methods']);
            // Add payment methods to a list of enabled methods for the front-end, if processor is turned on in settings
            add_filter('latepoint_enabled_payment_methods', [$this, 'register_enabled_payment_methods']);
            // Add settings fields for the payment processor
            add_action('latepoint_payment_processor_settings', [$this, 'add_settings_fields'], 10);
            // Encrypt sensitive fields
            add_filter('latepoint_encrypted_settings', [$this, 'add_encrypted_settings']);;

            add_filter('latepoint_get_all_payment_times', [$this, 'add_all_payment_methods_to_payment_times']);
            add_filter('latepoint_get_enabled_payment_times', [$this, 'add_enabled_payment_methods_to_payment_times']);

            add_filter('latepoint_process_payment_for_order_intent', [$this, 'process_payments_for_order_intent'], 10, 2);
            add_filter('latepoint_process_payment_for_transaction_intent', [$this, 'process_payment_for_transaction_intent'], 10, 2);

            // init the addon
            add_action('init', array($this, 'init'), 0);

            register_activation_hook(__FILE__, [$this, 'on_activate']);
            register_deactivation_hook(__FILE__, [$this, 'on_deactivate']);
        }

        public function process_payments_for_order_intent(array $result, OsOrderIntentModel $order_intent): array
        {
            if (
                ! OsPaymentsHelper::should_processor_handle_payment_for_order_intent($this->processor_code, $order_intent)
                || $order_intent->get_payment_data_value('method') !== 'ifthenpay_gateway'
            ) {
                return $result;
            }
            return $this->process_payment_by_intent($order_intent);
        }

        public function process_payment_for_transaction_intent(array $result, OsTransactionIntentModel $transaction_intent): array
        {
            if (
                ! OsPaymentsHelper::should_processor_handle_payment_for_transaction_intent($this->processor_code, $transaction_intent)
                || $transaction_intent->get_payment_data_value('method') !== 'ifthenpay_gateway'
            ) {
                return $result;
            }
            return $this->process_payment_by_intent($transaction_intent);
        }

        /**
         * Shared intent‐processing logic for both ORDER and TRANSACTION.
         *
         * @param OsOrderIntentModel|OsTransactionIntentModel $intent
         * @return array
         */
        private function process_payment_by_intent($intent_model): array
        {
            // 1) Token must exist
            $txid = $intent_model->get_payment_data_value('token');
            if (!$txid) {
                $msg = __('Missing payment token', 'ifthenpay-payments-for-latepoint');
                $intent_model->add_error('payment_error', $msg);
                return [
                    'status'  => LATEPOINT_STATUS_ERROR,
                    'message' => $msg,
                ];
            }

            // 2) Record must exist
            $payment = IfthenpayPaymentRepository::get_by_transaction_id($txid);
            if (!$payment) {
                $msg = __('Payment record not found', 'ifthenpay-payments-for-latepoint');
                $intent_model->add_error('payment_error', $msg);
                return [
                    'status'  => LATEPOINT_STATUS_ERROR,
                    'message' => $msg,
                ];
            }

            // 3) Handle statuses
            if ($payment->status === 'PAID') {
                return [
                    'status'    => LATEPOINT_STATUS_SUCCESS,
                    'processor' => $this->processor_code,
                    'charge_id' => $payment->transaction_id,
                    'kind'      => LATEPOINT_TRANSACTION_KIND_CAPTURE,
                ];
            }

            // CANCELLED or FAILED
            $msg = $payment->status === 'CANCELLED'
                ? __('Payment was cancelled', 'ifthenpay-payments-for-latepoint')
                : __('Payment failed', 'ifthenpay-payments-for-latepoint');

            $intent_model->add_error('payment_error', $msg);
            return [
                'status'  => LATEPOINT_STATUS_ERROR,
                'message' => $msg,
            ];
        }

        public function add_all_payment_methods_to_payment_times(array $payment_times): array
        {
            $payment_methods = $this->get_supported_payment_methods();
            foreach ($payment_methods as $payment_method_code => $payment_method_info) {
                $payment_times[LATEPOINT_PAYMENT_TIME_NOW][$payment_method_code][$this->processor_code] = $payment_method_info;
            }

            return $payment_times;
        }

        public function add_enabled_payment_methods_to_payment_times(array $payment_times): array
        {
            if (OsPaymentsHelper::is_payment_processor_enabled($this->processor_code)) {
                $payment_times = $this->add_all_payment_methods_to_payment_times($payment_times);
            }

            return $payment_times;
        }

        public function add_encrypted_settings($encrypted_settings)
        {
            $encrypted_settings[] = 'ifthenpay_backoffice_key';
            return $encrypted_settings;
        }

        public function localized_vars_for_admin($localized_vars)
        {
            $localized_vars['ifthenpay_validate_key_route'] = OsRouterHelper::build_route_name('payments_ifthenpay', 'validate_key');
            $localized_vars['ifthenpay_get_accounts_route'] = OsRouterHelper::build_route_name('payments_ifthenpay', 'get_payment_accounts_by_gateway');
            $localized_vars['ifthenpay_activate_account_route'] = OsRouterHelper::build_route_name('payments_ifthenpay', 'activate_account_by_entity');

            $localized_vars['ifthenpay_gateway_options'] = OsSettingsHelper::get_settings_value('ifthenpay_gateway_options', []);
            $localized_vars['ifthenpay_gateway_selected'] = OsSettingsHelper::get_settings_value('ifthenpay_gateway_key', '');

            $localized_vars['ifthenpay_translations'] = [
                'loading' => __('⏳ Loading…', 'ifthenpay-payments-for-latepoint'),
                'no_accounts' => __('No accounts.', 'ifthenpay-payments-for-latepoint'),
                'activate'  => __('Activate', 'ifthenpay-payments-for-latepoint'),
                'warning_default_method'  => __('⚠️ Select at least one Payment Method', 'ifthenpay-payments-for-latepoint'),
            ];

            return $localized_vars;
        }

        public function localized_vars_for_front($localized_vars)
        {
            if (OsPaymentsHelper::is_payment_processor_enabled($this->processor_code)) {
                $localized_vars['is_ifthenpay_active']           = true;
                $localized_vars['ifthenpay_order_payment_options_route'] = OsRouterHelper::build_route_name('payments_ifthenpay', 'get_order_ifthenpay_options');
                $localized_vars['ifthenpay_transaction_payment_options_route'] = OsRouterHelper::build_route_name('payments_ifthenpay', 'get_transaction_ifthenpay_options');
                $localized_vars['ifthenpay_check_status_route'] = OsRouterHelper::build_route_name('payments_ifthenpay', 'update_payment_repo_by_modal_url');

                $localized_vars['ifthenpay_translations'] = [
                    'warning' => __('⚠️ Please do not close this window until your payment completes. You’ll be redirected to the store page automatically.', 'ifthenpay-payments-for-latepoint'),
                ];
            } else {
                $localized_vars['is_ifthenpay_active'] = false;
            }
            return $localized_vars;
        }

        // Payment method for the processor
        public function get_supported_payment_methods()
        {
            return [
                'ifthenpay_gateway' => [
                    'name' => 'ifthenpay Gateway',
                    'label' => 'ifthenpay Gateway',
                    'image_url' => $this->images_url() . 'ifthenpay_simbolo.png',
                    'code' => 'ifthenpay_checkout',
                    'time_type' => 'later'
                ]
            ];
        }

        // Register payment processor
        public function register_payment_processor($payment_processors)
        {
            $payment_processors[$this->processor_code] = [
                'code' => $this->processor_code,
                'name' => __('ifthenpay', 'ifthenpay-payments-for-latepoint'),
                'image_url' => $this->images_url() . 'processor-logo.png'
            ];
            return $payment_processors;
        }

        // Adds payment method to payment settings
        public function register_payment_methods($payment_methods)
        {
            $payment_methods = array_merge($payment_methods, $this->get_supported_payment_methods());
            return $payment_methods;
        }

        // Enables payment methods if the processor is turned on
        public function register_enabled_payment_methods($enabled_payment_methods)
        {
            // check if payment processor is enabled in settings
            if (OsPaymentsHelper::is_payment_processor_enabled($this->processor_code)) {
                $enabled_payment_methods = array_merge($enabled_payment_methods, $this->get_supported_payment_methods());
            }
            return $enabled_payment_methods;
        }

        public function add_settings_fields($processor_code)
        {
            if ($processor_code !== $this->processor_code) {
                return;
            }

            // Always render Backoffice
            $backoffice_key = OsSettingsHelper::get_settings_value('ifthenpay_backoffice_key');
            IfthenpayAdminFormRenderer::render_backoffice_configuration($backoffice_key);

            if ($backoffice_key) {
                // Load the two needed settings
                $gateway_options   = OsSettingsHelper::get_settings_value('ifthenpay_gateway_options', []);
                $available_methods = OsSettingsHelper::get_settings_value('ifthenpay_available_methods', []);

                // Render payments section (internally decodes the JSON config)
                IfthenpayAdminFormRenderer::render_payments_configuration(
                    $gateway_options,
                    $available_methods
                );

                // Other configuration section
                IfthenpayAdminFormRenderer::render_others_configuration();
            }
        }

        // Loads addon specific javascript and stylesheets for frontend site
        public function load_front_scripts_and_styles()
        {
            // Stylesheets
            wp_enqueue_style('ifthenpay-payments-for-latepoint-front', $this->public_stylesheets() . 'ifthenpay-payments-for-latepoint-front.css', false, $this->version);

            // Javascripts
            wp_enqueue_script('ifthenpay-payments-for-latepoint-front',  $this->public_javascripts() . 'ifthenpay-payments-for-latepoint-front.js', array('jquery'), true, $this->version);
        }

        // Loads addon specific javascript and stylesheets for backend (wp-admin)
        public function load_admin_scripts_and_styles($localized_vars)
        {
            // Stylesheets
            wp_enqueue_style('ifthenpay-payments-for-latepoint', $this->public_stylesheets() . 'ifthenpay-payments-for-latepoint-admin.css', false, $this->version);

            // Javascripts
            wp_enqueue_script('ifthenpay-payments-for-latepoint',  $this->public_javascripts() . 'ifthenpay-payments-for-latepoint-admin.js', array('jquery'), true, $this->version);
        }

        // Add scripts to the clean layout.
        public function add_scripts_to_clean_layout(array $js_files): array
        {
            $js_files[] = 'ifthenpay-payments-for-latepoint-front';

            return $js_files;
        }

        // Add styles to the clean layout.
        public function add_styles_to_clean_layout(array $css_files): array
        {
            $css_files[] = 'ifthenpay-payments-for-latepoint-front';

            return $css_files;
        }

        /**
         * Init addon when WordPress Initialises.
         */
        public function init()
        {
            // Set up localisation.
        }

        public function latepoint_init()
        {
            LatePoint\Cerber\Router::init_addon();
        }

        public function on_deactivate() {}

        public function on_activate()
        {
            do_action('latepoint_on_addon_activate', $this->addon_name, $this->version);

            // Create the ifthenpay payments table
            if (!class_exists('IfthenpayPaymentRepository')) {
                require_once(dirname(__FILE__) . '/lib/helpers/ifthenpay-payments-repository.php');
            }
            IfthenpayPaymentRepository::create_table();

            // Optional: save version to maintain manual control
            update_option('latepoint-payments-ifthenpay_addon_db_version', $this->db_version);
        }

        public function register_addon($installed_addons)
        {
            $installed_addons[] = ['name' => $this->addon_name, 'db_version' => $this->db_version, 'version' => $this->version];
            return $installed_addons;
        }
    }

endif;

if (in_array('latepoint/latepoint.php', get_option('active_plugins', array()))  || array_key_exists('latepoint/latepoint.php', get_site_option('active_sitewide_plugins', array()))) {
    $LATEPOINT_ADDON_PAYMENTS_IFTHENPAY = new IfthenpayPaymentsForLatepoint();
}
