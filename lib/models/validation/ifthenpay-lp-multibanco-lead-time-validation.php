<?php
/**
 * The Multibanco minimum-lead-time save-time validation decision — same shape as
 * IfthenpayLpMultibancoValidityValidation, so it's unit-testable without a real OsSettingsModel/
 * WordPress boot.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Floor is 1, not 0: at 0 days, "the appointment is later today" would still offer Multibanco with
 * no real payment window at all. Default (IfthenpayLpPaymentMethodAvailability's own fallback) is 2
 * — see that class's own docblock for why 1 is allowed but not the default.
 */
class IfthenpayLpMultibancoLeadTimeValidation {

	public const MIN_DAYS = 1;
	public const MAX_DAYS = 30;

	/**
	 * Decides whether a Minimum Lead Time value should block the save it came from. The range
	 * check itself is IfthenpayLpWholeDaysSettingValidation's own — nothing here is specific to
	 * this setting beyond its bounds and label.
	 *
	 * @param string $value Raw setting value; empty means "not set" (a sane default is used at
	 *                      checkout time — see IfthenpayLpPaymentMethodAvailability's own default).
	 * @return string|null An error message to reject the save with, or null to allow it.
	 */
	public static function check( string $value ): ?string {
		return IfthenpayLpWholeDaysSettingValidation::check(
			$value,
			self::MIN_DAYS,
			self::MAX_DAYS,
			__( 'Minimum lead time', 'ifthenpay-payments-for-latepoint' )
		);
	}
}
