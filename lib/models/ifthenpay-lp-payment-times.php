<?php
/**
 * Buckets this add-on's payment methods into LatePoint's "now" vs "later" payment-time groups —
 * the hinge that lets a deferred method (Multibanco) commit a booking unpaid instead of blocking
 * checkout. See specs/001-multibanco-deferred/plan.md §1 and research.md's own note that
 * `add_all_payment_methods_to_payment_times()` previously hardcoded every method into "now",
 * silently contradicting a method's own declared `time_type`.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kept separate from the main plugin file's filter callback so the bucketing itself is
 * unit-testable without booting LatePoint — only the two `LATEPOINT_PAYMENT_TIME_*` constants are
 * needed, not a real settings/DB layer.
 */
class IfthenpayLpPaymentTimes {

	/**
	 * Adds every given payment method into $payment_times, under LATEPOINT_PAYMENT_TIME_LATER when
	 * its own `time_type` is `'later'`, LATEPOINT_PAYMENT_TIME_NOW otherwise (including when the
	 * key is absent, matching LatePoint's own default).
	 *
	 * @param array<string,mixed>        $payment_times   The filter's existing value, keyed by
	 *                                                     LATEPOINT_PAYMENT_TIME_* then method code.
	 * @param array<string,array<mixed>> $payment_methods This add-on's methods, keyed by code —
	 *                                                     each entry carries its own `time_type`.
	 * @param string                     $processor_code  This add-on's processor code.
	 * @return array<string,mixed>
	 */
	public static function add_methods( array $payment_times, array $payment_methods, string $processor_code ): array {
		foreach ( $payment_methods as $method_code => $method_info ) {
			$time = ( 'later' === ( $method_info['time_type'] ?? 'now' ) )
				? LATEPOINT_PAYMENT_TIME_LATER
				: LATEPOINT_PAYMENT_TIME_NOW;

			$payment_times[ $time ][ $method_code ][ $processor_code ] = $method_info;
		}

		return $payment_times;
	}
}
