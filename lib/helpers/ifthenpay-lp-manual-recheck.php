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
 * call (invariant 3) — but, unlike those, with no fresh outbound check against ifthenpay first.
 * The verified reference-listing endpoint for a single request_id lookup (ifthenpay's own
 * "payments_list") has no independently-verified raw HTTP shape in this add-on's own research yet
 * (unlike every other operation it depends on) — using it here blind would risk asserting a
 * payment is confirmed when it is not. Until that spike is done, this is a considered,
 * capability-gated override: whoever can call this (settings__edit for the controller action, WP
 * shell access for the CLI command) is trusted to have confirmed the payment on ifthenpay's own
 * backoffice first.
 */
class IfthenpayLpManualRecheck {

	public const NOT_FOUND        = 'not_found';
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

		$result = IfthenpayLpSettlement::settle_payment(
			(string) $record->request_id,
			array( 'amount' => $record->amount ),
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
