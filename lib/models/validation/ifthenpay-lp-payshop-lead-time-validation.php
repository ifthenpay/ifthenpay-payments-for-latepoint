<?php
/**
 * The Payshop minimum-lead-time save-time validation decision — same shape as
 * IfthenpayLpMultibancoLeadTimeValidation, its own setting rather than sharing Multibanco's.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Floor is 1, not 0, same reasoning as Multibanco's own: at 0 days, "the appointment is later
 * today" would still offer Payshop with no real window to go pay in person at all. Default
 * (IfthenpayLpPaymentMethodAvailability's own fallback) is 2 — same starting point as Multibanco,
 * a merchant is free to raise it if a physical trip needs more notice.
 */
class IfthenpayLpPayshopLeadTimeValidation {

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
