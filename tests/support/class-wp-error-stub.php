<?php
/**
 * Minimal WP_Error stand-in for unit tests, which never boot WordPress.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * A WP_Error stand-in with only the surface IfthenpayLpApiClient actually calls.
	 */
	class WP_Error {

		/**
		 * The error message.
		 *
		 * @var string
		 */
		private $message;

		/**
		 * Constructor.
		 *
		 * @param string $code    Unused by this stand-in; kept for signature compatibility.
		 * @param string $message Error message.
		 *
		 * @phpstan-ignore constructor.unusedParameter ($code is unused here, kept only so this stand-in's signature matches the real WP_Error's)
		 */
		public function __construct( string $code = '', string $message = '' ) {
			$this->message = $message;
		}

		/**
		 * Returns the error message.
		 */
		public function get_error_message(): string {
			return $this->message;
		}
	}
}
