<?php
/**
 * Proves IfthenpayLpPayByLinkMethodEligibility, the one place this plugin encodes which methods
 * PBL (Pay By Link) actually uses, and which of those it can pre-select — confirmed directly by
 * ifthenpay, not derivable from the method catalog itself.
 *
 * @package ifthenpay-payments-for-latepoint
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/helpers/ifthenpay-lp-pay-by-link-method-eligibility.php';

/**
 * Method eligibility proof.
 */
final class PayByLinkMethodEligibilityTest extends TestCase {

	/**
	 * Multibanco and Payshop are deferred-reference methods — never offered through PBL at all.
	 */
	public function test_mb_and_payshop_are_not_listed_in_pay_by_link(): void {
		$this->assertFalse( IfthenpayLpPayByLinkMethodEligibility::is_listed_in_pay_by_link( 'MB' ) );
		$this->assertFalse( IfthenpayLpPayByLinkMethodEligibility::is_listed_in_pay_by_link( 'PAYSHOP' ) );
	}

	/**
	 * Every other catalog method is a real PBL `accounts` entry.
	 */
	public function test_other_methods_are_listed_in_pay_by_link(): void {
		foreach ( array( 'MBWAY', 'CCARD', 'GOOGLE', 'APPLE', 'PIX' ) as $method_code ) {
			$this->assertTrue( IfthenpayLpPayByLinkMethodEligibility::is_listed_in_pay_by_link( $method_code ) );
		}
	}

	/**
	 * Only MBWAY, credit card, and Pix are valid `selected_method` values — Google Pay and Apple
	 * Pay are valid `accounts` entries but not valid defaults, and Multibanco/Payshop are neither.
	 */
	public function test_only_mbway_ccard_and_pix_are_eligible_as_default(): void {
		$this->assertTrue( IfthenpayLpPayByLinkMethodEligibility::is_eligible_as_default( 'MBWAY' ) );
		$this->assertTrue( IfthenpayLpPayByLinkMethodEligibility::is_eligible_as_default( 'CCARD' ) );
		$this->assertTrue( IfthenpayLpPayByLinkMethodEligibility::is_eligible_as_default( 'PIX' ) );

		foreach ( array( 'MB', 'PAYSHOP', 'GOOGLE', 'APPLE' ) as $method_code ) {
			$this->assertFalse( IfthenpayLpPayByLinkMethodEligibility::is_eligible_as_default( $method_code ) );
		}
	}

	/**
	 * The two checks default in opposite directions for a code neither list mentions (a method
	 * ifthenpay adds after this plugin's own list was written): listing is a denylist, so an
	 * unrecognized method is assumed to behave like every other ordinary PBL method and is listed;
	 * default-eligibility is an allowlist, so it is not trusted as a default without being added
	 * here first.
	 */
	public function test_unknown_method_code_is_listed_but_not_eligible_as_default(): void {
		$this->assertTrue( IfthenpayLpPayByLinkMethodEligibility::is_listed_in_pay_by_link( 'NOT-A-REAL-METHOD' ) );
		$this->assertFalse( IfthenpayLpPayByLinkMethodEligibility::is_eligible_as_default( 'NOT-A-REAL-METHOD' ) );
	}
}
