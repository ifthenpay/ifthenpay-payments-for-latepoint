<?php
/**
 * WP-CLI commands — only ever loaded under `defined( 'WP_CLI' ) && WP_CLI` (see the
 * main plugin file's includes()), so WP_CLI itself is always available here.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two commands: re-registering the callback URL against the currently saved Gateway Key (the
 * merchant-facing fix when a site's URL changes; during development, the fix for a cloudflared
 * tunnel URL that rotated since the last save), and manually settling a payment whose callback was
 * missed or failed (D-5, spec 001) — the practical way to trigger
 * OsPaymentsIfthenpaySettingsController::recheck_payment() without a dedicated admin UI button yet.
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

	/**
	 * Manually settles a payment whose callback was missed or failed (D-5) — see
	 * IfthenpayLpManualRecheck's own docblock for why this has no fresh outbound verification of
	 * its own yet: run it only once you've confirmed the payment on ifthenpay's own backoffice.
	 *
	 * ## OPTIONS
	 *
	 * <token>
	 * : Our own correlation handle for the payment — the repository row's token column.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ifthenpay recheck-payment lp-a1b2c3d4e5f6
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args       Positional arguments: [0] the token.
	 * @param array<string,string> $assoc_args Associative arguments (unused).
	 */
	public function recheck_payment( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI always calls with both; this command takes no associative arguments of its own.
		$outcome = IfthenpayLpManualRecheck::run( (string) ( $args[0] ?? '' ) );

		switch ( $outcome['outcome'] ) {
			case IfthenpayLpManualRecheck::SETTLED:
				WP_CLI::success( __( 'Payment confirmed and settled.', 'ifthenpay-payments-for-latepoint' ) );
				return;
			case IfthenpayLpManualRecheck::MISSING_ARGUMENT:
				WP_CLI::error( __( 'Usage: wp ifthenpay recheck-payment <token>', 'ifthenpay-payments-for-latepoint' ) );
				return;
			case IfthenpayLpManualRecheck::NOT_FOUND:
				WP_CLI::error( __( 'No payment record found for that token.', 'ifthenpay-payments-for-latepoint' ) );
				return;
			case IfthenpayLpManualRecheck::REJECTED:
				WP_CLI::error( __( 'This payment could not be settled — the stored details no longer match (amount, or the order is no longer open).', 'ifthenpay-payments-for-latepoint' ) );
				return;
			default:
				WP_CLI::error( __( 'Could not settle this payment right now. Please try again shortly.', 'ifthenpay-payments-for-latepoint' ) );
		}
	}
}

WP_CLI::add_command( 'ifthenpay callback-register', array( new IfthenpayLpCliCommands(), 'callback_register' ) );
WP_CLI::add_command( 'ifthenpay recheck-payment', array( new IfthenpayLpCliCommands(), 'recheck_payment' ) );
