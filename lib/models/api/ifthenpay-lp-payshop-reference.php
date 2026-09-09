<?php
/**
 * Payshop reference operation — a standalone REST API on a different host than Multibanco's own
 * (`ifthenpay.com`, not `api.ifthenpay.com`), with lowercase Portuguese field names instead of
 * Multibanco's camelCase ones. Verified against ifthenpay's own official PHP SDK
 * (`ifthenpay/ifthenpay-sdk-php`, `PayshopService`/`PayshopInitRequest`/`Config`) and corroborated
 * against ifthenpay's own public docs — not against a live sandbox call: unlike Multibanco, Payshop
 * has **no sandbox endpoint** (the SDK's own docs say to ask ifthenpay support for a real test
 * account instead), so this has not been exercised against a real response the way
 * IfthenpayLpMultibancoReference has.
 *
 * A GET mirror is also documented, at a **different path**
 * (`/api/payshop/get?payshopkey=...&id=...&valor=...&validade=...`), not just a method swap on
 * this same URL. Deliberately not used: it would put `payshopkey` — a real credential — in a query
 * string, where it leaks into server/proxy access logs and browser history. Same reasoning as
 * every other credentialled call in this add-on; POST with a JSON body keeps it out of anything
 * that logs a URL.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A rejected request answers 200 with `Code` != "0" (e.g. `"102"` invalid key, `"103"` invalid or
 * missing parameter) — unlike Multibanco, where a bad credential is caught earlier, at the HTTP
 * level, by IfthenpayLpApiClient itself (401/403, empty body). A Payshop credential problem never
 * reaches that check: it arrives as a normal 200 with a rejection code in the body, so it surfaces
 * here as IfthenpayLpTransportException, not IfthenpayLpCredentialException — same as any other
 * rejected request. Callers must not assume a caught exception here implies bad request data
 * specifically.
 */
class IfthenpayLpPayshopReference {

	private const URL = 'https://ifthenpay.com/api/payshop/reference/';

	private const CODE_SUCCESS = '0';

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- IfthenpayLpCredentialException is also thrown, propagated from IfthenpayLpApiClient::post() rather than a literal `throw` in this function's own body, which this sniff can't see.
	/**
	 * Creates a Payshop reference. No `entity` in the response — Payshop references stand alone.
	 *
	 * @param string $payshop_key The merchant's Payshop key.
	 * @param string $order_id    Our correlation handle, max 25 chars per the API.
	 * @param string $amount      Exactly 2 decimals, `.` separator (e.g. `"10.99"`) — a string,
	 *                            not a number, per the API's own contract.
	 * @param string $expiry_date `YYYYMMDD`. Always required here — omitting it at the API level
	 *                            means "no expiry", which would hold a slot forever.
	 * @return object{reference:string,request_id:string}
	 * @throws IfthenpayLpCredentialException On 401/403 (not observed for Payshop specifically, but
	 *                                        IfthenpayLpApiClient can still throw it for any call).
	 * @throws IfthenpayLpTransportException  On a network failure, a 5xx, unparseable JSON, or a
	 *                                        rejected request (`Code !== "0"` — the Message is
	 *                                        included in the exception).
	 */
	public static function create( string $payshop_key, string $order_id, string $amount, string $expiry_date ): object {
		$response = IfthenpayLpApiClient::post(
			self::URL,
			array(
				'payshopkey' => $payshop_key,
				'id'         => $order_id,
				'valor'      => $amount,
				'validade'   => $expiry_date,
			),
			IfthenpayLpApiClient::TIMEOUT_GENERAL
		);

		if ( ! is_array( $response ) || self::CODE_SUCCESS !== ( $response['Code'] ?? null ) ) {
			$message = is_array( $response ) && ! empty( $response['Message'] )
				? $response['Message']
				: __( 'ifthenpay rejected the Payshop reference request.', 'ifthenpay-payments-for-latepoint' );

			throw new IfthenpayLpTransportException( esc_html( $message ) );
		}

		return (object) array(
			'reference'  => $response['Reference'],
			'request_id' => $response['RequestId'],
		);
	}
}
