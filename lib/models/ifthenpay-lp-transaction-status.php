<?php
/**
 * Confirms a completed payment directly with ifthenpay by transaction id — the outbound check
 * IfthenpayLpManualRecheck originally shipped without (see that class's own history: no
 * independently-verified endpoint existed at the time). ifthenpay's newer transaction-status
 * endpoint fills that gap.
 *
 * VERIFIED live: a real, completed transaction id answers 200 with
 * `{"TransactionId":"...","PaymentMethod":"MBWAY"}`; an unknown/nonexistent one answers 404 with
 * an empty body — no JSON envelope, no partial "exists but failed" shape observed either way.
 * Existence is the signal: only a genuinely completed payment appears to get a transaction id
 * ifthenpay will answer for here. No key or auth parameter — just the transaction id itself.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One operation: confirm-by-transaction-id.
 */
class IfthenpayLpTransactionStatus {

	private const URL = 'https://api.ifthenpay.com/gateway/transaction/status/get';

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- IfthenpayLpCredentialException is also thrown, propagated from IfthenpayLpApiClient::get() rather than a literal `throw` in this function's own body, which this sniff can't see.
	/**
	 * Confirms a transaction id with ifthenpay directly.
	 *
	 * @param string $transaction_id ifthenpay's own identifier for the payment (our request_id).
	 * @return string|null The payment method ifthenpay recorded for it (e.g. `"MBWAY"`), or null
	 *                      when ifthenpay does not recognise this transaction id at all — a 404,
	 *                      not an error.
	 * @throws IfthenpayLpCredentialException On 401/403 (not expected for this endpoint, but the
	 *                                        shared client always checks).
	 * @throws IfthenpayLpTransportException  On a network failure, a 5xx, or a 200 response
	 *                                        missing PaymentMethod.
	 */
	public static function check( string $transaction_id ): ?string {
		$url = add_query_arg( array( 'transactionId' => rawurlencode( $transaction_id ) ), self::URL );

		// expects_json: false — a 404's empty body is not valid JSON, and is the documented "not
		// found" signal, not a transport error; decoding happens here instead, once the body is
		// known not to be empty.
		$raw = IfthenpayLpApiClient::get( $url, IfthenpayLpApiClient::TIMEOUT_GENERAL, false );

		if ( '' === $raw ) {
			return null;
		}

		$response = json_decode( $raw, true );
		if ( ! is_array( $response ) || empty( $response['PaymentMethod'] ) ) {
			throw new IfthenpayLpTransportException(
				esc_html__( 'ifthenpay returned an unexpected response while verifying this transaction.', 'ifthenpay-payments-for-latepoint' )
			);
		}

		return (string) $response['PaymentMethod'];
	}
}
