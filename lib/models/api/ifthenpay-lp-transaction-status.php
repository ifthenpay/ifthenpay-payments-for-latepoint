<?php
/**
 * Confirms a completed payment directly with ifthenpay by transaction id — the outbound check
 * IfthenpayLpManualRecheck originally shipped without (see that class's own history: no
 * independently-verified endpoint existed at the time). ifthenpay's newer transaction-status
 * endpoint fills that gap, and — unlike the endpoint it replaced — returns enough to verify a
 * txid actually belongs to the specific payment being settled, not just that it exists.
 *
 * VERIFIED live: a real, completed transaction id answers 200 with
 * `{"TransactionId":"...","PaymentMethod":"MBWAY","Amount":"0.10","OrderId":"..."}` —
 * `OrderId` echoes back exactly the `id` field sent when the Pay By Link was created (our own
 * token). An unknown/nonexistent transaction id answers 404 with an empty body — no JSON
 * envelope, no partial "exists but failed" shape observed either way. No key or auth parameter —
 * just the transaction id itself.
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
	 * @return object{payment_method:string,amount:string,order_id:string}|null The confirmed
	 *                      payment method, amount, and the `id` this transaction's Pay By Link was
	 *                      created with — or null when ifthenpay does not recognise this
	 *                      transaction id at all (a 404, not an error). Callers must check
	 *                      `order_id` against their own token and `amount` against their own
	 *                      stored amount before trusting this as confirmation of a *specific*
	 *                      payment — existence alone only proves *some* payment completed.
	 * @throws IfthenpayLpCredentialException On 401/403 (not expected for this endpoint, but the
	 *                                        shared client always checks).
	 * @throws IfthenpayLpTransportException  On a network failure, a 5xx, or a 200 response
	 *                                        missing PaymentMethod, Amount, or OrderId.
	 */
	public static function check( string $transaction_id ): ?object {
		$url = add_query_arg( array( 'transactionId' => rawurlencode( $transaction_id ) ), self::URL );

		// expects_json: false — a 404's empty body is not valid JSON, and is the documented "not
		// found" signal, not a transport error; decoding happens here instead, once the body is
		// known not to be empty.
		$raw = IfthenpayLpApiClient::get( $url, IfthenpayLpApiClient::TIMEOUT_GENERAL, false );

		if ( '' === $raw ) {
			return null;
		}

		$response = json_decode( $raw, true );
		if (
			! is_array( $response )
			|| empty( $response['PaymentMethod'] )
			|| ! isset( $response['Amount'] )
			|| '' === $response['Amount']
			|| empty( $response['OrderId'] )
		) {
			throw new IfthenpayLpTransportException(
				esc_html__( 'ifthenpay returned an unexpected response while verifying this transaction.', 'ifthenpay-payments-for-latepoint' )
			);
		}

		return (object) array(
			'payment_method' => (string) $response['PaymentMethod'],
			'amount'         => (string) $response['Amount'],
			'order_id'       => (string) $response['OrderId'],
		);
	}
}
