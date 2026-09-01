<?php
/**
 * Proves IfthenpayLpKeyValidator: the local format check never reaches the network,
 * and the remote check distinguishes an unrecognized key (403) from a recognized one — even with
 * zero entities, per the live-verified behaviour of the ifthenpay API.
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
require_once dirname( __DIR__, 2 ) . '/lib/helpers/ifthenpay-lp-key-validator.php';
require_once __DIR__ . '/../support/class-wp-error-stub.php';
require_once __DIR__ . '/../support/ifthenpay-http-fixtures.php';

/**
 * Key validator proof.
 */
final class KeyValidatorTest extends TestCase {

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
	 * Well-formed keys pass, with no HTTP call made.
	 */
	public function test_valid_format_is_accepted(): void {
		Functions\expect( 'wp_remote_request' )->never();

		$this->assertTrue( IfthenpayLpKeyValidator::has_valid_format( '1234-5678-9012-3456' ) );
	}

	/**
	 * Malformed keys are rejected locally — no HTTP call made, exactly the point of checking
	 * format before the network round trip.
	 */
	public function test_malformed_key_is_rejected_without_a_network_call(): void {
		Functions\expect( 'wp_remote_request' )->never();

		$this->assertFalse( IfthenpayLpKeyValidator::has_valid_format( 'not-a-key' ) );
		$this->assertFalse( IfthenpayLpKeyValidator::has_valid_format( '1234-5678-9012' ) );
		$this->assertFalse( IfthenpayLpKeyValidator::has_valid_format( '' ) );
	}

	/**
	 * A key ifthenpay does not recognize answers 403 plain text — a credential rejection, not a
	 * transport failure.
	 */
	public function test_unrecognized_key_throws_credential_exception(): void {
		Functions\expect( 'wp_remote_request' )->once()->andReturn(
			ifthenpay_lp_mock_response( 403, ifthenpay_lp_fixture( 'invalid-credentials.txt' ) )
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 403 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( ifthenpay_lp_fixture( 'invalid-credentials.txt' ) );

		$this->expectException( IfthenpayLpCredentialException::class );
		IfthenpayLpKeyValidator::verify_remote( '0000-0000-0000-0000' );
	}

	/**
	 * A recognized key with real entities answers 200 — no exception.
	 */
	public function test_recognized_key_with_entities_does_not_throw(): void {
		$body = ifthenpay_lp_fixture( 'entities-subentidades-valid.json' );
		Functions\expect( 'wp_remote_request' )->once()->andReturn( ifthenpay_lp_mock_response( 200, $body ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );

		IfthenpayLpKeyValidator::verify_remote( '1234-5678-9012-3456' );
		$this->addToAssertionCount( 1 ); // Reaching here without an exception is the assertion.
	}

	/**
	 * The documented edge case this operation exists to get right: a recognized key with zero
	 * entities is still valid — 200 with an empty array, not a rejection. Unlike /gateway/get,
	 * this endpoint's 200-vs-403 split is the actual validity signal, so
	 * an empty body must not be mistaken for an unrecognized key.
	 */
	public function test_recognized_key_with_no_entities_does_not_throw(): void {
		$body = ifthenpay_lp_fixture( 'empty-array.json' );
		Functions\expect( 'wp_remote_request' )->once()->andReturn( ifthenpay_lp_mock_response( 200, $body ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );

		IfthenpayLpKeyValidator::verify_remote( '1234-5678-9012-3456' );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * A transport failure says nothing about the key — distinct exception type from rejection,
	 * so a caller can fail open on the settings page.
	 */
	public function test_transport_failure_throws_transport_exception_not_credential_exception(): void {
		Functions\expect( 'wp_remote_request' )->once()->andReturn( new WP_Error( 'http_request_failed', 'Connection timed out' ) );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$this->expectException( IfthenpayLpTransportException::class );
		IfthenpayLpKeyValidator::verify_remote( '1234-5678-9012-3456' );
	}
}
