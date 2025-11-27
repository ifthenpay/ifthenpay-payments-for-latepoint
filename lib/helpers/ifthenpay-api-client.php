<?php
if (!defined('ABSPATH')) exit;

class IfthenpayAPIClient
{
    // Public API base and endpoints
    const BASE_API_PUBLIC              = 'https://api.ifthenpay.com';
    const ENDPOINT_AVAILABLE_METHODS   = '/gateway/methods/available';
    const ENDPOINT_PAY_BY_LINK        = '/gateway/pinpay';
    const ENDPOINT_TRANSACTION_STATUS = '/gateway/transaction/status';

    // Multibanco entities/subentities (single URL constant)
    const ENTITIES_SUBENTIDADES_URL    = 'https://www.ifthenpay.com/IfmbWS/ifmbws.asmx/getEntidadeSubentidadeJsonV2';

    // Mobile gateway endpoints
    const BASE_API_MOBILE             = 'https://ifthenpay.com/IfmbWS/ifthenpaymobile.asmx';
    const ENDPOINT_GATEWAY_KEYS       = '/GetGatewayKeys';
    const ENDPOINT_ACCOUNTS_BY_GATEWAY = '/GetAccountsByGatewayKey';

    /** @var string Backoffice key */
    private static $key;

    /**
     * Store and verify the Backoffice key (must have at least one valid Entidade + SubEntidade).
     * @param string $key
     * @throws Exception
     */
    public static function set_key(string $key): void
    {
        self::$key = sanitize_text_field($key);
        self::validate_key_or_fail();
    }

    /**
     * @throws Exception If the key is malformed or not recognised by the remote API.
     */
    private static function validate_key_or_fail(): void
    {
        // 1. Sanitize and normalize
        $key = sanitize_text_field(self::$key);

        // 2. Server-side format check: 1234-5678-9012-3456
        $pattern = '/^\d{4}(?:-\d{4}){3}$/';
        if (! preg_match($pattern, $key)) {
            throw new Exception(
                esc_html__('Invalid Backoffice Key format. Expected 1234-5678-9012-3456.', 'ifthenpay-payments-for-latepoint')
            );
        }

        // 3. Fetch entities/sub-entities from the external service
        $endpoint = sprintf(
            '%s?chavebackoffice=%s',
            self::ENTITIES_SUBENTIDADES_URL,
            urlencode($key)
        );
        $response = self::get($endpoint);

        if (! is_array($response) || empty($response)) {
            throw new Exception(
                esc_html__('Unexpected response when validating Backoffice Key.', 'ifthenpay-payments-for-latepoint')
            );
        }

        // 4. Look for at least one valid Entidade + SubEntidade
        foreach ($response as $item) {
            $has_entity   = ! empty($item['Entidade']);
            $has_subents  = ! empty($item['SubEntidade']) && is_array($item['SubEntidade']);

            if ($has_entity && $has_subents) {
                return; // ✅ key is valid
            }
        }

        // 5. If we fall through, nothing matched
        throw new Exception(
            esc_html__('Backoffice Key not recognized or has no entities. Please contact support.', 'ifthenpay-payments-for-latepoint')
        );
    }

    /**
     * Retrieves the Gateway Keys associated with the Backoffice Key.
     * @return array
     */
    public static function get_gateway_keys(): array
    {
        $url = self::BASE_API_MOBILE
            . self::ENDPOINT_GATEWAY_KEYS
            . '?backofficekey=' . urlencode(self::$key);
        $response = self::get($url);

        if (!is_array($response) || empty($response)) {
            throw new Exception(
                esc_html__('No Gateway Keys found for this Backoffice Key. Please contact ifthenpay to activate a gateway.', 'ifthenpay-payments-for-latepoint')
            );
        }

        return $response;
    }

    /**
     * Retrieves available payment accounts by gateway key.
     * @param string $gateway_key
     * @return array
     */
    public static function get_payment_accounts_by_gateway(string $gateway_key): array
    {
        $url = self::BASE_API_MOBILE
            . self::ENDPOINT_ACCOUNTS_BY_GATEWAY
            . '?backofficekey=' . urlencode(self::$key)
            . '&gatewayKey=' . urlencode($gateway_key);

        return self::get($url);
    }

    /**
     * Retrieves all globally available payment methods.
     * @return array
     */
    public static function get_available_payment_methods(): array
    {
        $url = self::BASE_API_PUBLIC
            . self::ENDPOINT_AVAILABLE_METHODS;

        return self::get($url);
    }

    /**
     * Create a “Pay by Link” on ifthenpay.
     * @param string $gateway_key
     * @param array  $payload
     * @return object { pin_code, pinpay_url, redirect_url }
     * @throws Exception
     */
    public static function create_pay_by_link(string $gateway_key, array $payload)
    {
        $url = rtrim(self::BASE_API_PUBLIC, '/')
            . self::ENDPOINT_PAY_BY_LINK
            . '/' . rawurlencode($gateway_key);

        $response = self::post($url, $payload);

        if (empty($response['PinCode']) || empty($response['PinpayUrl']) || empty($response['RedirectUrl'])) {
            throw new Exception(esc_html__('Invalid response from ifthenpay Pay-by-Link API.', 'ifthenpay-payments-for-latepoint'));
        }

        return (object) [
            'pin_code'     => $response['PinCode'],
            'pinpay_url'   => $response['PinpayUrl'],
            'redirect_url' => $response['RedirectUrl'],
        ];
    }

    /**
     * Get payment status by Transaction ID.
     * @param string $transaction_id
     * @return bool
     */
    public static function get_payment_status_by_transaction_id(string $transaction_id): bool
    {
        $url = rtrim(self::BASE_API_PUBLIC, '/')
            . self::ENDPOINT_TRANSACTION_STATUS
            . '?transactionId=' . urlencode($transaction_id);

        return (bool) self::get($url);
    }

    /**
     * GET request helper.
     * @param string $url
     * @return mixed
     * @throws Exception
     */
    private static function get(string $url)
    {
        $response = wp_remote_get($url, ['timeout' => 10]);
        if (is_wp_error($response)) {
            throw new Exception(esc_html($response->get_error_message()));
        }

        $body    = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if (!is_array($decoded) && !is_bool($decoded)) {
            throw new Exception(esc_html__('Invalid response (GET) from ifthenpay API.', 'ifthenpay-payments-for-latepoint'));
        }

        return $decoded;
    }

    /**
     * POST request helper.
     * @throws Exception
     */
    private static function post(string $url, array $data): array
    {
        $args = [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($data),
            'timeout' => 10,
        ];

        $resp = wp_remote_post($url, $args);
        if (is_wp_error($resp)) {
            throw new Exception(esc_html($resp->get_error_message()));
        }

        $body    = wp_remote_retrieve_body($resp);
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new Exception(esc_html__('Invalid JSON from ifthenpay API.', 'ifthenpay-payments-for-latepoint'));
        }

        return $decoded;
    }
}
