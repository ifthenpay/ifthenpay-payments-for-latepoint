<?php
/**
 * The manual re-check action's own logic (D-5, spec 001) — shared by the admin-only controller
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
 * call (invariant 3) — now with a fresh outbound check against ifthenpay first, via
 * IfthenpayLpTransactionStatus::check(). Confirms the request_id is a real, completed payment
 * before ever calling settle_payment(), closing the gap this class originally shipped without (no
 * independently-verified confirmation endpoint existed yet at the time) — and, since that
 * endpoint's response also carries the Pay By Link's own `id` (OrderId) and the confirmed
 * `Amount`, confirms the txid actually belongs to *this* token specifically, not merely that it
 * belongs to some completed payment: a stale or unrelated txid (e.g. one already used, or one
 * from a different booking's own low-value Pay By Link) is rejected outright rather than trusted.
 */
class IfthenpayLpManualRecheck {

	public const NOT_FOUND        = 'not_found';
	public const UNCONFIRMED      = 'unconfirmed';
	public const MISMATCH         = 'mismatch';
	public const SETTLED          = 'settled';
	public const REJECTED         = 'rejected';
	public const FAILED           = 'failed';
	public const MISSING_ARGUMENT = 'missing_argument';

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
		if ( ! $record || null === $record->request_id ) {
			return array( 'outcome' => self::NOT_FOUND );
		}

		try {
			$confirmation = IfthenpayLpTransactionStatus::check( (string) $record->request_id );
		} catch ( IfthenpayLpApiException $e ) {
			return array( 'outcome' => self::FAILED );
		}

		if ( null === $confirmation ) {
			return array( 'outcome' => self::UNCONFIRMED );
		}

		// The txid is real and completed, but for a different Pay By Link than this one — never
		// settle on it. See the class docblock: this is the check that closes the gap where any
		// completed txid could otherwise be replayed against an unrelated booking.
		if ( $confirmation->order_id !== $token ) {
			return array( 'outcome' => self::MISMATCH );
		}

		IfthenpayLpTransactionRepository::set_verified_method( $token, $confirmation->payment_method );

		$result = IfthenpayLpSettlement::settle_payment(
			(string) $record->request_id,
			array( 'amount' => $confirmation->amount ),
			'manual'
		);

		if ( $result->is_settled() ) {
			return array( 'outcome' => self::SETTLED );
		}

		if ( IfthenpayLpSettlementResult::REJECTED === $result->status() ) {
			return array( 'outcome' => self::REJECTED );
		}

		return array( 'outcome' => self::FAILED );
	}
}
