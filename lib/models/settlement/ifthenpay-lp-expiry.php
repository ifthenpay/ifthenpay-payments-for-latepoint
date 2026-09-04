<?php
/**
 * Normalises the merchant's single "expiry, in whole days" setting into the three formats
 * ifthenpay's APIs actually take: Multibanco wants whole days, Payshop wants a target date, and
 * Pay By Link wants an expiry timestamp. Also handles the reverse direction: turning a reference
 * API response's own returned expiry date into the repository's `expires_at` DATETIME.
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
	 * value up to the nearest one it accepts — this does not duplicate that.
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

	/**
	 * The reverse direction: turns the Multibanco reference API's own returned `ExpiryDate`
	 * (`DD-MM-YYYY`, verified against a real sandbox call — see
	 * IfthenpayLpMultibancoReference) into the repository's `expires_at` DATETIME — end of that
	 * day, plus a margin for callback delivery, never `now + N days` (a reference created in the
	 * morning with a one-day window must still be payable that evening; computing from creation
	 * time instead of the real deadline would cancel it hours too early). Nothing is
	 * assumed about the merchant's own validity setting here — this trusts the value ifthenpay
	 * itself returned.
	 *
	 * @param string $expiry_date_ddmmyyyy The API response's own `ExpiryDate` field.
	 * @param int    $margin_hours         Hours added after end-of-day, covering ifthenpay's own
	 *                                     callback retry window (up to ~5h40m — first 8 attempts at
	 *                                     five-minute intervals, the rest hourly).
	 */
	public static function to_expires_at_datetime( string $expiry_date_ddmmyyyy, int $margin_hours = 24 ): string {
		$parsed = DateTime::createFromFormat( '!d-m-Y', $expiry_date_ddmmyyyy, new DateTimeZone( 'UTC' ) );
		if ( false === $parsed ) {
			// Unparseable is not a valid reason to hold a slot forever — fall back to "now", so the
			// expiry job still reclaims it eventually rather than never.
			$parsed = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		}

		$parsed->setTime( 23, 59, 59 );
		$parsed->modify( "+{$margin_hours} hours" );

		return $parsed->format( 'Y-m-d H:i:s' );
	}

	/**
	 * The same computation as to_expires_at_datetime(), but for Payshop: its own reference
	 * creation response carries no expiry field to trust back (confirmed against ifthenpay's own
	 * PHP SDK and public docs — success returns only Code/Message/Reference/RequestId), so this
	 * trusts what this add-on itself sent as `validade` (to_date()'s own `YYYYMMDD` output)
	 * instead of a value ifthenpay echoed back.
	 *
	 * @param string $expiry_date_ymd The `YYYYMMDD` value already sent as `validade`.
	 * @param int    $margin_hours    Hours added after end-of-day, covering ifthenpay's own
	 *                                callback retry window.
	 */
	public static function to_expires_at_datetime_from_ymd( string $expiry_date_ymd, int $margin_hours = 24 ): string {
		$parsed = DateTime::createFromFormat( '!Ymd', $expiry_date_ymd, new DateTimeZone( 'UTC' ) );
		if ( false === $parsed ) {
			// Unparseable is not a valid reason to hold a slot forever — fall back to "now", so the
			// expiry job still reclaims it eventually rather than never.
			$parsed = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		}

		$parsed->setTime( 23, 59, 59 );
		$parsed->modify( "+{$margin_hours} hours" );

		return $parsed->format( 'Y-m-d H:i:s' );
	}
}
