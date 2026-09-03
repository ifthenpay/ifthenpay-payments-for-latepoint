<?php
/**
 * Proves IfthenpayLpBackofficeKeyValidation::check()'s three-step order:
 * empty allowed, malformed rejected locally, and a confirmed rejection blocks the save while a
 * transport failure does not (fail open, so an ifthenpay outage never locks a merchant out of
 * their own settings page).
 *
 * @package ifthenpay-payments-for-latepoint
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/models/ifthenpay-lp-exceptions.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/ifthenpay-lp-api-client.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/ifthenpay-lp-key-validator.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/ifthenpay-lp-backoffice-key-validation.php';
require_once __DIR__ . '/../support/class-wp-error-stub.php';
require_once __DIR__ . '/../support/ifthenpay-http-fixtures.php';

/**
 * Save-time validation decision proof.
 */
final class BackofficeKeyValidationTest extends TestCase {

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
	 * An empty key is allowed — the field can be cleared — with no network call at all.
	 */
	public function test_empty_key_is_allowed_with_no_network_call(): void {
		Functions\expect( 'wp_remote_request' )->never();

		$this->assertNull( IfthenpayLpBackofficeKeyValidation::check( '' ) );
	}

	/**
	 * A malformed key is rejected locally, with no network call.
	 */
	public function test_malformed_key_is_rejected_with_no_network_call(): void {
		Functions\expect( 'wp_remote_request' )->never();

		$error = IfthenpayLpBackofficeKeyValidation::check( 'not-a-key' );

		$this->assertNotNull( $error );
	}

	/**
	 * A well-formed key ifthenpay rejects (403) blocks the save.
	 */
	public function test_remote_rejection_blocks_the_save(): void {
		Functions\expect( 'wp_remote_request' )->once()->andReturn( ifthenpay_lp_mock_response( 403, 'Invalid Credentials' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 403 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( 'Invalid Credentials' );

		$error = IfthenpayLpBackofficeKeyValidation::check( '0000-0000-0000-0000' );

		$this->assertNotNull( $error );
	}

	/**
	 * A well-formed key ifthenpay confirms (200) allows the save.
	 */
	public function test_remote_success_allows_the_save(): void {
		$body = ifthenpay_lp_fixture( 'entities-subentidades-valid.json' );
		Functions\expect( 'wp_remote_request' )->once()->andReturn( ifthenpay_lp_mock_response( 200, $body ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );

		$this->assertNull( IfthenpayLpBackofficeKeyValidation::check( '1234-5678-9012-3456' ) );
	}

	/**
	 * A transport failure (outage, timeout, …) does NOT block the save — this is the whole point
	 * of failing open: an ifthenpay outage must never lock a merchant out of their own settings page.
	 */
	public function test_transport_failure_does_not_block_the_save(): void {
		Functions\expect( 'wp_remote_request' )->once()->andReturn( new WP_Error( 'http_request_failed', 'Connection timed out' ) );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$this->assertNull( IfthenpayLpBackofficeKeyValidation::check( '1234-5678-9012-3456' ) );
	}
}
