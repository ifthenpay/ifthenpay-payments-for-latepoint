<?php
/**
 * Loads saved inbound-callback query strings from tests/fixtures/callbacks/ — see
 * contracts/callback.md's "Test fixtures" section. Every value is synthetic, no real keys.
 *
 * @package ifthenpay-payments-for-latepoint
 */

/**
 * Loads a fixture's raw query string, trimming the trailing newline text editors add.
 *
 * @param string $name File name under tests/fixtures/callbacks/.
 * @throws RuntimeException If the fixture file doesn't exist.
 */
function ifthenpay_lp_callback_fixture( string $name ): string {
	$path = __DIR__ . '/../fixtures/callbacks/' . $name;
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local fixture file, not a remote URL.
	$contents = file_get_contents( $path );
	if ( false === $contents ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test-only helper; $path is a hardcoded fixture filename, never rendered to a user.
		throw new RuntimeException( "Fixture not found: {$path}" );
	}

	return rtrim( $contents, "\n" );
}

/**
 * Loads a fixture and parses it into a `$_GET`-shaped array, the way WordPress would populate it
 * for a real inbound GET request.
 *
 * @param string $name File name under tests/fixtures/callbacks/.
 * @return array<int|string,mixed>
 */
function ifthenpay_lp_callback_fixture_params( string $name ): array {
	$params = array();
	parse_str( ifthenpay_lp_callback_fixture( $name ), $params );

	return $params;
}

/**
 * The synthetic gateway key every "valid"-shaped fixture's `apk` was base64-encoded from. Never a
 * real credential.
 */
function ifthenpay_lp_callback_fixture_gateway_key(): string {
	return 'TEST-GW-KEY-0001';
}
