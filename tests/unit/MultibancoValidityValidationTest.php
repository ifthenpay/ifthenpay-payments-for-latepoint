<?php
/**
 * Proves IfthenpayLpMultibancoValidityValidation::check(): empty is allowed (falls back to a
 * default at payment time), non-numeric is rejected, and the accepted range matches the Multibanco
 * reference API's own verified `expiryDays` bounds (003 contracts/api.md).
 *
 * @package ifthenpay-payments-for-latepoint
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/helpers/ifthenpay-lp-multibanco-validity-validation.php';

/**
 * Save-time validation decision proof.
 */
final class MultibancoValidityValidationTest extends TestCase {

	/**
	 * Boots Brain Monkey and stubs the translation function.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs(
			array(
				'__' => static fn( $text ) => $text,
			)
		);
	}

	/**
	 * Tears down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * An empty value is allowed — the field can be left blank, a sane default applies at payment
	 * time (IfthenpayPaymentsForLatepoint::DEFAULT_MULTIBANCO_VALIDITY_DAYS).
	 */
	public function test_empty_value_is_allowed(): void {
		$this->assertNull( IfthenpayLpMultibancoValidityValidation::check( '' ) );
	}

	/**
	 * Whitespace-only is treated the same as empty.
	 */
	public function test_whitespace_only_is_allowed(): void {
		$this->assertNull( IfthenpayLpMultibancoValidityValidation::check( '   ' ) );
	}

	/**
	 * Zero is a real, valid value — "expires today" — not rejected as falsy/missing.
	 */
	public function test_zero_is_allowed(): void {
		$this->assertNull( IfthenpayLpMultibancoValidityValidation::check( '0' ) );
	}

	/**
	 * The documented top of the accepted range (003 contracts/api.md) is allowed.
	 */
	public function test_maximum_accepted_value_is_allowed(): void {
		$this->assertNull( IfthenpayLpMultibancoValidityValidation::check( '730' ) );
	}

	/**
	 * A value one above the documented maximum is rejected.
	 */
	public function test_value_above_maximum_is_rejected(): void {
		$this->assertNotNull( IfthenpayLpMultibancoValidityValidation::check( '731' ) );
	}

	/**
	 * A negative value is rejected — never silently treated as "no expiry" or clamped.
	 */
	public function test_negative_value_is_rejected(): void {
		$this->assertNotNull( IfthenpayLpMultibancoValidityValidation::check( '-1' ) );
	}

	/**
	 * A non-numeric value is rejected with a clear message, not a silent cast to 0.
	 */
	public function test_non_numeric_value_is_rejected(): void {
		$this->assertNotNull( IfthenpayLpMultibancoValidityValidation::check( 'thirty' ) );
	}

	/**
	 * A value with a decimal point is rejected — whole days only, matching the API's own contract.
	 */
	public function test_decimal_value_is_rejected(): void {
		$this->assertNotNull( IfthenpayLpMultibancoValidityValidation::check( '3.5' ) );
	}

	/**
	 * An in-range whole-day value is allowed.
	 */
	public function test_in_range_value_is_allowed(): void {
		$this->assertNull( IfthenpayLpMultibancoValidityValidation::check( '5' ) );
	}
}
