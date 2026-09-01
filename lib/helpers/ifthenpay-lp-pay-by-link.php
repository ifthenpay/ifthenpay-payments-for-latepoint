<?php
/**
 * Pay By Link operation (contracts/api.md operation #3) — the realtime flow, unchanged from the
 * customer's point of view. Payload construction stays the caller's job (IfthenpayDataFormatter);
 * this class only owns the call and the response contract.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A response missing any of PinCode/PinpayUrl/RedirectUrl is treated as an error — never a
 * partial success.
 */
class IfthenpayLpPayByLink {

	private const URL = 'https://api.ifthenpay.com/gateway/pinpay';

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- IfthenpayLpCredentialException is also thrown, propagated from IfthenpayLpApiClient::post() rather than a literal `throw` in this function's own body, which this sniff can't see.
	/**
	 * Creates a Pay By Link and returns its three fields, or throws.
	 *
	 * @param string              $gateway_key The gateway key to create the link under.
	 * @param array<string,mixed> $payload     Pay By Link payload — see IfthenpayDataFormatter::build_pay_by_link_payload().
	 * @return object{pin_code:string,pinpay_url:string,redirect_url:string}
	 * @throws IfthenpayLpCredentialException On 401/403.
	 * @throws IfthenpayLpTransportException  On a network failure, a 5xx, unparseable JSON, or a
	 *                                        200 missing any of the three required fields.
	 */
	public static function create( string $gateway_key, array $payload ): object {
		$url      = self::URL . '/' . rawurlencode( $gateway_key );
		$response = IfthenpayLpApiClient::post( $url, $payload, IfthenpayLpApiClient::TIMEOUT_GENERAL );

		if (
			! is_array( $response )
			|| empty( $response['PinCode'] )
			|| empty( $response['PinpayUrl'] )
			|| empty( $response['RedirectUrl'] )
		) {
			throw new IfthenpayLpTransportException(
				esc_html__( 'ifthenpay returned an incomplete Pay By Link response.', 'ifthenpay-payments-for-latepoint' )
			);
		}

		return (object) array(
			'pin_code'     => $response['PinCode'],
			'pinpay_url'   => $response['PinpayUrl'],
			'redirect_url' => $response['RedirectUrl'],
		);
	}
}
