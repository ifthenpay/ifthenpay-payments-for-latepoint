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
 * The Multibanco reference API's own `expiryDays` accepts 0 (expires the same day, per
 * IfthenpayLpExpiry::to_multibanco_days()) up to 730 (003 contracts/api.md) — but this merchant
 * setting starts at 1: same-day expiry is too easy for a customer to miss (see a reference, close
 * the tab, come back a few hours later to find it dead) and nothing in this plugin offers it as a
 * deliberate choice. IfthenpayLpPaymentProcessor's own `<= 0` fallback exists only for a value
 * saved before this floor existed, or written outside this validator. Public: also the settings
 * field's own min/max (IfthenpayLpAdminFormRenderer::render_multibanco_validity_field()), so the
 * two never drift.
 */
class IfthenpayLpMultibancoValidityValidation {

	public const MIN_DAYS = 1;
	public const MAX_DAYS = 730;

	/**
	 * Decides whether a Reference Validity value should block the save it came from.
	 *
	 * @param string $value Raw setting value; empty means "not set" (a sane default is used at
	 *                      payment time — see IfthenpayLpPaymentProcessor::DEFAULT_MULTIBANCO_VALIDITY_DAYS).
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
