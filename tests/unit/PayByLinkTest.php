<?php
/**
 * Proves IfthenpayLpPayByLink: a complete response returns all three fields, and a
 * response missing any of PinCode/PinpayUrl/RedirectUrl is treated as an error, never a partial
 * success.
 *
 * @package ifthenpay-payments-for-latepoint
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/models/ifthenpay-lp-exceptions.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/ifthenpay-lp-api-client.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/ifthenpay-lp-pay-by-link.php';
require_once __DIR__ . '/../support/class-wp-error-stub.php';
require_once __DIR__ . '/../support/ifthenpay-http-fixtures.php';

/**
 * Pay By Link operation proof.
 */
final class PayByLinkTest extends TestCase {

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
	 * A complete response returns the three fields, snake_cased.
	 */
	public function test_complete_response_returns_all_three_fields(): void {
		$body = ifthenpay_lp_fixture( 'pay-by-link-valid.json' );
		Functions\expect( 'wp_remote_request' )->once()->andReturn( ifthenpay_lp_mock_response( 200, $body ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );

		$result = IfthenpayLpPayByLink::create(
			'<GATEWAY_KEY>',
			array(
				'id'     => 'tok-1',
				'amount' => '10.00',
			)
		);

		$this->assertSame( '1234', $result->pin_code );
		$this->assertSame( 'https://pay.ifthenpay.com/pinpay/pin/1234', $result->pinpay_url );
		$this->assertSame( 'https://pay.ifthenpay.com/pinpay/redirect/1234', $result->redirect_url );
	}

	/**
	 * A response missing RedirectUrl is an error, not a partial success — the checkout flow has
	 * nowhere to send the customer with only two of the three fields.
	 */
	public function test_response_missing_a_required_field_throws(): void {
		$body = ifthenpay_lp_fixture( 'pay-by-link-missing-redirect.json' );
		Functions\expect( 'wp_remote_request' )->once()->andReturn( ifthenpay_lp_mock_response( 200, $body ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );

		$this->expectException( IfthenpayLpTransportException::class );
		IfthenpayLpPayByLink::create(
			'<GATEWAY_KEY>',
			array(
				'id'     => 'tok-1',
				'amount' => '10.00',
			)
		);
	}

	/**
	 * The gateway key is rawurlencoded into the path — the client throws a credential exception
	 * on 401/403 the same way as every other operation.
	 */
	public function test_credential_rejection_propagates(): void {
		Functions\expect( 'wp_remote_request' )->once()->andReturn( ifthenpay_lp_mock_response( 403, '' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 403 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		$this->expectException( IfthenpayLpCredentialException::class );
		IfthenpayLpPayByLink::create(
			'<GATEWAY_KEY>',
			array(
				'id'     => 'tok-1',
				'amount' => '10.00',
			)
		);
	}
}
