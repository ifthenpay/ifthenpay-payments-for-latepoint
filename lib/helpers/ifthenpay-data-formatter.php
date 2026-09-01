<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IfthenpayDataFormatter {


	/**
	 * Formats the available payment methods array from the ifthenpay API.
	 *
	 * Each entry is indexed by the lowercase method name, and contains its
	 * position, image, and description/tooltip.
	 *
	 * @param array $raw Raw data array returned from get_available_payment_methods().
	 * @return array Formatted array with cleaned method information.
	 */
	public static function format_available_payment_methods( array $raw ): array {
		$methods = array();

		foreach ( $raw as $entry ) {
			$method_key = $entry['Entity'] ?? '';
			if ( ! $method_key ) {
				continue;
			}

			// Filter out invisible methods
			if ( ! ( $entry['IsVisible'] ?? true ) ) {
				continue;
			}

			$methods[ $method_key ] = array(
				'position' => (int) ( $entry['Position'] ?? 0 ),
				'image'    => $entry['SmallImageUrl'] ?? '',
				'tooltip'  => $entry['DescriptionEN'] ?? '',
				'label'    => $entry['Method'] ?? '',
			);
		}

		// Sort by position ascending
		uasort( $methods, fn( $a, $b ) => $a['position'] <=> $b['position'] );

		return $methods;
	}

	/**
	 * Build payload for Pay-by-Link endpoint.
	 *
	 * @param OsOrderIntentModel $intent
	 * @param string             $token
	 * @return array
	 */
	public static function build_pay_by_link_payload( $intent, $token, $amount ) {
		// Basic fields
		$payload = array(
			'id'              => $token,
			'amount'          => self::format_amount( $amount ),
			'description'     => self::build_description( $intent ),
			'lang'            => self::get_language(),
			'accounts'        => self::build_accounts_string(),
			'selected_method' => self::get_selected_method(),
		);

		// Return URLs embedding token
		$base                   = home_url( '/' );
		$payload['success_url'] = add_query_arg(
			array(
				'ifthenpay_return' => 'success',
				'token'            => $token,
				'txid'             => '[TRANSACTIONID]',
			),
			$base
		);
		$payload['cancel_url']  = add_query_arg(
			array(
				'ifthenpay_return' => 'cancel',
				'token'            => $token,
				'txid'             => '[TRANSACTIONID]',
			),
			$base
		);
		$payload['error_url']   = add_query_arg(
			array(
				'ifthenpay_return' => 'error',
				'token'            => $token,
				'txid'             => '[TRANSACTIONID]',
			),
			$base
		);

		return $payload;
	}

	/**
	 * Format amount to two-decimal string.
	 */
	private static function format_amount( $raw ): string {
		return number_format( $raw, 2, '.', '' );
	}

	/**
	 * Build the description as "Order #{id} - {admin description}".
	 */
	private static function build_description( $intent ): string {
		$admin_desc = OsSettingsHelper::get_settings_value( 'ifthenpay_description', '' );
		return sprintf(
			/* translators: %1$s: order id, %2$s: admin description */
			__( 'Order #%1$s - %2$s', 'ifthenpay-payments-for-latepoint' ),
			$intent->id,
			$admin_desc
		);
	}

	/**
	 * Default to 'pt', accept en/es/fr.
	 */
	private static function get_language(): string {
		$lang = substr( get_locale(), 0, 2 );
		return in_array( $lang, array( 'pt', 'en', 'es', 'fr' ), true ) ? $lang : 'pt';
	}

	/**
	 * Serialize enabled methods into "METHOD|account;METHOD|account" format, the shape Pay By
	 * Link's own `accounts` field expects. The settings page stores only which methods are
	 * enabled, not their account keys — those come from the same live gateway dataset the
	 * settings page itself reads, matched here against the saved Gateway Key.
	 */
	private static function build_accounts_string(): string {
		$saved           = (array) OsSettingsHelper::get_settings_value( 'ifthenpay_payment_methods_configuration', array() );
		$enabled_methods = array_values( array_filter( $saved, 'is_string' ) );
		$gateway_key     = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key', '' );
		$backoffice_key  = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_backoffice_key', '' );

		if ( array() === $enabled_methods || '' === $gateway_key || '' === $backoffice_key ) {
			return '';
		}

		$dataset              = IfthenpayLpGatewayDataset::get( $backoffice_key );
		$accounts_for_gateway = null === $dataset ? array() : ( $dataset['accounts'][ $gateway_key ] ?? array() );

		$parts = array();
		foreach ( $enabled_methods as $method_code ) {
			if ( isset( $accounts_for_gateway[ $method_code ] ) ) {
				$parts[] = $method_code . '|' . $accounts_for_gateway[ $method_code ];
			}
		}

		return implode( ';', $parts );
	}

	/**
	 * The saved default method's position in the live method catalog, the shape Pay By Link's
	 * own `selected_method` field expects.
	 */
	private static function get_selected_method(): string {
		$default = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_default_method', '' );
		if ( '' === $default ) {
			return '';
		}

		$catalog = IfthenpayLpMethodCatalog::get();

		return isset( $catalog[ $default ]['position'] ) ? (string) $catalog[ $default ]['position'] : '';
	}
}
