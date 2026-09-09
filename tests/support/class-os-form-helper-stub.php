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
		 * Reproduces just enough of the real select_field() (LatePoint core,
		 * lib/helpers/form_helper.php) for a test to see which options actually got built and
		 * which one is selected — everything else (theme classes, validation attrs) is LatePoint's
		 * own concern and is not reproduced.
		 *
		 * @param string                      $name           Field name.
		 * @param string                      $label          Label text.
		 * @param array<string,string>|string $options `{value: label}`, or a raw `<option>` HTML
		 *                                              string — the real method accepts either,
		 *                                              inserting a string as-is instead of
		 *                                              iterating it.
		 * @param mixed                       $selected_value Currently selected value; the real method
		 *                                                     takes no type of its own (a loose `==`
		 *                                                     comparison), so callers may pass `null`. Only
		 *                                                     applied when $options is an array — a raw
		 *                                                     HTML string is expected to embed its own
		 *                                                     `selected` attribute already.
		 * @param array<string,mixed>         ...$rest         Unused by this stand-in; kept for signature parity.
		 * @phpstan-ignore missingType.parameter (deliberately untyped variadic stand-in for a LatePoint core signature this plugin doesn't own)
		 */
		public static function select_field( string $name, string $label, $options = array(), $selected_value = '', ...$rest ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $rest unused by this stand-in; kept for signature parity with the real method.
			if ( is_array( $options ) ) {
				$option_tags = '';
				foreach ( $options as $value => $option_label ) {
					$selected     = ( (string) $value == $selected_value ) ? ' selected' : ''; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- reproducing the real method's own loose `==` comparison against a possibly-null $selected_value.
					$option_tags .= '<option value="' . esc_attr( $value ) . '"' . $selected . '>' . esc_html( $option_label ) . '</option>';
				}
			} else {
				$option_tags = $options;
			}

			return '<div class="os-form-group os-form-select-group"><label>' . esc_html( $label ) . '</label>'
				. '<select name="' . esc_attr( $name ) . '">' . $option_tags . '</select></div>';
		}

		/**
		 * A fixed placeholder — the real field markup is LatePoint's own concern.
		 *
		 * @param mixed ...$args Unused; kept only so call sites don't need special-casing.
		 * @phpstan-ignore missingType.parameter (deliberately untyped variadic stand-in for a LatePoint core signature this plugin doesn't own)
		 */
		public static function text_field( ...$args ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- fixed placeholder; the real signature's args are LatePoint's own concern, not reproduced here.
			return '<input type="text" />';
		}

		/**
		 * A fixed placeholder — the real field markup is LatePoint's own concern.
		 *
		 * @param mixed ...$args Unused; kept only so call sites don't need special-casing.
		 * @phpstan-ignore missingType.parameter (deliberately untyped variadic stand-in for a LatePoint core signature this plugin doesn't own)
		 */
		public static function password_field( ...$args ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- fixed placeholder; the real signature's args are LatePoint's own concern, not reproduced here.
			return '<input type="password" />';
		}

		/**
		 * A fixed placeholder — the real field markup is LatePoint's own concern.
		 *
		 * @param mixed ...$args Unused; kept only so call sites don't need special-casing.
		 * @phpstan-ignore missingType.parameter (deliberately untyped variadic stand-in for a LatePoint core signature this plugin doesn't own)
		 */
		public static function number_field( ...$args ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- fixed placeholder; the real signature's args are LatePoint's own concern, not reproduced here.
			return '<input type="number" />';
		}

		/**
		 * Reproduces just enough of the real checkbox_field() (LatePoint core,
		 * lib/helpers/form_helper.php) for a test to see the value, checked state, disabled
		 * state, and label HTML a renderer built — the label is passed through as-is, matching
		 * the real method's own `wp_kses_post( $label )` (rich HTML labels are the whole point of
		 * using this over a plain checkbox).
		 *
		 * @param string               $name        Field name.
		 * @param string               $label       Label HTML, already built by the caller.
		 * @param string               $value       Checkbox value.
		 * @param bool                 $is_checked  Initial checked state.
		 * @param array<string,mixed>  $atts        Extra attributes; `disabled` is reproduced — matching the real method's own `atts_string_from_array()`, presence of the key renders the attribute regardless of its value, so a caller must omit the key entirely to leave the checkbox enabled.
		 * @param array<string,string> $wrapper_atts Wrapper attributes; `class` and `data-entity` are reproduced.
		 * @param mixed                $off_value   Unused by this stand-in; kept for signature parity.
		 */
		public static function checkbox_field( string $name, string $label, string $value = '', bool $is_checked = false, array $atts = array(), array $wrapper_atts = array(), $off_value = 'off' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $off_value unused by this stand-in; kept for signature parity with the real method.
			$wrapper_class = isset( $wrapper_atts['class'] ) ? ' class="' . esc_attr( $wrapper_atts['class'] ) . '"' : '';
			$data_entity   = isset( $wrapper_atts['data-entity'] ) ? ' data-entity="' . esc_attr( $wrapper_atts['data-entity'] ) . '"' : '';
			$disabled      = array_key_exists( 'disabled', $atts ) ? ' disabled' : '';

			return '<div' . $wrapper_class . $data_entity . '>'
				. '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . ( $is_checked ? ' checked' : '' ) . $disabled . ' />'
				. '<div>' . $label . '</div>'
				. '</label></div>';
		}
	}
}
