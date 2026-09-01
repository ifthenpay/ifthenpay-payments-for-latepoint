<?php
/**
 * Callback URL registration (003 T-12) — one activation per gateway key covers every method it
 * has an account for, since ifthenpay substitutes `[PAYMENT_METHOD]` itself rather than needing a
 * separate registration per method.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The REST route this registers against (`ifthenpay/v1/callback`) has no handler yet — that is
 * spec 001's job. Registering the URL now means the URL ifthenpay has on file already matches the
 * shape 001's handler will expect, instead of both changing at once later.
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
				__( 'The callback URL is too long (over 300 characters) — likely a long domain with a subdirectory install.', 'ifthenpay-payments-for-latepoint' )
			);
			return false;
		}

		try {
			$response = IfthenpayLpApiClient::post(
				self::URL,
				array(
					'apKey' => base64_encode( $gateway_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- required by ifthenpay's own callback-activation contract (contracts/api.md operation #4), not obfuscation.
					'chave' => $gateway_key,
					'urlCb' => $callback_url,
				),
				IfthenpayLpApiClient::TIMEOUT_GENERAL,
				false
			);
		} catch ( IfthenpayLpApiException $e ) {
			self::store_status( $gateway_key, false, $e->getMessage() );
			return false;
		}

		$success = self::response_indicates_success( $response );
		self::store_status(
			$gateway_key,
			$success,
			$success ? '' : __( 'ifthenpay did not accept this callback URL.', 'ifthenpay-payments-for-latepoint' )
		);

		return $success;
	}

	/**
	 * The "plain text" answer here is actually a bare JSON string literal — `"OK"` / `"INVALID"`,
	 * quotes included — VERIFIED live (003 T-12c), not the unquoted `OK` the contract previously
	 * assumed. Accepts either shape, so a future change back to true plain text would not silently
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
	 * attempted (e.g. a fresh install, or a gateway key that was never saved).
	 *
	 * @param string $gateway_key The gateway key to look up.
	 * @return array{success:bool,message:string,registered_at:int}|null
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
	 */
	private static function build_callback_url(): string {
		$base      = rest_url( 'ifthenpay/v1/callback' );
		$separator = ( false === strpos( $base, '?' ) ) ? '?' : '&';

		return $base . $separator
			. 'amount=[AMOUNT]&reference=[ORDER_ID]&apk=[ANTI_PHISHING_KEY]&method=[PAYMENT_METHOD]&request_id=[REQUEST_ID]';
	}

	/**
	 * Records one registration outcome, replacing any previous one for the same gateway key.
	 *
	 * @param string $gateway_key The gateway key this outcome belongs to.
	 * @param bool   $success     Whether registration succeeded.
	 * @param string $message     Empty on success; the reason on failure.
	 */
	private static function store_status( string $gateway_key, bool $success, string $message ): void {
		$statuses                 = get_option( self::STATUS_OPTION, array() );
		$statuses[ $gateway_key ] = array(
			'success'       => $success,
			'message'       => $message,
			'registered_at' => time(),
		);
		update_option( self::STATUS_OPTION, $statuses );
	}
}
