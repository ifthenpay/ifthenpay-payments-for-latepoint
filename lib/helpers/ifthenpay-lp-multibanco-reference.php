<?php
/**
 * Multibanco dynamic reference operation — a standalone REST API, not part of
 * api.ifthenpay.com's general surface. Not exercised by the Moodle plugin; every field here is
 * VERIFIED against a real sandbox call, not inferred.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two distinct failure modes, both VERIFIED live: bad credentials answer HTTP 403 with an empty
 * body (caught by IfthenpayLpApiClient as IfthenpayLpCredentialException, before this class ever
 * sees a body); bad request data answers HTTP 400 with the normal envelope, blank fields, and
 * `Status: "-1"` — that one only this class can catch, since the transport layer has no reason to
 * treat a 400 as a failure on its own.
 */
class IfthenpayLpMultibancoReference {

	private const BASE_URL = 'https://api.ifthenpay.com/multibanco/reference';

	private const STATUS_SUCCESS = '0';

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- IfthenpayLpCredentialException is also thrown, propagated from IfthenpayLpApiClient::post() rather than a literal `throw` in this function's own body, which this sniff can't see.
	/**
	 * Creates a Multibanco reference.
	 *
	 * @param string $mb_key      The merchant's Multibanco key.
	 * @param string $order_id    Our correlation handle, max 25 chars per the API.
	 * @param string $amount      Exactly 2 decimals, `.` separator (e.g. `"10.99"`) — a string,
	 *                            not a number, per the API's own contract.
	 * @param int    $expiry_days Always required here — omitting it at the API level means "no
	 *                            expiry", which would hold a slot forever. `0` = expires today.
	 * @param bool   $sandbox     Use `/sandbox` instead of `/init` — fake/non-payable, same
	 *                            contract; ifthenpay's own guidance is to always use it for testing.
	 * @return object{entity:string,reference:string,amount:string,expiry_date:string,request_id:string}
	 * @throws IfthenpayLpCredentialException On 401/403.
	 * @throws IfthenpayLpTransportException  On a network failure, a 5xx, unparseable JSON, or a
	 *                                        rejected request (`Status !== "0"` — the Message is
	 *                                        included in the exception).
	 */
	public static function create( string $mb_key, string $order_id, string $amount, int $expiry_days, bool $sandbox = false ): object {
		$url = self::BASE_URL . '/' . ( $sandbox ? 'sandbox' : 'init' );

		$response = IfthenpayLpApiClient::post(
			$url,
			array(
				'mbKey'      => $mb_key,
				'orderId'    => $order_id,
				'amount'     => $amount,
				'expiryDays' => $expiry_days,
			),
			IfthenpayLpApiClient::TIMEOUT_GENERAL
		);

		if ( ! is_array( $response ) || self::STATUS_SUCCESS !== ( $response['Status'] ?? null ) ) {
			$message = is_array( $response ) && ! empty( $response['Message'] )
				? $response['Message']
				: __( 'ifthenpay rejected the Multibanco reference request.', 'ifthenpay-payments-for-latepoint' );

			throw new IfthenpayLpTransportException( esc_html( $message ) );
		}

		return (object) array(
			'entity'      => $response['Entity'],
			'reference'   => $response['Reference'],
			'amount'      => $response['Amount'],
			'expiry_date' => $response['ExpiryDate'],
			'request_id'  => $response['RequestId'],
		);
	}
}
