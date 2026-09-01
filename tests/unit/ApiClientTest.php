<?php
/**
 * Proves IfthenpayLpApiClient's transport and error model against every fixture in
 * tests/fixtures/ifthenpay/ (003 T-02/T-03): valid JSON, 401, 403, 5xx, a transport-level
 * failure, malformed JSON, the two plain-text callback-activation shapes, and a 200-empty-array
 * response. No WordPress booted — every wp_* call the client makes is stubbed via Brain Monkey.
 *
 * @package ifthenpay-payments-for-latepoint
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/helpers/ifthenpay-lp-api-exception.php';
require_once dirname( __DIR__, 2 ) . '/lib/helpers/ifthenpay-lp-credential-exception.php';
require_once dirname( __DIR__, 2 ) . '/lib/helpers/ifthenpay-lp-transport-exception.php';
require_once dirname( __DIR__, 2 ) . '/lib/helpers/ifthenpay-lp-api-client.php';
require_once __DIR__ . '/../support/class-wp-error-stub.php';
require_once __DIR__ . '/../support/ifthenpay-http-fixtures.php';

/**
 * Client skeleton and error-model proof.
 */
final class ApiClientTest extends TestCase {

	/**
	 * Boots Brain Monkey and stubs the WP functions the client always touches.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs(
			array(
				'esc_html'   => static fn( $text ) => $text,
				'esc_html__' => static fn( $text ) => $text,
				'__'         => static fn( $text ) => $text,
			)
		);
	}

	/**
	 * Tears down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stubs wp_remote_request to return a fixed WP HTTP API response array, and wires the three
	 * retrieval helpers the client calls against it.
	 *
	 * @param int    $status HTTP status code to simulate.
	 * @param string $body   Raw response body to simulate.
	 */
	private function mock_http( int $status, string $body ): void {
		$response = ifthenpay_lp_mock_response( $status, $body );

		Functions\expect( 'wp_remote_request' )->once()->andReturn( $response );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
	}

	/**
	 * A 200 with a well-formed JSON body decodes to an array.
	 */
	public function test_valid_json_response_decodes(): void {
		$this->mock_http( 200, ifthenpay_lp_fixture( 'valid.json' ) );

		$result = IfthenpayLpApiClient::get( 'https://api.ifthenpay.com/example' );

		$this->assertSame( array( 'example' => 'value' ), $result );
	}

	/**
	 * A 200 with an empty JSON array — the shape /gateway/get answers for a client with no
	 * gateway keys for this context — decodes to an empty array, not null or an error.
	 */
	public function test_200_empty_array_decodes_to_empty_array(): void {
		$this->mock_http( 200, ifthenpay_lp_fixture( 'empty-array.json' ) );

		$result = IfthenpayLpApiClient::get( 'https://api.ifthenpay.com/gateway/get' );

		$this->assertSame( array(), $result );
	}

	/**
	 * 401 is a credential rejection, not a transport failure.
	 */
	public function test_401_throws_credential_exception(): void {
		$this->mock_http( 401, '' );

		$this->expectException( IfthenpayLpCredentialException::class );
		IfthenpayLpApiClient::get( 'https://api.ifthenpay.com/example' );
	}

	/**
	 * 403 is a credential rejection too.
	 */
	public function test_403_throws_credential_exception(): void {
		$this->mock_http( 403, '' );

		$this->expectException( IfthenpayLpCredentialException::class );
		IfthenpayLpApiClient::get( 'https://api.ifthenpay.com/example' );
	}

	/**
	 * A 5xx says nothing about the credentials — it's a transport failure, not a credential one.
	 */
	public function test_5xx_throws_transport_exception_not_credential_exception(): void {
		$this->mock_http( 500, '' );

		$this->expectException( IfthenpayLpTransportException::class );
		IfthenpayLpApiClient::get( 'https://api.ifthenpay.com/example' );
	}

	/**
	 * A network-level failure (timeout, DNS, …) surfaces as a WP_Error from wp_remote_request —
	 * also a transport failure, never mistaken for a credential rejection.
	 */
	public function test_wp_error_throws_transport_exception(): void {
		Functions\expect( 'wp_remote_request' )->once()->andReturn( new WP_Error( 'http_request_failed', 'Connection timed out' ) );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$this->expectException( IfthenpayLpTransportException::class );
		IfthenpayLpApiClient::get( 'https://api.ifthenpay.com/example' );
	}

	/**
	 * A 200 with a body that isn't valid JSON, when JSON was expected, is a transport failure —
	 * distinct from a plain-text-by-design endpoint (see the next two tests).
	 */
	public function test_malformed_json_throws_transport_exception(): void {
		$this->mock_http( 200, ifthenpay_lp_fixture( 'malformed.json' ) );

		$this->expectException( IfthenpayLpTransportException::class );
		IfthenpayLpApiClient::get( 'https://api.ifthenpay.com/example' );
	}

	/**
	 * Callback activation answers plain text, not JSON — with $expects_json=false, `OK` comes
	 * back as-is, no exception.
	 */
	public function test_plain_text_ok_is_returned_as_is_when_json_not_expected(): void {
		$this->mock_http( 200, ifthenpay_lp_fixture( 'callback-activation-ok.txt' ) );

		$result = IfthenpayLpApiClient::post(
			'https://api.ifthenpay.com/endpoint/callback/activation/',
			array( 'chave' => '<GATEWAY_KEY>' ),
			IfthenpayLpApiClient::TIMEOUT_GENERAL,
			false
		);

		$this->assertSame( 'OK', $result );
	}

	/**
	 * Same endpoint, the failure shape — still plain text, still not an exception. `INVALID` is
	 * data for the caller to interpret, not a transport-level problem.
	 */
	public function test_plain_text_invalid_is_returned_as_is_when_json_not_expected(): void {
		$this->mock_http( 200, ifthenpay_lp_fixture( 'callback-activation-invalid.txt' ) );

		$result = IfthenpayLpApiClient::post(
			'https://api.ifthenpay.com/endpoint/callback/activation/',
			array( 'chave' => '<GATEWAY_KEY>' ),
			IfthenpayLpApiClient::TIMEOUT_GENERAL,
			false
		);

		$this->assertSame( 'INVALID', $result );
	}
}
