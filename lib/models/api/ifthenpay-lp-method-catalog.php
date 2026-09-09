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
 *
 * Cached two ways, same reasoning as IfthenpayLpGatewayDataset: an in-memory per-request layer (so
 * one render asking for it several times never re-fetches — e.g. add_settings_fields() and the
 * gateway dataset's own fetch() both ask for it in the same request) underneath the transient.
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
	 * Per-request cache. No key dimension (the catalog is global) — `$fetched` is what
	 * distinguishes "not asked yet this request" from "asked, and the result was null".
	 *
	 * @var array<string,array{position:int,image:string,tooltip:string,label:string}>|null
	 */
	private static ?array $cache = null;

	/**
	 * Whether get() has already run this request — see $cache's own docblock.
	 *
	 * @var bool
	 */
	private static bool $fetched = false;

	/**
	 * Returns the catalog, from cache when available.
	 *
	 * @return array<string,array{position:int,image:string,tooltip:string,label:string}>|null
	 *         Keyed by `Entity` code (`MB`, `MBWAY`, …), `IsVisible: false` entries already
	 *         excluded. `null` means the fetch failed — distinct from an empty catalog, which
	 *         would mean ifthenpay itself currently offers nothing.
	 */
	public static function get(): ?array {
		if ( self::$fetched ) {
			return self::$cache;
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			self::$fetched = true;
			self::$cache   = $cached;
			return $cached;
		}

		try {
			$raw = IfthenpayLpApiClient::get( self::URL, IfthenpayLpApiClient::TIMEOUT_GENERAL );
		} catch ( IfthenpayLpApiException $e ) {
			self::$fetched = true;
			self::$cache   = null;
			return null;
		}

		if ( ! is_array( $raw ) ) {
			self::$fetched = true;
			self::$cache   = null;
			return null;
		}

		$formatted = IfthenpayLpDataFormatter::format_available_payment_methods( $raw );
		set_transient( self::CACHE_KEY, $formatted, self::CACHE_TTL );

		self::$fetched = true;
		self::$cache   = $formatted;
		return $formatted;
	}
}
