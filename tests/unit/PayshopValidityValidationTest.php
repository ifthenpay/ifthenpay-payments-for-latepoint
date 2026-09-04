<?php
/**
 * Proves IfthenpayLpPayshopValidityValidation::check(): empty is allowed (falls back to a default
 * at checkout time), 0 is rejected, and the range matches its own documented bounds — narrower than
 * Multibanco's own, since Payshop's own maximum is not API-confirmed. Same shape as
 * MultibancoValidityValidationTest — both delegate to IfthenpayLpWholeDaysSettingValidation.
 *
 * @package ifthenpay-payments-for-latepoint
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/models/validation/ifthenpay-lp-whole-days-setting-validation.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/validation/ifthenpay-lp-payshop-validity-validation.php';

/**
 * Save-time validation decision proof.
 */
final class PayshopValidityValidationTest extends TestCase {

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
	 * time (IfthenpayLpPaymentProcessor::DEFAULT_PAYSHOP_VALIDITY_DAYS).
	 */
	public function test_empty_value_is_allowed(): void {
		$this->assertNull( IfthenpayLpPayshopValidityValidation::check( '' ) );
	}

	/**
	 * Zero is rejected — same-day expiry is too easy for a customer to miss.
	 */
	public function test_zero_is_rejected(): void {
		$this->assertNotNull( IfthenpayLpPayshopValidityValidation::check( '0' ) );
	}

	/**
	 * The bottom of the accepted range is allowed.
	 */
	public function test_minimum_accepted_value_is_allowed(): void {
		$this->assertNull( IfthenpayLpPayshopValidityValidation::check( '1' ) );
	}

	/**
	 * The documented top of the accepted range is allowed — 365, not Multibanco's confirmed 730,
	 * since Payshop's own API does not document a maximum.
	 */
	public function test_maximum_accepted_value_is_allowed(): void {
		$this->assertNull( IfthenpayLpPayshopValidityValidation::check( '365' ) );
	}

	/**
	 * A value one above the documented maximum is rejected.
	 */
	public function test_value_above_maximum_is_rejected(): void {
		$this->assertNotNull( IfthenpayLpPayshopValidityValidation::check( '366' ) );
	}

	/**
	 * A non-numeric value is rejected with a clear message, not a silent cast to 0.
	 */
	public function test_non_numeric_value_is_rejected(): void {
		$this->assertNotNull( IfthenpayLpPayshopValidityValidation::check( 'two' ) );
	}

	/**
	 * An in-range whole-day value is allowed.
	 */
	public function test_in_range_value_is_allowed(): void {
		$this->assertNull( IfthenpayLpPayshopValidityValidation::check( '3' ) );
	}
}
