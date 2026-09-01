<?php
/**
 * Normalises the merchant's single "expiry, in whole days" setting into the three formats
 * ifthenpay's APIs actually take. See contracts/api.md's "Three expiry formats, one setting".
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every method here returns a real value for any valid input — never null, never an empty string
 * — so a caller that always wires the result into the request payload cannot end up omitting it.
 * Omission is exactly what must never happen for Multibanco or Payshop: it means no expiry, which
 * holds a booking slot forever.
 */
class IfthenpayLpExpiry {

	/**
	 * Multibanco's `expiryDays` takes whole days directly. ifthenpay itself rounds an unlisted
	 * value up to the nearest one it accepts (contracts/api.md) — this does not duplicate that.
	 *
	 * @param int $whole_days The merchant's expiry setting, in whole days. `0` is valid: expires
	 *                        today.
	 */
	public static function to_multibanco_days( int $whole_days ): int {
		return $whole_days;
	}

	/**
	 * Payshop's `expiry_date` and Pay By Link's `expiredate` both take an absolute `YYYYMMDD`
	 * date — the same computation, just assigned to a different payload key by the caller.
	 *
	 * @param int      $whole_days The merchant's expiry setting, in whole days from now.
	 * @param int|null $from       Reference Unix timestamp; defaults to now. Exposed for tests —
	 *                             production callers should not pass this.
	 */
	public static function to_date( int $whole_days, ?int $from = null ): string {
		$from      = $from ?? time();
		$timestamp = strtotime( "+{$whole_days} days", $from );

		// strtotime() only fails on an unparseable string, and "+N days" for an int $whole_days
		// always parses — this is a type-safety guard PHPStan needs, not a real failure mode.
		if ( false === $timestamp ) {
			$timestamp = $from;
		}

		return gmdate( 'Ymd', $timestamp );
	}
}
