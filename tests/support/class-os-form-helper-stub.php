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

		/**
		 * Reproduces just enough of the real toggler_field() (LatePoint core,
		 * lib/helpers/form_helper.php) for a test to see whether a row rendered on/off — a hidden
		 * input plus a `.os-toggler` div carrying the on/off class, which is the actual state the
		 * real component's own click handler reads and flips.
		 *
		 * @param string $name      Field name, used to derive the hidden input's id.
		 * @param string $label     Unused by any current caller; kept for signature parity.
		 * @param bool   $is_active Initial on/off state.
		 * @param mixed  ...$args   Unused; kept for signature parity with the real method.
		 */
		public static function toggler_field( string $name, string $label, bool $is_active, ...$args ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $label/$args unused by this stand-in; kept for signature parity with the real method.
			$status = $is_active ? 'on' : 'off';
			$id     = preg_replace( '/[^0-9a-zA-Z_]/', '_', strtolower( $name ) );

			return '<input type="hidden" id="' . esc_attr( $id ) . '" value="' . esc_attr( $status ) . '" />'
				. '<div class="os-toggler ' . esc_attr( $status ) . '" data-for="' . esc_attr( $id ) . '"></div>';
		}
	}
}
