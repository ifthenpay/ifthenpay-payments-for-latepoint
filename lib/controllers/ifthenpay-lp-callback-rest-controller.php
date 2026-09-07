<?php
/**
 * The inbound payment-notification endpoint — a plain WP REST route, not one of LatePoint's own
 * `latepoint_route_call` actions (payments-ifthenpay-checkout-controller.php). It needs a stable
 * public URL independent of LatePoint's admin-post plumbing, kept separate from the add-on's
 * browser-facing controller actions.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates cheapest, most decisive checks first (parameter presence, then a fast unlocked
 * lookup, then the anti-phishing key) before ever calling IfthenpayLpSettlement::settle_payment()
 * — which owns the lock, the authoritative already-settled check, and the authoritative amount
 * check. Every rejection is an empty body; only the success path carries one (see body_for()'s
 * own docblock for why).
 */
class IfthenpayLpCallbackRestController {

	/**
	 * Registers `GET /wp-json/ifthenpay-lp/v1/callback` — public by design, ifthenpay calls it
	 * server-to-server with no WordPress auth of any kind; authenticity rests entirely on the
	 * anti-phishing key parameter (see IfthenpayLpCallbackParams::matches_gateway_key()).
	 */
	public static function register_routes(): void {
		register_rest_route(
			'ifthenpay-lp/v1',
			'/callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handles one inbound notification, per the validation order documented on this class.
	 *
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 *
	 * @param WP_REST_Request $request The inbound request.
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$params = IfthenpayLpCallbackParams::from_array( $request->get_query_params() );
		if ( null === $params ) {
			// Missing one of the three required parameters — nothing to look up or act on, the
			// same outcome as a reference that doesn't exist.
			return new WP_REST_Response( null, 404 );
		}

		// A fast, unlocked lookup by our own token — cheap enough to run before bothering with a
		// lock, and decisive for garbage/malicious traffic. settle_payment() re-reads the
		// authoritative row itself, under lock, keyed by request_id.
		$record = IfthenpayLpTransactionRepository::find_by_token( $params->reference );
		if ( ! $record ) {
			return new WP_REST_Response( null, 404 );
		}

		if ( ! $params->matches_gateway_key( (string) $record->gateway_key ) ) {
			return new WP_REST_Response( null, 403 );
		}

		if ( 'realtime' === $record->kind ) {
			$outcome = self::settle_realtime( $record, $params );
			return new WP_REST_Response( $outcome['body'], $outcome['status'] );
		}

		$result = IfthenpayLpSettlement::settle_payment(
			$params->request_id,
			array( 'amount' => $params->amount ),
			'callback',
			$params->reference
		);

		return new WP_REST_Response( self::body_for( $result ), self::status_for( $result ) );
	}

	/**
	 * A realtime row never has an order to settle against at the point a callback typically
	 * arrives — that only exists once the browser's own polling round-trip tells LatePoint the
	 * payment succeeded (IfthenpayLpPaymentProcessor::process_payment_by_intent()), and a callback
	 * is server-to-server, often faster than the browser's own redirect. Routing it through
	 * IfthenpayLpSettlement::settle_payment() would reject with 'order_not_ready' every time,
	 * forcing ifthenpay to retry until the browser eventually finishes on its own — no better than
	 * not handling the callback at all, and never recovers a customer who doesn't come back.
	 *
	 * Marks the row paid directly instead, the same way the polling path already does
	 * (IfthenpayLpTransactionRepository::record_verification()) — one mechanism for "this realtime
	 * row is paid", reachable from either trigger, not two that could disagree. Idempotent via the
	 * row's own status, not request_id (a realtime row is looked up by token here, same as
	 * polling — request_id was never stored at creation for this kind either way).
	 *
	 * Locked on the token (a realtime row has no request_id to lock on) — the same key the browser
	 * polling path (OsPaymentsIfthenpayCheckoutController::resolve_payment_status_from_modal_url())
	 * now also locks on, since the callback genuinely can arrive while the browser's own polling
	 * round-trip for the same payment is still in flight. Without a shared lock, both could read
	 * PENDING before either writes — harmless when both agree the payment succeeded, but a real risk
	 * when the browser's own poll reports 'cancel' at the very moment a legitimate callback confirms
	 * payment, which could silently downgrade an already-paid row to CANCELLED.
	 *
	 * @param object                    $record As found by token; already gateway-key authenticated.
	 * @param IfthenpayLpCallbackParams $params As parsed from the request.
	 * @return array{status:int,body:?string}
	 */
	private static function settle_realtime( object $record, IfthenpayLpCallbackParams $params ): array {
		try {
			return IfthenpayLpSettlementLock::with_lock(
				(string) $record->token,
				static function () use ( $params ) {
					return self::settle_realtime_locked( $params );
				}
			);
		} catch ( IfthenpayLpLockUnavailableException $e ) {
			// Our own side, not a verdict on the payment — same as the deferred path's own
			// lock_unavailable handling (IfthenpayLpSettlement::settle_payment()); ifthenpay retries.
			unset( $e );
			return array(
				'status' => 500,
				'body'   => null,
			);
		}
	}

	/**
	 * Runs with the lock for the token already held — re-reads the record fresh, since $record as
	 * passed into settle_realtime() was read before the lock and may already be stale (e.g. the
	 * browser's own polling settled this same row in between).
	 *
	 * @param IfthenpayLpCallbackParams $params As passed to settle_realtime().
	 * @return array{status:int,body:?string}
	 */
	private static function settle_realtime_locked( IfthenpayLpCallbackParams $params ): array {
		$record = IfthenpayLpTransactionRepository::find_by_token( $params->reference );
		if ( ! $record ) {
			return array(
				'status' => 404,
				'body'   => null,
			);
		}

		if ( 'PAID' === $record->status ) {
			return array(
				'status' => 200,
				'body'   => IfthenpayLpSettlementResult::ALREADY_SETTLED,
			);
		}

		if ( null !== $record->amount && IfthenpayLpDataFormatter::format_amount( $record->amount ) !== IfthenpayLpDataFormatter::format_amount( $params->amount ) ) {
			return array(
				'status' => 409,
				'body'   => null,
			);
		}

		// Not a txid — the callback's own request_id, the only correlation handle it carries. Kept
		// under the same method_data key record_verification() already uses for either shape.
		$confirmation = (object) array(
			'payment_method' => $params->method,
			'amount'         => $params->amount,
			'order_id'       => $params->reference,
		);
		IfthenpayLpTransactionRepository::record_verification( $params->reference, $params->request_id, $confirmation, true );

		return array(
			'status' => 200,
			'body'   => IfthenpayLpSettlementResult::SETTLED,
		);
	}

	/**
	 * Maps a settlement outcome to the HTTP status ifthenpay expects for it.
	 *
	 * @param IfthenpayLpSettlementResult $result As returned by settle_payment().
	 */
	private static function status_for( IfthenpayLpSettlementResult $result ): int {
		switch ( $result->status() ) {
			case IfthenpayLpSettlementResult::SETTLED:
			case IfthenpayLpSettlementResult::ALREADY_SETTLED:
				return 200;
			case IfthenpayLpSettlementResult::REJECTED:
				// The one rejection reason the contract gives its own code; every other rejection
				// (unknown request id, an order that can't be settled, ...) is the same "nothing
				// here to act on" outcome as an unmatched reference.
				return 'amount_mismatch' === $result->reason() ? 409 : 404;
			default: // FAILED — our own side, not a verdict on the payment; ifthenpay should retry.
				return 500;
		}
	}

	/**
	 * A body only on the success path — the literal string 'settled' or 'already_settled' — so a
	 * developer testing this endpoint by hand (curl, a webhook tester) can see which one fired
	 * without inspecting the database. ifthenpay's own retry engine never reads it: any response
	 * other than 200 already triggers a retry regardless of which code it is, which is the same
	 * reason the distinct 403/404/409/500 codes above exist — visibility for a human, not a
	 * functional need on ifthenpay's side. Every rejection stays an empty body; a settlement
	 * outcome is the one thing worth a developer being able to tell apart without a DB lookup.
	 *
	 * @param IfthenpayLpSettlementResult $result As returned by settle_payment().
	 */
	private static function body_for( IfthenpayLpSettlementResult $result ): ?string {
		return $result->is_settled() ? $result->status() : null;
	}
}
