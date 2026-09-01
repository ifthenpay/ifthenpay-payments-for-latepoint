<?php
/**
 * The gateway dataset for a Backoffice Key (contracts/api.md operation #2): every gateway key the
 * account has, and which methods each one has an account for — intersected against the method
 * catalog, so a method the catalog currently hides never reaches the caller regardless of what
 * the raw gateway record contains.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-request only, keyed by Backoffice Key — deliberately not a transient. This reflects the
 * merchant's own backoffice configuration; they change it there and expect to see it here
 * immediately, and a single settings-page render asks for it more than once.
 */
class IfthenpayLpGatewayDataset {

	private const URL = 'https://api.ifthenpay.com/gateway/get';

	/**
	 * The one `Entity` code that doesn't match its gateway-record field name — VERIFIED live, see
	 * contracts/api.md operation #1's trap note. Every other catalog code is used as-is.
	 */
	private const CATALOG_TO_FIELD_NAME = array(
		'MB' => 'Multibanco',
	);

	/**
	 * Per-request cache, keyed by Backoffice Key.
	 *
	 * @var array<string,array{gatewaykeys:array<string,string>,accounts:array<string,array<string,string>>}|null>
	 */
	private static array $cache = array();

	/**
	 * Returns the formatted dataset for a Backoffice Key, fetching and caching on first use.
	 *
	 * @param string $backoffice_key The merchant's Backoffice Key.
	 * @return array{gatewaykeys:array<string,string>,accounts:array<string,array<string,string>>}|null
	 *         `null` means the fetch failed and the caller cannot tell whether gateway keys exist
	 *         — distinct from a real, empty dataset, which means this Backoffice Key genuinely has
	 *         none for this context yet (the normal first-run state).
	 */
	public static function get( string $backoffice_key ): ?array {
		if ( array_key_exists( $backoffice_key, self::$cache ) ) {
			return self::$cache[ $backoffice_key ];
		}

		$dataset                        = self::fetch( $backoffice_key );
		self::$cache[ $backoffice_key ] = $dataset;

		return $dataset;
	}

	/**
	 * Fetches and formats the dataset, uncached.
	 *
	 * @param string $backoffice_key The merchant's Backoffice Key.
	 * @return array{gatewaykeys:array<string,string>,accounts:array<string,array<string,string>>}|null
	 */
	private static function fetch( string $backoffice_key ) {
		$catalog = IfthenpayLpMethodCatalog::get();
		if ( null === $catalog ) {
			return null;
		}

		$url = add_query_arg(
			array(
				'boKey' => rawurlencode( $backoffice_key ),
				'type'  => 'LatePoint',
			),
			self::URL
		);

		try {
			$raw = IfthenpayLpApiClient::get( $url, IfthenpayLpApiClient::TIMEOUT_GENERAL );
		} catch ( IfthenpayLpApiException $e ) {
			return null;
		}

		if ( ! is_array( $raw ) ) {
			return null;
		}

		return self::format( $raw, $catalog );
	}

	/**
	 * Turns the raw gateway records into the {gatewaykeys, accounts} shape.
	 *
	 * @param array<int,array<string,mixed>>                                             $raw     Raw gateway records.
	 * @param array<string,array{position:int,image:string,tooltip:string,label:string}> $catalog Method catalog to intersect against.
	 * @return array{gatewaykeys:array<string,string>,accounts:array<string,array<string,string>>}
	 */
	private static function format( array $raw, array $catalog ): array {
		$gatewaykeys = array();
		$accounts    = array();

		foreach ( $raw as $record ) {
			$gateway_key = $record['GatewayKey'] ?? '';
			if ( ! is_string( $gateway_key ) || '' === $gateway_key ) {
				continue;
			}

			$gatewaykeys[ $gateway_key ] = is_string( $record['Alias'] ?? null ) ? $record['Alias'] : $gateway_key;

			$gateway_accounts = self::extract_accounts( $record, $catalog );
			if ( array() !== $gateway_accounts ) {
				$accounts[ $gateway_key ] = $gateway_accounts;
			}
		}

		return array(
			'gatewaykeys' => $gatewaykeys,
			'accounts'    => $accounts,
		);
	}

	/**
	 * Extracts a catalog-visible method's account key from one gateway record, method by method.
	 *
	 * @param array<string,mixed>                                                        $record  One gateway record.
	 * @param array<string,array{position:int,image:string,tooltip:string,label:string}> $catalog Method catalog to intersect against.
	 * @return array<string,string> methodKey => accountKey, catalog methods only.
	 */
	private static function extract_accounts( array $record, array $catalog ): array {
		$accounts = array();

		foreach ( $catalog as $method_key => $method_meta ) {
			$field_name = self::CATALOG_TO_FIELD_NAME[ $method_key ] ?? $method_key;
			$raw_value  = $record[ $field_name ] ?? '';

			if ( ! is_string( $raw_value ) || '' === $raw_value ) {
				continue;
			}

			$account_key = self::extract_account_key( $raw_value );
			if ( null !== $account_key ) {
				$accounts[ $method_key ] = $account_key;
			}
		}

		return $accounts;
	}

	/**
	 * A method field's raw value is `"{METHOD}|{accountKey}"` — except Multibanco can instead be
	 * the raw `"{entidade}|{subentidade}"` form (contracts/api.md's trap note). Either way, what
	 * comes after the separator is a real, non-empty account reference; only its meaning differs,
	 * which is a concern for the Multibanco-specific consumer (spec 001), not this intersection.
	 *
	 * @param string $raw_value One method field's raw value.
	 */
	private static function extract_account_key( string $raw_value ): ?string {
		$parts = explode( '|', $raw_value, 2 );
		if ( ! isset( $parts[1] ) ) {
			return null;
		}

		$account_key = trim( $parts[1] );

		return '' === $account_key ? null : $account_key;
	}
}
