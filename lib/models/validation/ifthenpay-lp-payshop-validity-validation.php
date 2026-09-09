<?php
/**
 * The Payshop reference-validity save-time validation decision — same shape as
 * IfthenpayLpMultibancoValidityValidation, its own setting rather than sharing Multibanco's.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MIN_DAYS matches Multibanco's own floor for the same reason: same-day expiry is too easy for a
 * customer to miss. MAX_DAYS is more conservative than Multibanco's confirmed 730 — ifthenpay's own
 * API does not document a maximum for Payshop; 365 is the guard rail ifthenpay's own official PHP
 * SDK applies client-side (not a confirmed server-enforced limit, the closest available signal).
 * IfthenpayLpPaymentProcessor's own `<= 0` fallback exists only for a value saved before this floor
 * existed, or written outside this validator.
 */
class IfthenpayLpPayshopValidityValidation {

	public const MIN_DAYS = 1;
	public const MAX_DAYS = 365;

	/**
	 * Decides whether a Reference Validity value should block the save it came from. The range
	 * check itself is IfthenpayLpWholeDaysSettingValidation's own — nothing here is specific to
	 * this setting beyond its bounds and label.
	 *
	 * @param string $value Raw setting value; empty means "not set" (a sane default is used at
	 *                      payment time — see IfthenpayLpPaymentProcessor::DEFAULT_PAYSHOP_VALIDITY_DAYS).
	 * @return string|null An error message to reject the save with, or null to allow it.
	 */
	public static function check( string $value ): ?string {
		return IfthenpayLpWholeDaysSettingValidation::check(
			$value,
			self::MIN_DAYS,
			self::MAX_DAYS,
			__( 'Reference validity', 'ifthenpay-payments-for-latepoint' )
		);
	}
}
