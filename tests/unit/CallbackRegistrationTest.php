<?php
/**
 * Proves IfthenpayLpCallbackRegistration: the assembled URL is checked against the
 * 300-character limit before any request is attempted, the plain-text OK/INVALID response (not
 * JSON) decides success, and every outcome — including a transport failure — is stored rather
 * than thrown, since a registration failure must never block the settings save it runs after.
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
require_once dirname( __DIR__, 2 ) . '/lib/helpers/ifthenpay-lp-callback-registration.php';
require_once __DIR__ . '/../support/class-wp-error-stub.php';
require_once __DIR__ . '/../support/ifthenpay-http-fixtures.php';

/**
 * Callback registration proof.
 */
final class CallbackRegistrationTest extends TestCase {

	/**
	 * In-memory stand-in for get_option()/update_option() — a plain array keyed by option name,
	 * reset before every test so status stored in one test can't leak into the next.
	 *
	 * @var array<string,mixed>
	 */
	private array $options = array();

	/**
	 * Boots Brain Monkey and stubs the WP functions the client and this class always touch.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();

		Functions\stubs(
			array(
				'esc_html'   => static fn( $text ) => $text,
				'esc_html__' => static fn( $text ) => $text,
				'__'         => static fn( $text ) => $text,
				'rest_url'   => static fn( $path ) => 'https://example.test/wp-json/' . ltrim( $path, '/' ),
			)
		);

		Functions\when( 'get_option' )->alias(
			function ( string $name, $default_value = false ) {
				return $this->options[ $name ] ?? $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
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
	 * The real response — the bare JSON string literal `"OK"`, quotes included, confirmed against
	 * the live API — registers success. A naive `'OK' === trim($response)` check would miss this
	 * shape entirely and silently record every real success as a failure; this is the regression
	 * that live call caught.
	 */
	public function test_ok_response_registers_success(): void {
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_request' )->justReturn( ifthenpay_lp_mock_response( 200, ifthenpay_lp_fixture( 'callback-activation-ok.txt' ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $response ) => $response['body'] );

		$this->assertTrue( IfthenpayLpCallbackRegistration::register( 'GATEWAY-1' ) );

		$status = IfthenpayLpCallbackRegistration::get_status( 'GATEWAY-1' );
		$this->assertTrue( $status['success'] );
		$this->assertSame( '', $status['message'] );
	}

	/**
	 * A bare, unquoted "OK" — the shape the contract originally assumed — is also recognized, in
	 * case ifthenpay's own response shape ever reverts.
	 */
	public function test_bare_unquoted_ok_also_registers_success(): void {
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_request' )->justReturn( ifthenpay_lp_mock_response( 200, 'OK' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $response ) => $response['body'] );

		$this->assertTrue( IfthenpayLpCallbackRegistration::register( 'GATEWAY-BARE-OK' ) );
	}

	/**
	 * A plain-text "INVALID" from ifthenpay registers failure, with a named reason stored.
	 */
	public function test_invalid_response_registers_failure_with_a_named_reason(): void {
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_request' )->justReturn( ifthenpay_lp_mock_response( 200, ifthenpay_lp_fixture( 'callback-activation-invalid.txt' ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $response ) => $response['body'] );

		$this->assertFalse( IfthenpayLpCallbackRegistration::register( 'GATEWAY-2' ) );

		$status = IfthenpayLpCallbackRegistration::get_status( 'GATEWAY-2' );
		$this->assertFalse( $status['success'] );
		$this->assertNotSame( '', $status['message'] );
	}

	/**
	 * A transport failure is stored as a named failure, not thrown — this call runs after a
	 * settings save via an action hook, which must not fatal the request that triggered it.
	 */
	public function test_transport_failure_registers_failure_instead_of_throwing(): void {
		Functions\when( 'wp_remote_request' )->justReturn( new WP_Error( 'http_request_failed', 'timeout' ) );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$this->assertFalse( IfthenpayLpCallbackRegistration::register( 'GATEWAY-3' ) );

		$status = IfthenpayLpCallbackRegistration::get_status( 'GATEWAY-3' );
		$this->assertFalse( $status['success'] );
		$this->assertNotSame( '', $status['message'] );
	}

	/**
	 * A gateway key with no stored status at all — a fresh install, or one never registered —
	 * is null, distinct from a stored failure.
	 */
	public function test_unknown_gateway_key_has_no_status(): void {
		$this->assertNull( IfthenpayLpCallbackRegistration::get_status( 'NEVER-REGISTERED' ) );
	}

	/**
	 * The 300-character limit is checked before any request is
	 * attempted — a specific, named failure, not a request ifthenpay would have to reject.
	 */
	public function test_url_over_300_characters_fails_without_a_network_call(): void {
		Functions\when( 'rest_url' )->justReturn( 'https://' . str_repeat( 'a', 300 ) . '.example.test/wp-json/ifthenpay-lp/v1/callback' );
		Functions\expect( 'wp_remote_request' )->never();

		$this->assertFalse( IfthenpayLpCallbackRegistration::register( 'GATEWAY-4' ) );

		$status = IfthenpayLpCallbackRegistration::get_status( 'GATEWAY-4' );
		$this->assertFalse( $status['success'] );
		$this->assertStringContainsString( '300', $status['message'] );
	}
}
