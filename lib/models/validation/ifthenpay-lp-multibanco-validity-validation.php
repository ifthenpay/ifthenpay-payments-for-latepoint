<?php
/**
 * The Multibanco reference-validity save-time validation decision — kept separate from the
 * `latepoint_model_validate` hook glue (main plugin file), the same shape as
 * IfthenpayLpBackofficeKeyValidation, so it's unit-testable without a real OsSettingsModel/
 * WordPress boot.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Range verified against the Multibanco reference API's own accepted `expiryDays` values (003
 * contracts/api.md): 0, or one of 1-31, 45, 60, 90, 120, 180, 365, 730 — an unlisted value in
 * between rounds up to the next one, so any whole number in this range is safe to send, not only
 * the exact listed steps.
 */
class IfthenpayLpMultibancoValidityValidation {

	private const MIN_DAYS = 0;
	private const MAX_DAYS = 730;

	/**
	 * Decides whether a Reference Validity value should block the save it came from.
	 *
	 * @param string $value Raw setting value; empty means "not set" (a sane default is used at
	 *                      payment time — see IfthenpayPaymentsForLatepoint::DEFAULT_MULTIBANCO_VALIDITY_DAYS).
	 * @return string|null An error message to reject the save with, or null to allow it.
	 */
	public static function check( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		if ( ! ctype_digit( $value ) ) {
			return __( 'Reference validity must be a whole number of days.', 'ifthenpay-payments-for-latepoint' );
		}

		$days = (int) $value;
		if ( $days < self::MIN_DAYS || $days > self::MAX_DAYS ) {
			return sprintf(
				/* translators: 1: minimum accepted days, 2: maximum accepted days */
				__( 'Reference validity must be between %1$d and %2$d days.', 'ifthenpay-payments-for-latepoint' ),
				self::MIN_DAYS,
				self::MAX_DAYS
			);
		}

		return null;
	}
}
