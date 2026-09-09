<?php
/**
 * Proves IfthenpayLpPayshopLeadTimeValidation::check(): empty is allowed (falls back to a default
 * at checkout time), 0 is rejected (a same-day minimum offers no real window to go pay in person),
 * and the range matches its own documented bounds — same shape as Multibanco's own. Same shape as
 * MultibancoLeadTimeValidationTest — both delegate to IfthenpayLpWholeDaysSettingValidation.
 *
 * @package ifthenpay-payments-for-latepoint
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/models/validation/ifthenpay-lp-whole-days-setting-validation.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/validation/ifthenpay-lp-payshop-lead-time-validation.php';

/**
 * Save-time validation decision proof.
 */
final class PayshopLeadTimeValidationTest extends TestCase {

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
	 * An empty value is allowed — a sane default applies at checkout time
	 * (IfthenpayLpPaymentMethodAvailability::DEFAULT_PAYSHOP_LEAD_TIME_DAYS).
	 */
	public function test_empty_value_is_allowed(): void {
		$this->assertNull( IfthenpayLpPayshopLeadTimeValidation::check( '' ) );
	}

	/**
	 * Zero is rejected — a same-day minimum offers no real window to go pay in person at all.
	 */
	public function test_zero_is_rejected(): void {
		$this->assertNotNull( IfthenpayLpPayshopLeadTimeValidation::check( '0' ) );
	}

	/**
	 * The bottom of the accepted range is allowed.
	 */
	public function test_minimum_accepted_value_is_allowed(): void {
		$this->assertNull( IfthenpayLpPayshopLeadTimeValidation::check( '1' ) );
	}

	/**
	 * The documented top of the accepted range is allowed.
	 */
	public function test_maximum_accepted_value_is_allowed(): void {
		$this->assertNull( IfthenpayLpPayshopLeadTimeValidation::check( '30' ) );
	}

	/**
	 * A value one above the documented maximum is rejected.
	 */
	public function test_value_above_maximum_is_rejected(): void {
		$this->assertNotNull( IfthenpayLpPayshopLeadTimeValidation::check( '31' ) );
	}

	/**
	 * A non-numeric value is rejected with a clear message, not a silent cast to 0.
	 */
	public function test_non_numeric_value_is_rejected(): void {
		$this->assertNotNull( IfthenpayLpPayshopLeadTimeValidation::check( 'two' ) );
	}

	/**
	 * An in-range whole-day value is allowed.
	 */
	public function test_in_range_value_is_allowed(): void {
		$this->assertNull( IfthenpayLpPayshopLeadTimeValidation::check( '3' ) );
	}
}
