<?php
/**
 * Minimal OsSettingsHelper stand-in for unit tests, which never boot LatePoint.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! class_exists( 'OsSettingsHelper' ) ) {
	/**
	 * An OsSettingsHelper stand-in with only the surface the plugin's own renderers call —
	 * a plain in-memory map a test can seed directly, standing in for the real settings table.
	 */
	class OsSettingsHelper {

		/**
		 * Values a test seeded directly.
		 *
		 * @var array<string,mixed>
		 */
		public static array $values = array();

		/**
		 * Returns a seeded value, or $default when nothing was seeded for $name.
		 *
		 * @param string $name          Setting name.
		 * @param mixed  $fallback_value Fallback value.
		 * @return mixed
		 */
		public static function get_settings_value( string $name, $fallback_value = null ) {
			return self::$values[ $name ] ?? $fallback_value;
		}
	}
}
