<?php
/**
 * Shared HTTP fixture helpers for testing IfthenpayLpApiClient and every operation built on it —
 * used from both unit tests (Brain Monkey) and integration tests (real `pre_http_request`
 * filtering); only the two Brain-Monkey-specific helpers below require the former. No real keys or
 * account data — every fixture body is synthetic.
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

/**
 * Resets IfthenpayLpMethodCatalog's private static in-memory cache. That cache has no key
 * dimension (the catalog is global, unlike IfthenpayLpGatewayDataset's own per-Backoffice-Key
 * cache), so a value left over from an earlier test in the same PHPUnit process would otherwise
 * silently skip whatever get_transient()/wp_remote_request() stubs the next test sets up. No
 * production code ever needs to do this — the catalog has no merchant-triggered invalidation
 * event — so this stays test-only via Reflection rather than adding a public reset method with no
 * real caller.
 */
function ifthenpay_lp_reset_method_catalog_cache(): void {
	foreach ( array( 'cache', 'fetched' ) as $property_name ) {
		$property = new ReflectionProperty( IfthenpayLpMethodCatalog::class, $property_name );
		$property->setAccessible( true );
		$property->setValue( null, 'cache' === $property_name ? null : false );
	}
}

/**
 * Stubs the transport for a catalog fetch followed by a gateway-dataset fetch, in that order — the
 * two real network calls IfthenpayLpGatewayDataset::get() makes on a cold cache. Shared by every
 * test that exercises a path through IfthenpayLpGatewayDataset::get() on a key it hasn't cached yet.
 */
function ifthenpay_lp_mock_catalog_then_gateway_responses(): void {
	\Brain\Monkey\Functions\when( 'is_wp_error' )->justReturn( false );

	\Brain\Monkey\Functions\expect( 'wp_remote_request' )
		->twice()
		->andReturn(
			ifthenpay_lp_mock_response( 200, ifthenpay_lp_fixture( 'method-catalog.json' ) ),
			ifthenpay_lp_mock_response( 200, ifthenpay_lp_fixture( 'gateway-dataset.json' ) )
		);
	// Extract from the actual response passed in, rather than a fixed value per call — with two
	// different bodies across two calls, that is the only way to get the right body back to the
	// right caller regardless of call order.
	\Brain\Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $response ) => $response['response']['code'] );
	\Brain\Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $response ) => $response['body'] );
}

/**
 * Mocks IfthenpayLpTransactionStatus::check()'s own HTTP call via a real `pre_http_request`
 * filter — a real, completed transaction id answers 200 with
 * {"TransactionId":...,"PaymentMethod":...,"Amount":...,"OrderId":...}; an unrecognised one
 * answers 404 with an empty body (VERIFIED live against the real endpoint, not assumed). Shared by
 * every integration test exercising a path through IfthenpayLpTransactionStatus::check() — the
 * realtime polling fallback and manual re-check both confirm a txid/request_id the same way.
 *
 * `TransactionId` in the mocked body is a fixed placeholder: IfthenpayLpTransactionStatus::check()
 * never reads that field back out (only PaymentMethod/Amount/OrderId), and whatever
 * method_data.transaction_id a test asserts on comes from the identifier the caller itself passed
 * in, not from this response body.
 *
 * @param bool   $verified What the endpoint should answer.
 * @param string $order_id OrderId to echo back, when $verified is true — must match the token
 *                         under test for the confirmation to be accepted.
 * @param string $method   The confirmed payment method, when $verified is true.
 * @param string $amount   The confirmed amount, when $verified is true.
 */
function ifthenpay_lp_mock_transaction_status( bool $verified, string $order_id = '', string $method = 'MBWAY', string $amount = '25.00' ): void {
	add_filter(
		'pre_http_request',
		static function ( $preempt, $args, $url ) use ( $verified, $order_id, $method, $amount ) {
			if ( false !== strpos( $url, '/gateway/transaction/status/get' ) ) {
				return $verified
					? ifthenpay_lp_mock_response(
						200,
						(string) wp_json_encode(
							array(
								'TransactionId' => 'TXID-IGNORED-BY-CHECK',
								'PaymentMethod' => $method,
								'Amount'        => $amount,
								'OrderId'       => $order_id,
							)
						)
					)
					: ifthenpay_lp_mock_response( 404, '' );
			}
			return $preempt;
		},
		10,
		3
	);
}
