<?php
/**
 * Minimal OsFormHelper stand-in for unit tests, which never boot LatePoint.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! class_exists( 'OsFormHelper' ) ) {
	/**
	 * An OsFormHelper stand-in. Its own field markup is LatePoint's concern, not this plugin's —
	 * a fixed placeholder is enough to prove a renderer calls it and moves on.
	 */
	class OsFormHelper {

		/**
		 * A fixed placeholder — the real field markup is LatePoint's own concern.
		 *
		 * @param mixed ...$args Unused; kept only so call sites don't need special-casing.
		 * @phpstan-ignore missingType.parameter (deliberately untyped variadic stand-in for a LatePoint core signature this plugin doesn't own)
		 */
		public static function select_field( ...$args ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- fixed placeholder; the real signature's args are LatePoint's own concern, not reproduced here.
			return '<select></select>';
		}
	}
}
