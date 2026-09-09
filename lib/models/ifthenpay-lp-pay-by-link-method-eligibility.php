<?php
/**
 * Which methods PBL (Pay By Link) actually uses, and which of those it can pre-select — confirmed
 * directly by ifthenpay, not inferred from the catalog: Multibanco and Payshop are deferred
 * reference methods and are never offered through PBL at all, and PBL's own `selected_method`
 * field only pre-selects a narrower subset still (MBWAY, credit card, and Pix) — Google Pay and
 * Apple Pay are valid PBL `accounts` entries but not valid `selected_method` values.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two independent eligibility checks a method code can be run against.
 */
class IfthenpayLpPayByLinkMethodEligibility {

	/**
	 * @var string[]
	 */
	private const NOT_USED_BY_PBL = array( 'MB', 'PAYSHOP' );

	/**
	 * @var string[]
	 */
	private const ELIGIBLE_AS_DEFAULT = array( 'MBWAY', 'CCARD', 'PIX' );

	/**
	 * Whether this method belongs in PBL's own `accounts` field at all.
	 *
	 * @param string $method_code An ifthenpay method code (MB, MBWAY, …).
	 */
	public static function is_listed_in_pay_by_link( string $method_code ): bool {
		return ! in_array( $method_code, self::NOT_USED_BY_PBL, true );
	}

	/**
	 * Whether this method is one PBL's `selected_method` field can point at.
	 *
	 * @param string $method_code An ifthenpay method code (MB, MBWAY, …).
	 */
	public static function is_eligible_as_default( string $method_code ): bool {
		return in_array( $method_code, self::ELIGIBLE_AS_DEFAULT, true );
	}
}
