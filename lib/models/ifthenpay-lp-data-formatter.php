<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IfthenpayLpDataFormatter {

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

		uasort( $methods, fn( $a, $b ) => $a['position'] <=> $b['position'] );

		return $methods;
	}

	/**
	 * Build payload for Pay-by-Link endpoint.
	 *
	 * @param OsOrderIntentModel|OsTransactionIntentModel $intent
	 * @param string                                      $token
	 * @return array
	 */
	public static function build_pay_by_link_payload( $intent, $token, $amount ) {
		$payload = array(
			'id'              => $token,
			'amount'          => self::format_amount( $amount ),
			'description'     => self::build_description( $intent ),
			'lang'            => self::get_language(),
			'accounts'        => self::build_accounts_string(),
			'selected_method' => self::get_selected_method(),
			// The string "true", not a boolean — one payment per link; each checkout attempt
			// mints its own (contracts/api.md). Was documented as part of this payload since
			// spec 003 but never actually sent until now.
			'otp'             => 'true',
		);

		// Back to the actual booking page with LatePoint's own resume key, not the homepage.
		$base = (string) $intent->get_page_url_with_intent();
		if ( ! wp_http_validate_url( $base ) ) {
			$base = home_url( '/' );
		}
		foreach ( array( 'success', 'cancel', 'error' ) as $type ) {
			$payload[ $type . '_url' ] = add_query_arg(
				array(
					'ifthenpay_return' => $type,
					'token'            => $token,
					'txid'             => '[TRANSACTIONID]',
				),
				$base
			);
		}

		return $payload;
	}

	/**
	 * Formats an amount to the two-decimal string every ifthenpay API expects — shared with
	 * IfthenpayLpPaymentProcessor and IfthenpayLpSettlement, so the one formula
	 * (number_format($x, 2, '.', '')) isn't reimplemented in three places.
	 *
	 * @param float|int|string $raw
	 */
	public static function format_amount( $raw ): string {
		return number_format( (float) $raw, 2, '.', '' );
	}

	/**
	 * Build the description as "Intent #{id} - {admin description}".
	 */
	private static function build_description( $intent ): string {
		$admin_desc = OsSettingsHelper::get_settings_value( 'ifthenpay_description', '' );
		return sprintf(
			/* translators: %1$s: intent id, %2$s: admin description */
			__( 'Intent #%1$s - %2$s', 'ifthenpay-payments-for-latepoint' ),
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
	 * Serialize enabled methods into "METHOD|account;METHOD|account" format — confirmed by
	 * ifthenpay's own accounts-field documentation (`MBWAY|MBWAY-KEY;CCARD|CCARD-KEY;...`, a bare
	 * key with no spaces and no repeated method name) — the shape Pay By Link's own `accounts`
	 * field expects. The settings page stores only which methods are enabled, not their account
	 * keys — those come from the same live gateway dataset the settings page itself reads
	 * (IfthenpayLpGatewayDataset already strips the raw `"{METHOD} | "` display prefix down to
	 * the bare key), matched here against the saved Gateway Key. Multibanco and Payshop are
	 * excluded even if enabled: they are deferred-reference methods PBL never offers, and a
	 * merchant can enable them today for a future deferred flow this plugin doesn't have yet.
	 */
	private static function build_accounts_string(): string {
		// Drops the settings page's always-present hidden fallback entry (an empty string — see
		// IfthenpayLpAdminFormRenderer::render_payments_configuration()), the same as that page's own
		// read of this setting does.
		$saved           = (array) OsSettingsHelper::get_settings_value( 'ifthenpay_payment_methods_configuration', array() );
		$enabled_methods = array_values( array_filter( $saved, static fn( $value ) => is_string( $value ) && '' !== $value ) );
		$gateway_key     = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key', '' );
		$backoffice_key  = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_backoffice_key', '' );

		if ( array() === $enabled_methods || '' === $gateway_key || '' === $backoffice_key ) {
			return '';
		}

		$dataset              = IfthenpayLpGatewayDataset::get( $backoffice_key );
		$accounts_for_gateway = null === $dataset ? array() : ( $dataset['accounts'][ $gateway_key ] ?? array() );

		$parts = array();
		foreach ( $enabled_methods as $method_code ) {
			if ( IfthenpayLpPayByLinkMethodEligibility::is_listed_in_pay_by_link( $method_code ) && isset( $accounts_for_gateway[ $method_code ] ) ) {
				$parts[] = $method_code . '|' . $accounts_for_gateway[ $method_code ];
			}
		}

		return implode( ';', $parts );
	}

	/**
	 * The saved default method's position in the live method catalog, the shape Pay By Link's
	 * own `selected_method` field expects. Only MBWAY, credit card, and Pix are valid values here
	 * — Multibanco, Payshop, Google Pay, and Apple Pay are not, even though the last two are valid
	 * `accounts` entries.
	 */
	private static function get_selected_method(): string {
		$default = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_default_method', '' );
		if ( '' === $default || ! IfthenpayLpPayByLinkMethodEligibility::is_eligible_as_default( $default ) ) {
			return '';
		}

		$catalog = IfthenpayLpMethodCatalog::get();

		return isset( $catalog[ $default ]['position'] ) ? (string) $catalog[ $default ]['position'] : '';
	}
}
