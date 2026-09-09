<?php
/**
 * Proves IfthenpayLpMultibancoLeadTimeValidation::check(): empty is allowed (falls back to a
 * default at checkout time), non-numeric is rejected, 0 is rejected (a same-day minimum offers no
 * real payment window), and the range matches its own documented bounds. Same shape as
 * MultibancoValidityValidationTest — both delegate to IfthenpayLpWholeDaysSettingValidation, so
 * this is also indirect coverage of that shared class with a different range.
 *
 * @package ifthenpay-payments-for-latepoint
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/models/validation/ifthenpay-lp-whole-days-setting-validation.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/validation/ifthenpay-lp-multibanco-lead-time-validation.php';

/**
 * Save-time validation decision proof.
 */
final class MultibancoLeadTimeValidationTest extends TestCase {

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
	 * An empty value is allowed — the field can be left blank, a sane default applies at checkout
	 * time (IfthenpayLpPaymentMethodAvailability::DEFAULT_MULTIBANCO_LEAD_TIME_DAYS).
	 */
	public function test_empty_value_is_allowed(): void {
		$this->assertNull( IfthenpayLpMultibancoLeadTimeValidation::check( '' ) );
	}

	/**
	 * Whitespace-only is treated the same as empty.
	 */
	public function test_whitespace_only_is_allowed(): void {
		$this->assertNull( IfthenpayLpMultibancoLeadTimeValidation::check( '   ' ) );
	}

	/**
	 * Zero is rejected — a same-day minimum offers no real payment window at all.
	 */
	public function test_zero_is_rejected(): void {
		$this->assertNotNull( IfthenpayLpMultibancoLeadTimeValidation::check( '0' ) );
	}

	/**
	 * The bottom of the accepted range is allowed — 1 day is a real, if tight, window.
	 */
	public function test_minimum_accepted_value_is_allowed(): void {
		$this->assertNull( IfthenpayLpMultibancoLeadTimeValidation::check( '1' ) );
	}

	/**
	 * The documented top of the accepted range is allowed.
	 */
	public function test_maximum_accepted_value_is_allowed(): void {
		$this->assertNull( IfthenpayLpMultibancoLeadTimeValidation::check( '30' ) );
	}

	/**
	 * A value one above the documented maximum is rejected.
	 */
	public function test_value_above_maximum_is_rejected(): void {
		$this->assertNotNull( IfthenpayLpMultibancoLeadTimeValidation::check( '31' ) );
	}

	/**
	 * A negative value is rejected — never silently treated as "no minimum" or clamped.
	 */
	public function test_negative_value_is_rejected(): void {
		$this->assertNotNull( IfthenpayLpMultibancoLeadTimeValidation::check( '-1' ) );
	}

	/**
	 * A non-numeric value is rejected with a clear message, not a silent cast to 0.
	 */
	public function test_non_numeric_value_is_rejected(): void {
		$this->assertNotNull( IfthenpayLpMultibancoLeadTimeValidation::check( 'two' ) );
	}

	/**
	 * A value with a decimal point is rejected — whole days only.
	 */
	public function test_decimal_value_is_rejected(): void {
		$this->assertNotNull( IfthenpayLpMultibancoLeadTimeValidation::check( '1.5' ) );
	}

	/**
	 * An in-range whole-day value is allowed.
	 */
	public function test_in_range_value_is_allowed(): void {
		$this->assertNull( IfthenpayLpMultibancoLeadTimeValidation::check( '3' ) );
	}
}
