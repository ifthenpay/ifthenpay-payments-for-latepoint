<?php
/**
 * Proves IfthenpayLpMultibancoReference (003 T-07) against the two shapes VERIFIED live in
 * contracts/api.md: a successful reference (Status "0") returns its five fields, and a rejected
 * request (Status "-1", HTTP 400, blank fields) throws with the API's own Message — a case the
 * shared transport layer has no reason to treat as a failure on its own, since it's a normal 400
 * with a well-formed envelope, not a 5xx or malformed body.
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
require_once dirname( __DIR__, 2 ) . '/lib/helpers/ifthenpay-lp-multibanco-reference.php';
require_once __DIR__ . '/../support/class-wp-error-stub.php';
require_once __DIR__ . '/../support/ifthenpay-http-fixtures.php';

/**
 * Multibanco reference operation proof.
 */
final class MultibancoReferenceTest extends TestCase {

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
	 * A successful reference (Status "0") returns the five fields verified live.
	 */
	public function test_successful_reference_returns_all_fields(): void {
		$body = ifthenpay_lp_fixture( 'multibanco-reference-valid.json' );
		Functions\expect( 'wp_remote_request' )->once()->andReturn( ifthenpay_lp_mock_response( 200, $body ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );

		$result = IfthenpayLpMultibancoReference::create( '<MB_KEY>', 'ORDER-1', '1.00', 1 );

		$this->assertSame( '11990', $result->entity );
		$this->assertSame( '000191905', $result->reference );
		$this->assertSame( '1.00', $result->amount );
		$this->assertSame( '02-09-2026', $result->expiry_date );
		$this->assertSame( 'B8kWFoPQ3b6lyjYeETa4', $result->request_id );
	}

	/**
	 * A rejected request — Status "-1", HTTP 400, every field blank — throws with the API's
	 * Message, rather than returning a result with empty/meaningless fields.
	 */
	public function test_rejected_request_throws_with_the_api_message(): void {
		$body = ifthenpay_lp_fixture( 'multibanco-reference-invalid-amount.json' );
		Functions\expect( 'wp_remote_request' )->once()->andReturn( ifthenpay_lp_mock_response( 400, $body ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 400 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );

		try {
			IfthenpayLpMultibancoReference::create( '<MB_KEY>', 'ORDER-1', '0.00', 1 );
			$this->fail( 'Expected IfthenpayLpTransportException.' );
		} catch ( IfthenpayLpTransportException $e ) {
			$this->assertSame( 'Invalid Amount', $e->getMessage() );
		}
	}

	/**
	 * Bad credentials answer 403 with an empty body — the transport layer catches this before
	 * this class ever sees a body to check Status on.
	 */
	public function test_credential_rejection_propagates(): void {
		Functions\expect( 'wp_remote_request' )->once()->andReturn( ifthenpay_lp_mock_response( 403, '' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 403 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		$this->expectException( IfthenpayLpCredentialException::class );
		IfthenpayLpMultibancoReference::create( '<MB_KEY>', 'ORDER-1', '1.00', 1 );
	}

	/**
	 * The `sandbox` flag targets `/sandbox` instead of `/init` — proven by asserting the request
	 * URL, since ifthenpay's own guidance is to always use it for testing.
	 */
	public function test_sandbox_flag_targets_the_sandbox_endpoint(): void {
		$body = ifthenpay_lp_fixture( 'multibanco-reference-valid.json' );
		Functions\expect( 'wp_remote_request' )
			->once()
			->with( 'https://api.ifthenpay.com/multibanco/reference/sandbox', Mockery::type( 'array' ) )
			->andReturn( ifthenpay_lp_mock_response( 200, $body ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );

		IfthenpayLpMultibancoReference::create( '<MB_KEY>', 'ORDER-1', '1.00', 1, true );
		$this->addToAssertionCount( 1 ); // The Mockery ->with() expectation above is the real assertion.
	}
}
