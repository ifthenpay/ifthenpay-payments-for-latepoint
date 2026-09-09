<?php
/**
 * Proves IfthenpayLpExpiry: Multibanco's whole-days passthrough, and Payshop/Pay By
 * Link's YYYYMMDD date computation — and that neither ever omits a value, which is the one thing
 * that must never happen for either method (omission means no expiry, holding a booking slot
 * forever).
 *
 * @package ifthenpay-payments-for-latepoint
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/helpers/ifthenpay-lp-expiry.php';

/**
 * Expiry normalisation proof.
 */
final class ExpiryTest extends TestCase {

	/**
	 * Multibanco takes whole days directly, unchanged.
	 */
	public function test_multibanco_days_is_a_direct_passthrough(): void {
		$this->assertSame( 3, IfthenpayLpExpiry::to_multibanco_days( 3 ) );
	}

	/**
	 * Zero whole days is a valid Multibanco value — "expires today" — not "no expiry", so it
	 * must come back as 0, not be treated as falsy/omitted.
	 */
	public function test_zero_multibanco_days_is_preserved_not_omitted(): void {
		$this->assertSame( 0, IfthenpayLpExpiry::to_multibanco_days( 0 ) );
	}

	/**
	 * Payshop/Pay By Link: whole days from a fixed reference point become an absolute YYYYMMDD
	 * date.
	 */
	public function test_date_adds_whole_days_to_the_reference_point(): void {
		$reference = (int) mktime( 12, 0, 0, 1, 15, 2026 ); // 2026-01-15 12:00:00.

		$this->assertSame( '20260118', IfthenpayLpExpiry::to_date( 3, $reference ) );
	}

	/**
	 * Zero whole days is "today" for the date-based methods too — the same value, not an
	 * omitted/empty result.
	 */
	public function test_zero_days_returns_todays_date_not_an_empty_value(): void {
		$reference = (int) mktime( 12, 0, 0, 1, 15, 2026 );

		$this->assertSame( '20260115', IfthenpayLpExpiry::to_date( 0, $reference ) );
	}

	/**
	 * Without an explicit reference point, the result is always a real, present-day-or-later
	 * YYYYMMDD string — never empty, which is the guarantee production callers rely on.
	 */
	public function test_date_without_explicit_reference_still_returns_a_real_value(): void {
		$result = IfthenpayLpExpiry::to_date( 5 );

		$this->assertMatchesRegularExpression( '/^\d{8}$/', $result );
	}
}
