<?php
/**
 * Shared save-time validation for every "whole number of days, within a range" setting this add-on
 * has — reference validity (IfthenpayLpMultibancoValidityValidation) and minimum lead time
 * (IfthenpayLpMultibancoLeadTimeValidation) today, and the natural reuse point for a future deferred
 * method's own equivalent settings (Payshop, spec 002 — not built yet, but the range-check logic
 * itself has nothing method-specific about it).
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One check, parameterised by the caller's own bounds and field name — not a setting-specific
 * class of its own, since the logic is identical for every caller and only the range/label differ.
 */
class IfthenpayLpWholeDaysSettingValidation {

	/**
	 * Decides whether a whole-days value should block the save it came from.
	 *
	 * @param string $value       Raw setting value; empty means "not set" (a sane default is used
	 *                            elsewhere — every caller has its own).
	 * @param int    $min         Smallest accepted value, inclusive.
	 * @param int    $max         Largest accepted value, inclusive.
	 * @param string $field_label Already-translated field name, used in the rejection message.
	 * @return string|null An error message to reject the save with, or null to allow it.
	 */
	public static function check( string $value, int $min, int $max, string $field_label ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		if ( ! ctype_digit( $value ) ) {
			/* translators: %s: field name */
			return sprintf( __( '%s must be a whole number of days.', 'ifthenpay-payments-for-latepoint' ), $field_label );
		}

		$days = (int) $value;
		if ( $days < $min || $days > $max ) {
			return sprintf(
				/* translators: 1: field name, 2: minimum accepted days, 3: maximum accepted days */
				__( '%1$s must be between %2$d and %3$d days.', 'ifthenpay-payments-for-latepoint' ),
				$field_label,
				$min,
				$max
			);
		}

		return null;
	}
}
