<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one thing left here: polling a payment's status by transaction ID, used by
 * verifyPaymentWithRetry() in the controller. Not migrated to IfthenpayLpApiClient — the
 * `/gateway/transaction/status` endpoint it calls was never independently verified the way every
 * operation in contracts/api.md was (live curl, or read from the Moodle reference plugin); it is
 * inherited as-is from the pre-revamp plugin. It is also entangled with a known, unfixed
 * verification gap in update_payment_repo_by_modal_url() (see 001's research.md) — migrating this
 * one call in isolation would look like a fix without being one. Leave both to 001, which already
 * scopes the real fix. Do not add new callers here.
 */
class IfthenpayAPIClient {

	const BASE_API_PUBLIC             = 'https://api.ifthenpay.com';
	const ENDPOINT_TRANSACTION_STATUS = '/gateway/transaction/status';

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
}
