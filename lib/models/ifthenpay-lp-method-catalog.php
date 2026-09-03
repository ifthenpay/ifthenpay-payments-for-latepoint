<?php
/**
 * The global payment-method catalog — keyless, identical for every site, cached across requests.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `IsVisible` is the single authority on what ifthenpay currently offers — a method with real
 * account data in a gateway record but `IsVisible: false` (verified live: COFIDIS, BIZUM) must
 * still not be offered. See IfthenpayLpGatewayDataset, which intersects against this catalog.
 */
class IfthenpayLpMethodCatalog {

	private const URL = 'https://api.ifthenpay.com/gateway/methods/available';

	/**
	 * Cosmetic if stale — a newly added method takes up to this long to appear, which is
	 * self-healing on the next fetch. Not worth shortening to chase that.
	 */
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	private const CACHE_KEY = 'ifthenpay_lp_method_catalog';

	/**
	 * Returns the catalog, from cache when available.
	 *
	 * @return array<string,array{position:int,image:string,tooltip:string,label:string}>|null
	 *         Keyed by `Entity` code (`MB`, `MBWAY`, …), `IsVisible: false` entries already
	 *         excluded. `null` means the fetch failed — distinct from an empty catalog, which
	 *         would mean ifthenpay itself currently offers nothing.
	 */
	public static function get(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		try {
			$raw = IfthenpayLpApiClient::get( self::URL, IfthenpayLpApiClient::TIMEOUT_GENERAL );
		} catch ( IfthenpayLpApiException $e ) {
			return null;
		}

		if ( ! is_array( $raw ) ) {
			return null;
		}

		$formatted = IfthenpayDataFormatter::format_available_payment_methods( $raw );
		set_transient( self::CACHE_KEY, $formatted, self::CACHE_TTL );

		return $formatted;
	}
}
