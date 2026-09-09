<?php
/**
 * The manual re-check action's own logic — shared by the admin-only controller
 * action (OsPaymentsIfthenpaySettingsController::recheck_payment()) and the WP-CLI command
 * (IfthenpayLpCliCommands::recheck_payment()), the two ways to trigger it.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settles through the same settle_payment() the callback route and the realtime polling fallback
 * call, but first confirms the request_id with ifthenpay directly via
 * IfthenpayLpTransactionStatus::check() — closing the gap this class originally shipped without
 * (no independently-verified confirmation endpoint existed yet). That endpoint's response also
 * carries the Pay By Link's own `id` (OrderId) and the confirmed `Amount`, so this additionally
 * confirms the txid belongs to *this* token specifically: a stale or unrelated txid (e.g. one
 * already used, or one from a different booking's own low-value Pay By Link) is rejected outright
 * rather than trusted.
 *
 * Scoped to rows with a request_id — in practice, deferred (Multibanco) payments only. A realtime
 * row settles via the browser's own polling or the inbound callback, both of which decide "paid"
 * directly against the row's token (see resolve_payment_status_from_modal_url() and
 * IfthenpayLpCallbackRestController::settle_realtime()) with no separate confirm-by-identifier step
 * of their own to redo here — run() reports UNSUPPORTED_FOR_KIND for one rather than the misleading
 * NOT_FOUND a merchant or support agent could otherwise read as "this payment doesn't exist".
 */
class IfthenpayLpManualRecheck {

	public const NOT_FOUND            = 'not_found';
	public const UNCONFIRMED          = 'unconfirmed';
	public const MISMATCH             = 'mismatch';
	public const SETTLED              = 'settled';
	public const REJECTED             = 'rejected';
	public const FAILED               = 'failed';
	public const MISSING_ARGUMENT     = 'missing_argument';
	public const UNSUPPORTED_FOR_KIND = 'unsupported_for_kind';

	/**
	 * Runs the re-check for one payment.
	 *
	 * @param string $token Our correlation handle (the repository row's token column).
	 * @return array{outcome:string} One of this class's own constants under 'outcome'.
	 */
	public static function run( string $token ): array {
		if ( '' === $token ) {
			return array( 'outcome' => self::MISSING_ARGUMENT );
		}

		$record = IfthenpayLpTransactionRepository::find_by_token( $token );
		if ( ! $record ) {
			return array( 'outcome' => self::NOT_FOUND );
		}

		// A realtime row never has a request_id (it settles via the browser's own polling or the
		// inbound callback, both keyed by token — see IfthenpayLpTransactionRepository::insert()'s
		// callers). Told apart from NOT_FOUND: the record is real, this action just doesn't apply to
		// it, which is a materially different thing to tell whoever is troubleshooting a payment.
		if ( null === $record->request_id ) {
			return array( 'outcome' => self::UNSUPPORTED_FOR_KIND );
		}

		try {
			$confirmation = IfthenpayLpTransactionStatus::check( (string) $record->request_id );
		} catch ( IfthenpayLpApiException $e ) {
			return array( 'outcome' => self::FAILED );
		}

		if ( null === $confirmation ) {
			return array( 'outcome' => self::UNCONFIRMED );
		}

		// Also corrects `method` to the confirmed value in the same write, whenever order_id
		// matches — settling itself still happens below, through settle_payment(), not here.
		IfthenpayLpTransactionRepository::record_verification( $token, (string) $record->request_id, $confirmation );

		// The txid is real and completed, but for a different Pay By Link than this one — never
		// settle on it. See the class docblock: this is the check that closes the gap where any
		// completed txid could otherwise be replayed against an unrelated booking.
		if ( $confirmation->order_id !== $token ) {
			return array( 'outcome' => self::MISMATCH );
		}

		$result = IfthenpayLpSettlement::settle_payment(
			(string) $record->request_id,
			array( 'amount' => $confirmation->amount ),
			'manual',
			$token
		);

		if ( $result->is_settled() ) {
			return array( 'outcome' => self::SETTLED );
		}

		if ( IfthenpayLpSettlementResult::REJECTED === $result->status() ) {
			return array( 'outcome' => self::REJECTED );
		}

		return array( 'outcome' => self::FAILED );
	}

	/**
	 * A merchant-facing message for a run() outcome — shared by the admin controller action and
	 * the WP-CLI command, which both surface the same wording except for MISSING_ARGUMENT and
	 * NOT_FOUND (a CLI-specific usage hint is more useful there than the admin UI's generic
	 * message), which they keep their own text for instead of calling this.
	 *
	 * @param string $outcome One of this class's own constants.
	 */
	public static function default_message_for( string $outcome ): string {
		switch ( $outcome ) {
			case self::SETTLED:
				return __( 'Payment confirmed and settled.', 'ifthenpay-payments-for-latepoint' );
			case self::MISSING_ARGUMENT:
				return __( 'Missing payment reference.', 'ifthenpay-payments-for-latepoint' );
			case self::NOT_FOUND:
				return __( 'Payment record not found.', 'ifthenpay-payments-for-latepoint' );
			case self::UNCONFIRMED:
				return __( 'ifthenpay does not recognise this payment as completed — nothing was settled.', 'ifthenpay-payments-for-latepoint' );
			case self::MISMATCH:
				return __( 'ifthenpay confirms this transaction, but it belongs to a different booking — nothing was settled.', 'ifthenpay-payments-for-latepoint' );
			case self::UNSUPPORTED_FOR_KIND:
				return __( 'This payment does not support manual re-check — it settles automatically once ifthenpay confirms it (inbound callback) or the customer returns to complete checkout.', 'ifthenpay-payments-for-latepoint' );
			case self::REJECTED:
				return __( 'This payment could not be settled — the stored details no longer match (amount, or the order is no longer open).', 'ifthenpay-payments-for-latepoint' );
			default:
				return __( 'Could not settle this payment right now. Please try again shortly.', 'ifthenpay-payments-for-latepoint' );
		}
	}
}
