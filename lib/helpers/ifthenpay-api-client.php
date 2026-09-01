<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What remains here is only what 003 T-11 did not migrate: creating a Pay By Link and polling its
 * transaction status, both plain public API calls that take no Backoffice Key at all (path/body
 * auth only). Everything that needed the Backoffice Key — key validation, the gateway/methods
 * dataset — now goes through IfthenpayLpApiClient and friends instead; do not add new callers here.
 */
class IfthenpayAPIClient {

	const BASE_API_PUBLIC             = 'https://api.ifthenpay.com';
	const ENDPOINT_PAY_BY_LINK        = '/gateway/pinpay';
	const ENDPOINT_TRANSACTION_STATUS = '/gateway/transaction/status';

	/**
	 * Create a “Pay by Link” on ifthenpay.
	 *
	 * @param string $gateway_key
	 * @param array  $payload
	 * @return object { pin_code, pinpay_url, redirect_url }
	 * @throws Exception
	 */
	public static function create_pay_by_link( string $gateway_key, array $payload ) {
		$url = rtrim( self::BASE_API_PUBLIC, '/' )
			. self::ENDPOINT_PAY_BY_LINK
			. '/' . rawurlencode( $gateway_key );

		$response = self::post( $url, $payload );

		if ( empty( $response['PinCode'] ) || empty( $response['PinpayUrl'] ) || empty( $response['RedirectUrl'] ) ) {
			throw new Exception( esc_html__( 'Invalid response from ifthenpay Pay-by-Link API.', 'ifthenpay-payments-for-latepoint' ) );
		}

		return (object) array(
			'pin_code'     => $response['PinCode'],
			'pinpay_url'   => $response['PinpayUrl'],
			'redirect_url' => $response['RedirectUrl'],
		);
	}

	/**
	 * Get payment status by Transaction ID.
	 *
	 * @param string $transaction_id
	 * @return bool
	 */
	public static function get_payment_status_by_transaction_id( string $transaction_id ): bool {
		$url = rtrim( self::BASE_API_PUBLIC, '/' )
			. self::ENDPOINT_TRANSACTION_STATUS
			. '?transactionId=' . urlencode( $transaction_id );

		return (bool) self::get( $url );
	}

	/**
	 * GET request helper.
	 *
	 * @param string $url
	 * @return mixed
	 * @throws Exception
	 */
	private static function get( string $url ) {
		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) ) {
			throw new Exception( esc_html( $response->get_error_message() ) );
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) && ! is_bool( $decoded ) ) {
			throw new Exception( esc_html__( 'Invalid response (GET) from ifthenpay API.', 'ifthenpay-payments-for-latepoint' ) );
		}

		return $decoded;
	}

	/**
	 * POST request helper.
	 *
	 * @throws Exception
	 */
	private static function post( string $url, array $data ): array {
		$args = array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $data ),
			'timeout' => 10,
		);

		$resp = wp_remote_post( $url, $args );
		if ( is_wp_error( $resp ) ) {
			throw new Exception( esc_html( $resp->get_error_message() ) );
		}

		$body    = wp_remote_retrieve_body( $resp );
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			throw new Exception( esc_html__( 'Invalid JSON from ifthenpay API.', 'ifthenpay-payments-for-latepoint' ) );
		}

		return $decoded;
	}
}
