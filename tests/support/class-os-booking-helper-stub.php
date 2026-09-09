<?php
/**
 * Minimal OsBookingHelper stand-in for unit tests, which never boot LatePoint.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! class_exists( 'OsBookingHelper' ) ) {
	/**
	 * An OsBookingHelper stand-in with only the surface the plugin's own renderers call — a
	 * test-seedable static value standing in for the real `timeslot_blocking_statuses` setting.
	 */
	class OsBookingHelper {

		/**
		 * A test seeds this directly; the real helper instead derives it from the
		 * `timeslot_blocking_statuses` option (comma-separated), defaulting to `array('approved')`.
		 *
		 * @var string[]
		 */
		public static array $timeslot_blocking_statuses = array( 'approved' );

		/**
		 * Mirrors the real helper's own signature.
		 *
		 * @return string[]
		 */
		public static function get_timeslot_blocking_statuses(): array {
			return self::$timeslot_blocking_statuses;
		}
	}
}
