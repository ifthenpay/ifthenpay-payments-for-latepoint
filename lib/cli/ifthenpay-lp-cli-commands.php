<?php
/**
 * WP-CLI commands (003 T-12b) — only ever loaded under `defined( 'WP_CLI' ) && WP_CLI` (see the
 * main plugin file's includes()), so WP_CLI itself is always available here.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One command today: re-registering the callback URL against the currently saved Gateway Key.
 * The merchant-facing fix when a site's URL changes; during development, the fix for a cloudflared
 * tunnel URL that rotated since the last save.
 */
class IfthenpayLpCliCommands {

	/**
	 * Re-registers the callback URL for the currently saved Gateway Key.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ifthenpay callback-register
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args       Positional arguments (unused).
	 * @param array<string,string> $assoc_args Associative arguments (unused).
	 */
	public function callback_register( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI always calls with both; this command takes no arguments of its own.
		$gateway_key = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key', '' );

		if ( '' === $gateway_key ) {
			WP_CLI::error( __( 'No Gateway Key is saved yet — configure the ifthenpay payment processor first.', 'ifthenpay-payments-for-latepoint' ) );
			return;
		}

		if ( IfthenpayLpCallbackRegistration::register( $gateway_key ) ) {
			WP_CLI::success(
				sprintf(
					/* translators: %s: gateway key */
					__( 'Callback URL registered for gateway key %s.', 'ifthenpay-payments-for-latepoint' ),
					$gateway_key
				)
			);
			return;
		}

		$status = IfthenpayLpCallbackRegistration::get_status( $gateway_key );
		WP_CLI::error(
			sprintf(
				/* translators: 1: gateway key, 2: failure reason */
				__( 'Registration failed for gateway key %1$s: %2$s', 'ifthenpay-payments-for-latepoint' ),
				$gateway_key,
				$status['message'] ?? ''
			)
		);
	}
}

WP_CLI::add_command( 'ifthenpay callback-register', array( new IfthenpayLpCliCommands(), 'callback_register' ) );
