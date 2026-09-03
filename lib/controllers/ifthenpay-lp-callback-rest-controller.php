<?php
/**
 * The inbound payment-notification endpoint — a plain WP REST route, not one of LatePoint's own
 * `latepoint_route_call` actions (payments-ifthenpay-checkout-controller.php). See
 * specs/001-multibanco-deferred/plan.md §4: a stable public URL independent of LatePoint's
 * admin-post plumbing, kept separate from the add-on's browser-facing controller actions.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements the validation order and response mapping from
 * specs/001-multibanco-deferred/contracts/callback.md exactly: cheapest, most decisive checks
 * first (parameter presence, then a fast unlocked lookup, then the anti-phishing key) before ever
 * calling IfthenpayLpSettlement::settle_payment() — which owns the lock, the authoritative
 * already-settled check, and the authoritative amount check. No response body in any case.
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

		$result = IfthenpayLpSettlement::settle_payment(
			$params->request_id,
			array( 'amount' => $params->amount ),
			'callback',
			$params->reference
		);

		return new WP_REST_Response( null, self::status_for( $result ) );
	}

	/**
	 * Maps a settlement outcome to the response table in contracts/callback.md.
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
}
