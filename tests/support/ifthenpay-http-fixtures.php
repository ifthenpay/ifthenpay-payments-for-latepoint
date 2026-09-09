<?php
/**
 * Shared HTTP fixture helpers for testing IfthenpayLpApiClient and every operation built on it.
 * No real keys or account data — every fixture body is synthetic.
 *
 * @package ifthenpay-payments-for-latepoint
 */

/**
 * Loads a fixture file's raw content, trimming the trailing newline text editors add.
 *
 * @param string $name File name under tests/fixtures/ifthenpay/.
 * @throws RuntimeException If the fixture file doesn't exist.
 */
function ifthenpay_lp_fixture( string $name ): string {
	$path = __DIR__ . '/../fixtures/ifthenpay/' . $name;
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local fixture file, not a remote URL; wp_remote_get() does not apply here.
	$contents = file_get_contents( $path );
	if ( false === $contents ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test-only helper; $path is a hardcoded fixture filename, never rendered to a user.
		throw new RuntimeException( "Fixture not found: {$path}" );
	}

	return rtrim( $contents, "\n" );
}

/**
 * Builds a `wp_remote_get()`/`wp_remote_post()`-shaped response array, for Brain Monkey to
 * return in place of a real HTTP call.
 *
 * @param int    $status HTTP status code.
 * @param string $body   Raw response body.
 * @return array{response: array{code: int, message: string}, body: string, headers: array<empty>}
 */
function ifthenpay_lp_mock_response( int $status, string $body ): array {
	return array(
		'response' => array(
			'code'    => $status,
			'message' => '',
		),
		'body'     => $body,
		'headers'  => array(),
	);
}
