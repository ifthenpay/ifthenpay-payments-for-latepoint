<?php
/**
 * Callback URL registration — one activation per gateway key covers every method it
 * has an account for, since ifthenpay substitutes `[PAYMENT_METHOD]` itself rather than needing a
 * separate registration per method.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The route this registers against is handled by IfthenpayLpCallbackRestController.
 */
class IfthenpayLpCallbackRegistration {

	private const URL = 'https://api.ifthenpay.com/endpoint/callback/activation/?cms=latepoint';

	/**
	 * Watch the 300-character URL limit — validated before the request is even attempted, as its
	 * own named failure rather than a request ifthenpay would reject.
	 */
	private const MAX_URL_LENGTH = 300;

	private const STATUS_OPTION = 'ifthenpay_lp_callback_status';

	/**
	 * Registers the callback URL for one gateway key. Never throws — a registration failure must
	 * not block the settings save it runs after; the outcome is stored instead, for
	 * get_status() to surface.
	 *
	 * @param string $gateway_key The gateway key to register the callback against.
	 * @return bool Whether registration succeeded.
	 */
	public static function register( string $gateway_key ): bool {
		$callback_url = self::build_callback_url();

		if ( strlen( $callback_url ) > self::MAX_URL_LENGTH ) {
			self::store_status(
				$gateway_key,
				false,
				__( 'The callback URL is too long (over 300 characters) — likely a long domain with a subdirectory install.', 'ifthenpay-payments-for-latepoint' ),
				$callback_url
			);
			return false;
		}

		try {
			$response = IfthenpayLpApiClient::post(
				self::URL,
				array(
					'apKey' => base64_encode( $gateway_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- required by ifthenpay's own callback-activation API contract, not obfuscation.
					'chave' => $gateway_key,
					'urlCb' => $callback_url,
				),
				IfthenpayLpApiClient::TIMEOUT_GENERAL,
				false
			);
		} catch ( IfthenpayLpApiException $e ) {
			self::store_status( $gateway_key, false, $e->getMessage(), $callback_url );
			return false;
		}

		$success = self::response_indicates_success( $response );
		self::store_status(
			$gateway_key,
			$success,
			$success ? '' : __( 'ifthenpay did not accept this callback URL.', 'ifthenpay-payments-for-latepoint' ),
			$callback_url
		);

		return $success;
	}

	/**
	 * The "plain text" answer here is actually a bare JSON string literal — `"OK"` / `"INVALID"`,
	 * quotes included — confirmed against the live API, not the unquoted `OK` initially assumed.
	 * Accepts either shape, so a future change back to true plain text would not silently
	 * break this.
	 *
	 * @param array<mixed>|string $response IfthenpayLpApiClient::post()'s return value.
	 */
	private static function response_indicates_success( $response ): bool {
		if ( ! is_string( $response ) ) {
			return false;
		}

		return in_array( trim( $response ), array( 'OK', '"OK"' ), true );
	}

	/**
	 * The last recorded registration outcome for a gateway key, or null if none was ever
	 * attempted (e.g. a fresh install, or a gateway key that was never saved). `callback_url` is
	 * the exact URL that attempt actually submitted — not necessarily what build_callback_url()
	 * would compute right now, if the site's own URL changed since — so support can compare what
	 * ifthenpay has on file for this gateway key against what the site would send today.
	 *
	 * @param string $gateway_key The gateway key to look up.
	 * @return array{success:bool,message:string,registered_at:int,callback_url:string}|null
	 */
	public static function get_status( string $gateway_key ): ?array {
		$statuses = get_option( self::STATUS_OPTION, array() );

		return $statuses[ $gateway_key ] ?? null;
	}

	/**
	 * `rest_url()` already carries the site's own permalink style (pretty vs. `?rest_route=`), so
	 * the placeholder query string is appended with whichever separator that leaves needed — never
	 * built with add_query_arg(), which would URL-encode the literal `[...]` tokens ifthenpay's own
	 * substitution engine expects verbatim.
	 *
	 * Public so a caller only wanting to preview what the *next* registration would submit — e.g.
	 * the ifthenpay Tools page, before any registration has ever run — doesn't need its own copy.
	 */
	public static function build_callback_url(): string {
		$base      = rest_url( 'ifthenpay-lp/v1/callback' );
		$separator = ( false === strpos( $base, '?' ) ) ? '?' : '&';

		return $base . $separator
			. 'amount=[AMOUNT]&reference=[ORDER_ID]&apk=[ANTI_PHISHING_KEY]&method=[PAYMENT_METHOD]&request_id=[REQUEST_ID]';
	}

	/**
	 * Records one registration outcome, replacing any previous one for the same gateway key.
	 *
	 * @param string $gateway_key  The gateway key this outcome belongs to.
	 * @param bool   $success      Whether registration succeeded.
	 * @param string $message      Empty on success; the reason on failure.
	 * @param string $callback_url The exact URL this attempt submitted (or tried to).
	 */
	private static function store_status( string $gateway_key, bool $success, string $message, string $callback_url ): void {
		$statuses                 = get_option( self::STATUS_OPTION, array() );
		$statuses[ $gateway_key ] = array(
			'success'       => $success,
			'message'       => $message,
			'registered_at' => time(),
			'callback_url'  => $callback_url,
		);
		update_option( self::STATUS_OPTION, $statuses );
	}
}
