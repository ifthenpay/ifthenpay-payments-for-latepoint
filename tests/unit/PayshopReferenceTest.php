<?php
/**
 * Proves IfthenpayLpPayshopReference against the response shape VERIFIED from ifthenpay's own PHP
 * SDK and helpdesk API article (no live sandbox exists for Payshop, unlike Multibanco — see the
 * class's own docblock): a successful reference (Code "0") returns just reference and request id,
 * with no entity/amount/expiry echoed back; a rejected request (Code "102", invalid key) still
 * answers HTTP 200 — Payshop never signals a bad credential at the HTTP level the way Multibanco
 * does, so this class, not the shared transport layer, is what catches it.
 *
 * @package ifthenpay-payments-for-latepoint
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/models/api/ifthenpay-lp-exceptions.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/api/ifthenpay-lp-api-client.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/api/ifthenpay-lp-payshop-reference.php';
require_once __DIR__ . '/../support/class-wp-error-stub.php';
require_once __DIR__ . '/../support/ifthenpay-http-fixtures.php';

/**
 * Payshop reference operation proof.
 */
final class PayshopReferenceTest extends TestCase {

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
	 * A successful reference (Code "0") returns just the two fields Payshop actually echoes back —
	 * no entity, amount or expiry, unlike Multibanco.
	 */
	public function test_successful_reference_returns_reference_and_request_id(): void {
		$body = ifthenpay_lp_fixture( 'payshop-reference-valid.json' );
		Functions\expect( 'wp_remote_request' )->once()->andReturn( ifthenpay_lp_mock_response( 200, $body ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );

		$result = IfthenpayLpPayshopReference::create( '<PAYSHOP_KEY>', 'ORDER-1', '1.00', '20260908' );

		$this->assertSame( '900123456', $result->reference );
		$this->assertSame( 'C9lXGpQR4c7mzkZfFUb5', $result->request_id );
	}

	/**
	 * A rejected request — Code "102", still HTTP 200 — throws with the API's own Message. Unlike
	 * Multibanco, an invalid Payshop key never reaches the transport layer's own 401/403 check.
	 */
	public function test_rejected_request_throws_with_the_api_message(): void {
		$body = ifthenpay_lp_fixture( 'payshop-reference-invalid-key.json' );
		Functions\expect( 'wp_remote_request' )->once()->andReturn( ifthenpay_lp_mock_response( 200, $body ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );

		try {
			IfthenpayLpPayshopReference::create( '<BAD_KEY>', 'ORDER-1', '1.00', '20260908' );
			$this->fail( 'Expected IfthenpayLpTransportException.' );
		} catch ( IfthenpayLpTransportException $e ) {
			$this->assertSame( 'A payshopkey não é válida.', $e->getMessage() );
		}
	}

	/**
	 * The request body uses Payshop's own lowercase Portuguese field names, not Multibanco's
	 * camelCase ones — proven by asserting the actual POST body sent.
	 */
	public function test_request_uses_payshops_own_field_names(): void {
		$body = ifthenpay_lp_fixture( 'payshop-reference-valid.json' );
		Functions\expect( 'wp_remote_request' )
			->once()
			->with(
				'https://ifthenpay.com/api/payshop/reference/',
				Mockery::on(
					static function ( $args ) {
						$sent = json_decode( $args['body'], true );
						return array(
							'payshopkey' => '<PAYSHOP_KEY>',
							'id'         => 'ORDER-1',
							'valor'      => '1.00',
							'validade'   => '20260908',
						) === $sent;
					}
				)
			)
			->andReturn( ifthenpay_lp_mock_response( 200, $body ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );

		IfthenpayLpPayshopReference::create( '<PAYSHOP_KEY>', 'ORDER-1', '1.00', '20260908' );
		$this->addToAssertionCount( 1 ); // The Mockery ->with() expectation above is the real assertion.
	}
}
