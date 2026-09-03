<?php
/**
 * Proves IfthenpayLpTransactionStatus against the two shapes VERIFIED live against the real API:
 * a completed transaction id answers 200 with `{"TransactionId":...,"PaymentMethod":...}`; an
 * unrecognised one answers 404 with an empty body — not valid JSON, and the documented "not
 * found" signal, not a transport error.
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
require_once dirname( __DIR__, 2 ) . '/lib/helpers/ifthenpay-lp-transaction-status.php';
require_once __DIR__ . '/../support/class-wp-error-stub.php';
require_once __DIR__ . '/../support/ifthenpay-http-fixtures.php';

/**
 * Transaction status operation proof.
 */
final class TransactionStatusTest extends TestCase {

	/**
	 * Boots Brain Monkey and stubs the WP functions the client always touches.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs(
			array(
				'esc_html'      => static fn( $text ) => $text,
				'esc_html__'    => static fn( $text ) => $text,
				'__'            => static fn( $text ) => $text,
				'add_query_arg' => static fn( $args, $url ) => $url . '?' . http_build_query( $args ),
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
	 * A completed transaction id returns the payment method ifthenpay recorded for it.
	 */
	public function test_completed_transaction_returns_payment_method(): void {
		$body = wp_json_encode(
			array(
				'TransactionId' => 'HWG9lQsKJeLhjYzoCa8U',
				'PaymentMethod' => 'MBWAY',
			)
		);
		Functions\expect( 'wp_remote_request' )->once()->andReturn( ifthenpay_lp_mock_response( 200, $body ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );

		$result = IfthenpayLpTransactionStatus::check( 'HWG9lQsKJeLhjYzoCa8U' );

		$this->assertSame( 'MBWAY', $result );
	}

	/**
	 * An unrecognised transaction id — 404, empty body — is null, not an exception.
	 */
	public function test_unrecognised_transaction_returns_null(): void {
		Functions\expect( 'wp_remote_request' )->once()->andReturn( ifthenpay_lp_mock_response( 404, '' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 404 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		$result = IfthenpayLpTransactionStatus::check( 'DOES-NOT-EXIST' );

		$this->assertNull( $result );
	}

	/**
	 * A 200 response missing PaymentMethod (a shape that shouldn't happen, per what was verified
	 * live, but the client must never invent a value) throws rather than returning a false
	 * confirmation.
	 */
	public function test_response_missing_payment_method_throws(): void {
		$body = wp_json_encode( array( 'TransactionId' => 'HWG9lQsKJeLhjYzoCa8U' ) );
		Functions\expect( 'wp_remote_request' )->once()->andReturn( ifthenpay_lp_mock_response( 200, $body ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );

		$this->expectException( IfthenpayLpTransportException::class );
		IfthenpayLpTransactionStatus::check( 'HWG9lQsKJeLhjYzoCa8U' );
	}
}
