<?php
/**
 * HTTP transport for every outbound call to ifthenpay. One class, so every operation shares the
 * same timeout policy and error model — no bare wp_remote_get()/wp_remote_post() elsewhere.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared transport, used by every operation-specific class (key validation, gateway dataset, Pay
 * By Link, Multibanco/Payshop references, callback activation) — this class only owns the HTTP
 * call and the error model, not any endpoint's shape.
 */
class IfthenpayLpApiClient {

	/**
	 * Timeout for calls made from the settings page (key validation). An ifthenpay outage must
	 * not make a merchant wait long to find that out.
	 */
	public const TIMEOUT_VALIDATION = 5;

	/**
	 * Timeout for every other call.
	 */
	public const TIMEOUT_GENERAL = 10;

	/**
	 * GET request.
	 *
	 * @param string $url          Fully qualified URL.
	 * @param int    $timeout      Seconds — TIMEOUT_VALIDATION or TIMEOUT_GENERAL.
	 * @param bool   $expects_json False for an endpoint that answers plain text by design (e.g.
	 *                             callback activation's `OK`/`INVALID`) — the raw body is
	 *                             returned as-is, with no JSON parsing attempted or expected.
	 * @return array<mixed>|string Decoded JSON body, or the raw string when $expects_json is false.
	 * @throws IfthenpayLpCredentialException On 401/403.
	 * @throws IfthenpayLpTransportException  On a network failure, a 5xx, or unparseable JSON
	 *                                         when JSON was expected.
	 */
	public static function get( string $url, int $timeout = self::TIMEOUT_GENERAL, bool $expects_json = true ) {
		return self::request( 'GET', $url, null, $timeout, $expects_json );
	}

	/**
	 * POST request with a JSON body.
	 *
	 * @param string              $url          Fully qualified URL.
	 * @param array<string,mixed> $body         Request payload, JSON-encoded.
	 * @param int                 $timeout      Seconds — TIMEOUT_VALIDATION or TIMEOUT_GENERAL.
	 * @param bool                $expects_json False for a plain-text endpoint; see {@see self::get()}.
	 * @return array<mixed>|string
	 * @throws IfthenpayLpCredentialException On 401/403.
	 * @throws IfthenpayLpTransportException  On a network failure, a 5xx, or unparseable JSON
	 *                                         when JSON was expected.
	 */
	public static function post( string $url, array $body, int $timeout = self::TIMEOUT_GENERAL, bool $expects_json = true ) {
		return self::request( 'POST', $url, $body, $timeout, $expects_json );
	}

	/**
	 * Shared implementation for get()/post().
	 *
	 * @param string                   $method       HTTP method.
	 * @param string                   $url          Fully qualified URL.
	 * @param array<string,mixed>|null $body         Request payload, or null for a bodyless request.
	 * @param int                      $timeout      Seconds.
	 * @param bool                     $expects_json Whether to parse the body as JSON.
	 * @return array<mixed>|string
	 * @throws IfthenpayLpCredentialException On 401/403.
	 * @throws IfthenpayLpTransportException  On a network failure, a 5xx, or unparseable JSON
	 *                                         when JSON was expected.
	 */
	private static function request( string $method, string $url, ?array $body, int $timeout, bool $expects_json ) {
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
		);

		if ( null !== $body ) {
			$encoded_body = wp_json_encode( $body );
			if ( false === $encoded_body ) {
				throw new IfthenpayLpTransportException(
					esc_html__( 'The request payload could not be encoded as JSON.', 'ifthenpay-payments-for-latepoint' )
				);
			}

			$args['headers'] = array( 'Content-Type' => 'application/json' );
			$args['body']    = $encoded_body;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new IfthenpayLpTransportException( esc_html( $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $status || 403 === $status ) {
			throw new IfthenpayLpCredentialException(
				esc_html__( 'ifthenpay rejected the credentials used for this request.', 'ifthenpay-payments-for-latepoint' )
			);
		}

		if ( $status >= 500 ) {
			throw new IfthenpayLpTransportException(
				esc_html(
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'ifthenpay returned an unexpected server error (%d).', 'ifthenpay-payments-for-latepoint' ),
						$status
					)
				)
			);
		}

		$raw = wp_remote_retrieve_body( $response );

		if ( ! $expects_json ) {
			return $raw;
		}

		$decoded = json_decode( $raw, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			throw new IfthenpayLpTransportException(
				esc_html__( 'ifthenpay returned a response that could not be parsed as JSON.', 'ifthenpay-payments-for-latepoint' )
			);
		}

		return $decoded;
	}
}
