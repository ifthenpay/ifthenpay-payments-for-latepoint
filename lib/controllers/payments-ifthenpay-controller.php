<?php
if (! defined('ABSPATH')) exit;

if (! class_exists('OsPaymentsIfthenpayController')) :

    /**
     * Controller for ifthenpay integration.
     *
     * Handles AJAX endpoints for key validation, fetching payment options,
     * updating transaction records, and account activation.
     *
     * @package ifthenpay-payments-for-latepoint
     */
    class OsPaymentsIfthenpayController extends OsController
    {

        public function __construct()
        {
            parent::__construct();

            // Front-end / public AJAX
            $this->action_access['public']  = array_merge($this->action_access['public'], ['get_ifthenpay_options', 'update_payment_repo_by_modal_url']);
            $this->action_access['customer'] = array_merge($this->action_access['customer'], ['get_payment_options']);
        }

        /**
         * Validate Backoffice Key via API and update plugin settings.
         *
         * @return void Sends JSON with status, message, and form HTML.
         */
        public function validate_key()
        {
            $key = sanitize_text_field($this->params['backoffice_key'] ?? '');

            try {
                // Validates the Backoffice Key and Set API client key for subsequent requests
                IfthenpayAPIClient::set_key($key);

                // Fetch payment methods and gateway keys
                $gateways_raw = IfthenpayAPIClient::get_gateway_keys();
                $methods_raw  = IfthenpayAPIClient::get_available_payment_methods();

                // Format API data into usable structures
                $gateway_keys = IfthenpayDataFormatter::format_gateway_keys($gateways_raw);
                $available_methods  = IfthenpayDataFormatter::format_available_payment_methods($methods_raw);

                // Save settings for later use in form population
                OsSettingsHelper::save_setting_by_name('ifthenpay_backoffice_key', $key);
                OsSettingsHelper::save_setting_by_name('ifthenpay_gateway_options', $gateway_keys);
                OsSettingsHelper::save_setting_by_name('ifthenpay_available_methods', $available_methods);

                // Generate HTML form output
                ob_start();
                IfthenpayAdminFormRenderer::render_payments_configuration($gateway_keys, $available_methods);
                IfthenpayAdminFormRenderer::render_others_configuration();
                $html = ob_get_clean();

                $this->send_json([
                    'status' => 'success',
                    'message' => __('Backoffice Key Valid ✅', 'ifthenpay-payments-for-latepoint'),
                    'html' => $html,
                    'inline_data' => [
                        'gateway_options' => $gateway_keys,
                        'gateway_selected' => OsSettingsHelper::get_settings_value('ifthenpay_gateway_key')
                    ]
                ]);
            } catch (Exception $e) {
                // On failure, clear all plugin settings
                $settings_to_clear = [
                    'ifthenpay_backoffice_key',
                    'ifthenpay_gateway_options',
                    'ifthenpay_available_methods',
                    'ifthenpay_gateway_key',
                    "ifthenpay_payment_methods_configuration",
                    'ifthenpay_default_method',
                    'ifthenpay_description',
                ];
                foreach ($settings_to_clear as $setting_key) {
                    OsSettingsHelper::remove_setting_by_name($setting_key);
                }

                $this->send_json([
                    'status'  => LATEPOINT_STATUS_ERROR,
                    'clear'   => true, // instructs frontend to remove dynamic sections
                    'message' => $e->getMessage(),
                ]);
            }
        }

        /**
         * Retrieve payment accounts for a given gateway.
         *
         * @return void Sends JSON with status and account data.
         */
        public function get_payment_accounts_by_gateway()
        {
            $gateway_key    = sanitize_text_field($this->params['gateway_key'] ?? '');
            $backoffice_key = OsSettingsHelper::get_settings_value('ifthenpay_backoffice_key');

            if (! $gateway_key || ! $backoffice_key) {
                $this->send_json([
                    'status'  => LATEPOINT_STATUS_ERROR,
                    'message' => __('Missing required keys.', 'ifthenpay-payments-for-latepoint'),
                ]);
                return;
            }

            try {
                // Configure the API client and fetch the raw accounts list
                IfthenpayAPIClient::set_key($backoffice_key);
                $raw_accounts = IfthenpayAPIClient::get_payment_accounts_by_gateway($gateway_key);

                // Format into [ Entidade => [ Alias => Conta ] ]
                $accounts_by_entity = IfthenpayDataFormatter::format_payment_accounts($raw_accounts);

                $this->send_json([
                    'status' => LATEPOINT_STATUS_SUCCESS,
                    'data'   => $accounts_by_entity,
                ]);
            } catch (Exception $e) {
                $this->send_json([
                    'status'  => LATEPOINT_STATUS_ERROR,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        /**
         * Public endpoint for “ORDER” checkout.
         */
        public function get_order_ifthenpay_options()
        {
            // 1) Bootstrap cart/booking
            OsStepsHelper::set_required_objects($this->params);
            $amount = OsStepsHelper::$cart_object->specs_calculate_amount_to_charge();

            // 2) Create or update the intent
            $booking_url = $this->params['booking_form_page_url'] ?? wp_get_original_referer();
            $order_intent = OsOrderIntentHelper::create_or_update_order_intent(
                OsStepsHelper::$cart_object,
                OsStepsHelper::$restrictions,
                OsStepsHelper::$presets,
                $booking_url
            );

            // 3) Delegate the rest
            $this->send_ifthenpay_options($order_intent, $amount);
        }


        /**
         * Public endpoint for “TRANSACTION” checkout.
         */
        public function get_transaction_ifthenpay_options()
        {
            // 1) Validate & load invoice
            if (! filter_var($this->params['invoice_id'], FILTER_VALIDATE_INT)) {
                wp_send_json_error('Invalid invoice ID');
            }
            $invoice = new OsInvoiceModel($this->params['invoice_id']);

            // 2) Create or update the intent
            $transaction_intent = OsTransactionIntentHelper::create_or_update_transaction_intent(
                $invoice,
                $this->params
            );
            $amount = $transaction_intent->specs_charge_amount;

            // 3) Delegate the rest
            $this->send_ifthenpay_options($transaction_intent, $amount);
        }


        /**
         * Shared core: skip on zero, consume ifthenpay API, persist & respond.
         *
         * @param  object $intent_model  An OrderIntent or TransactionIntent instance.
         * @param  float  $amount  How much to charge.
         */
        private function send_ifthenpay_options($intent_model, $amount)
        {
            // Skip‐payment if free
            if ($amount <= 0) {
                $this->send_json([
                    'status'       => LATEPOINT_STATUS_SUCCESS,
                    'skip_payment' => true,
                    'message'      => __('Nothing to pay', 'ifthenpay-payments-for-latepoint'),
                ]);
                return;
            }

            try {
                // 1) Server Side Token
                $token = $intent_model->intent_key;

                // 2) Build Payload & Generate Pay-by-Link
                $payload    = IfthenpayDataFormatter::build_pay_by_link_payload($intent_model, $token, $amount);
                $api_result = IfthenpayAPIClient::create_pay_by_link(
                    OsSettingsHelper::get_settings_value('ifthenpay_gateway_key'),
                    $payload
                );

                // 3) Persist as PENDING
                IfthenpayPaymentRepository::create(
                    $token,
                    $intent_model->id,
                    $api_result->redirect_url
                );

                // 4) Success JSON
                $this->send_json([
                    'status'        => LATEPOINT_STATUS_SUCCESS,
                    'token'         => $token,
                    'paybylink_url' => esc_url_raw($api_result->redirect_url),
                    'success_url'   => $payload['success_url'],
                    'cancel_url'    => $payload['cancel_url'],
                    'error_url'     => $payload['error_url'],
                ]);
            } catch (Exception $e) {
                $this->send_json([
                    'status'  => LATEPOINT_STATUS_ERROR,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        /**
         * Handle overlay callback and update payment status.
         *
         * @return void Sends JSON with status and message.
         */
        public function update_payment_repo_by_modal_url()
        {
            // Pull callback params
            $type  = $this->params['ifthenpay_return'];
            $txid  = $this->params['txid'];
            $token = $this->params['payment_token'];

            try {
                // Success path: verify then mark PAID
                if ($type === 'success' && $this->verifyPaymentWithRetry($txid)) {
                    IfthenpayPaymentRepository::update_transaction_id($token, $txid);
                    IfthenpayPaymentRepository::update_status($token, 'PAID');
                    $this->send_json([
                        'status'  => LATEPOINT_STATUS_SUCCESS,
                        'message' => __('Payment completed', 'ifthenpay-payments-for-latepoint'),
                    ]);
                }
                // Cancelled by user: mark CANCELLED and return error
                elseif ($type === 'cancel') {
                    IfthenpayPaymentRepository::update_status($token, 'CANCELLED');
                    $this->send_json([
                        'status'  => LATEPOINT_STATUS_ERROR,
                        'message' => __('Payment cancelled', 'ifthenpay-payments-for-latepoint'),
                    ]);
                }
                // All other cases (error or failed payment verification): mark FAILED and return error
                else {
                    IfthenpayPaymentRepository::update_status($token, 'FAILED');
                    IfthenpayPaymentRepository::update_transaction_id($token, $txid);
                    $this->send_json([
                        'status'  => LATEPOINT_STATUS_ERROR,
                        'message' => __('Payment failed due to payment verification error', 'ifthenpay-payments-for-latepoint'),
                    ]);
                }
            } catch (Exception $e) {
                // Exception fallback
                $this->send_json([
                    'status'  => LATEPOINT_STATUS_ERROR,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        /**
         * Retry payment verification until success or timeout.
         *
         * @param string $txid    Transaction ID.
         * @param int    $timeout Max seconds to retry.
         * @param int    $interval Seconds between attempts.
         * @return bool True if verified.
         */
        private function verifyPaymentWithRetry(string $txid, int $timeout = 10, int $interval = 3): bool
        {
            $deadline = time() + $timeout;
            do {
                if (IfthenpayAPIClient::get_payment_status_by_transaction_id($txid)) {
                    return true;
                }
                sleep($interval);
            } while (time() < $deadline);

            return false;
        }

        /**
         * Send activation payment method email to ifthenpay Heldesk.
         *
         * @return void Sends JSON with status and message.
         */
        public function activate_account_by_entity()
        {
            $wp_user = wp_get_current_user();

            $payload = [
                'gateway_key'      => sanitize_text_field($this->params['gateway_key']),
                'entity'           => sanitize_text_field($this->params['entity']),
                'backoffice_key'   => OsSettingsHelper::get_settings_value('ifthenpay_backoffice_key'),
                'customer_email'   => $wp_user->data->user_email,
                'site_url'         => home_url('/'),
                'site_name'        => get_bloginfo('name'),
                'wp_version'       => get_bloginfo('version'),
                'latepoint_version' => LATEPOINT_VERSION,
                'plugin_version'   => IFTHENPAY_PLUGIN_VERSION,
            ];

            $sent = IfthenpayEmailHelper::send_activation_email($payload);

            if ($sent) {
                $this->send_json([
                    'status'  => LATEPOINT_STATUS_SUCCESS,
                    'message' => __('Your activation request has been sent to support.', 'ifthenpay-payments-for-latepoint')
                ]);
            } else {
                $this->send_json([
                    'status'  => LATEPOINT_STATUS_ERROR,
                    'message' => __('Failed to send the activation email. Please try again later.', 'ifthenpay-payments-for-latepoint'),
                ]);
            }
        }
    }

endif;
