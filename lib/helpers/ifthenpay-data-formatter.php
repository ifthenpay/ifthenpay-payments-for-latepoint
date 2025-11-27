<?php
if (!defined('ABSPATH')) exit;

class IfthenpayDataFormatter
{

    /**
     * Formats the list of gateway keys into a flat associative array.
     *
     * @param array $raw Raw list of gateways from the API.
     * @return array Associative array [ Alias => GatewayKey ].
     */
    public static function format_gateway_keys(array $raw): array
    {
        return array_column($raw, 'GatewayKey', 'Alias');
    }

    /**
     * Formats the available payment methods array from the ifthenpay API.
     *
     * Each entry is indexed by the lowercase method name, and contains its
     * position, image, and description/tooltip.
     *
     * @param array $raw Raw data array returned from get_available_payment_methods().
     * @return array Formatted array with cleaned method information.
     */
    public static function format_available_payment_methods(array $raw): array
    {
        $methods = [];

        foreach ($raw as $entry) {
            $method_key = $entry['Entity'] ?? '';
            if (!$method_key) continue;

            $methods[$method_key] = [
                'position' => (int) ($entry['Position'] ?? 0),
                'image'    => $entry['SmallImageUrl'] ?? '',
                'tooltip'  => $entry['DescriptionEN'] ?? '',
                'label'   => $entry['Method'] ?? ''
            ];
        }

        // Sort by position ascending
        uasort($methods, fn($a, $b) => $a['position'] <=> $b['position']);

        return $methods;
    }

    /**
     * Turn a flat list of account records into a map of
     * Entidade => [ Alias => Conta ] for dropdowns.
     *
     * Numeric Entidade values become "MB".
     *
     * @param array<int,array{Alias:string,Conta:string,Entidade:string,SubEntidade:string}> $accounts
     * @return array<string,array<string,string>>
     */
    public static function format_payment_accounts(array $accounts): array
    {
        $result = [];

        foreach ($accounts as $acct) {
            if (empty($acct['Alias']) || empty($acct['Conta'])) {
                continue;
            }

            // numeric Entidade → MB
            $ent = is_numeric($acct['Entidade']) ? 'MB' : $acct['Entidade'];

            // initialize bucket if first time
            if (! isset($result[$ent])) {
                $result[$ent] = [];
            }

            // map alias => conta
            $result[$ent][$acct['Alias']] = $acct['Conta'];
        }

        return $result;
    }

    /**
     * Build payload for Pay-by-Link endpoint.
     *
     * @param OsOrderIntentModel $intent
     * @param string             $token
     * @return array
     */
    public static function build_pay_by_link_payload($intent, $token, $amount)
    {
        // Basic fields
        $payload = [
            'id'              => $token,
            'amount'          => self::format_amount($amount),
            'description'     => self::build_description($intent),
            'lang'            => self::get_language(),
            // 'expiredate'      => self::get_expire_date(),
            'accounts'        => self::build_accounts_string(),
            'selected_method' => self::get_selected_method(),
            // 'btnCloseUrl'     => home_url('/'),
            // 'btnCloseLabel'   => OsSettingsHelper::get_settings_value('ifthenpay_gateway_close_text', __('Close', 'ifthenpay-payments-for-latepoint')),
        ];

        // Return URLs embedding token
        $base = home_url('/');
        $payload['success_url'] = add_query_arg(['ifthenpay_return' => 'success', 'token' => $token, 'txid' => "[TRANSACTIONID]"], $base);
        $payload['cancel_url']  = add_query_arg(['ifthenpay_return' => 'cancel', 'token' => $token, 'txid' => "[TRANSACTIONID]"], $base);
        $payload['error_url']   = add_query_arg(['ifthenpay_return' => 'error', 'token' => $token, 'txid' => "[TRANSACTIONID]"], $base);

        return $payload;
    }

    /**
     * Format amount to two-decimal string.
     */
    private static function format_amount($raw): string
    {
        return number_format($raw, 2, '.', '');
    }

    /**
     * Build the description as "Order #{id} - {admin description}".
     */
    private static function build_description($intent): string
    {
        $admin_desc = OsSettingsHelper::get_settings_value('ifthenpay_description', '');
        return sprintf(
            /* translators: %1$s: order id, %2$s: admin description */
            __('Order #%1$s - %2$s', 'ifthenpay-payments-for-latepoint'),
            $intent->id,
            $admin_desc
        );
    }

    /**
     * Default to 'pt', accept en/es/fr.
     */
    private static function get_language(): string
    {
        $lang = substr(get_locale(), 0, 2);
        return in_array($lang, ['pt', 'en', 'es', 'fr'], true) ? $lang : 'pt';
    }

    /**
     * Compute expire date based on admin 'ifthenpay_deadline'.
     */
    private static function get_expire_date(): string
    {
        $days = (int) OsSettingsHelper::get_settings_value('ifthenpay_deadline');
        return gmdate('Ymd', strtotime("+{$days} days"));
    }

    /**
     * Serialize checked accounts into "A|B;C|D" format.
     */
    private static function build_accounts_string(): string
    {
        // 1. Pull the raw setting (might be JSON)
        $raw = OsSettingsHelper::get_settings_value(
            'ifthenpay_payment_methods_configuration',
            []
        );

        // 2. Decode if it’s a JSON string
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $config  = is_array($decoded) ? $decoded : [];
        } else {
            $config = (array) $raw;
        }

        // 3. Filter only checked + non‐empty accounts
        $parts = [];
        foreach ($config as $method_code => $entry) {
            if (
                is_array($entry)
                && ! empty($entry['checked'])
                && ! empty($entry['selected_account'])
            ) {
                // Collapse whitespace around the "|" and trim
                $parts[] = preg_replace('/\s*\|\s*/', '|', trim($entry['selected_account']));
            }
        }

        // 4. Join with semicolons
        return implode(';', $parts);
    }

    /**
     * Determine selected method position from default and available methods.
     *
     */
    private static function get_selected_method(): string
    {
        $default = OsSettingsHelper::get_settings_value('ifthenpay_default_method', '');
        $raw     = OsSettingsHelper::get_settings_value('ifthenpay_available_methods', []);
        $available = is_string($raw) ? maybe_unserialize($raw) : (array) $raw;
        if (isset($available[$default]['position'])) {
            return (string) $available[$default]['position'];
        }
        return '';
    }
}
