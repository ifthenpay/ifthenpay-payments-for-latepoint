<?php
/**
 * Parses and validates the inbound payment-notification query string, per
 * specs/001-multibanco-deferred/contracts/callback.md. We chose these five parameter names
 * ourselves when registering the callback template (IfthenpayLpCallbackRegistration) — see that
 * contract for why a single shape now covers every method, deferred or realtime.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every field is an opaque string. `amount`, `reference` and `apk` are required per the contract's
 * own validation order (step 1); `method` and `request_id` are always sent by ifthenpay from our
 * own template but are not themselves re-validated here — a missing one becomes ''. No length or
 * digits-only assertion is ever applied: real records carry a `request_id` of `"0"` and a
 * `reference` that is a domain name (see research.md).
 */
class IfthenpayLpCallbackParams {

	/**
	 * Our own correlation handle (the ORDER_ID placeholder) — matched against the repository's
	 * `token` column, not the `reference` column (that one holds the actual Multibanco reference
	 * digits, a different value).
	 *
	 * @var string
	 */
	public string $reference;

	/**
	 * As sent by ifthenpay, formatted — compared as a string, never cast to a number.
	 *
	 * @var string
	 */
	public string $amount;

	/**
	 * Base64-encoded gateway key, still encoded — compare with matches_gateway_key(), never decode
	 * and compare by hand elsewhere.
	 *
	 * @var string
	 */
	public string $apk;

	/**
	 * The ifthenpay method code, e.g. `MB`, `PAYBYLINK`.
	 *
	 * @var string
	 */
	public string $method;

	/**
	 * The ifthenpay settlement identifier — the settle_payment() idempotency key.
	 *
	 * @var string
	 */
	public string $request_id;

	/**
	 * Builds a fully-parsed instance; use from_array() instead of this directly.
	 *
	 * @param string $reference  Our own token.
	 * @param string $amount     As sent by ifthenpay, formatted.
	 * @param string $apk        Still base64-encoded.
	 * @param string $method     ifthenpay method code, e.g. `MB`, `PAYBYLINK`.
	 * @param string $request_id ifthenpay's settlement identifier.
	 */
	private function __construct( string $reference, string $amount, string $apk, string $method, string $request_id ) {
		$this->reference  = $reference;
		$this->amount     = $amount;
		$this->apk        = $apk;
		$this->method     = $method;
		$this->request_id = $request_id;
	}

	/**
	 * Builds from a `$_GET`-shaped array. Returns null when a required parameter is missing or
	 * empty — including when the request arrived shaped for a different, older per-method
	 * registration (e.g. Payshop's own `anti_phishing_key`/`order_id` instead of our `apk`/
	 * `reference`), which simply has none of our required keys.
	 *
	 * @param array<int|string,mixed> $params Raw request parameters, e.g. `$_GET` or a
	 *                                        `parse_str()` result.
	 */
	public static function from_array( array $params ): ?self {
		foreach ( array( 'amount', 'reference', 'apk' ) as $required ) {
			if ( ! isset( $params[ $required ] ) || '' === $params[ $required ] ) {
				return null;
			}
		}

		return new self(
			(string) $params['reference'],
			(string) $params['amount'],
			(string) $params['apk'],
			isset( $params['method'] ) ? (string) $params['method'] : '',
			isset( $params['request_id'] ) ? (string) $params['request_id'] : ''
		);
	}

	/**
	 * Decodes `apk` and compares it, in constant time, against the gateway key stored on the
	 * matched payment record — never a global setting, per the contract.
	 *
	 * @param string $record_gateway_key The gateway key stored on the matched repository row.
	 */
	public function matches_gateway_key( string $record_gateway_key ): bool {
		if ( '' === $record_gateway_key ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the anti-phishing key ifthenpay itself base64-encoded (research.md), not obfuscation.
		$decoded = base64_decode( $this->apk, true );
		if ( false === $decoded ) {
			return false;
		}

		return hash_equals( $record_gateway_key, $decoded );
	}
}
