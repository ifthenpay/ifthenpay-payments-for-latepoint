<?php
/**
 * Proves IfthenpayLpEnabledMethodGate::is_usable() (003 T-13, FR-13): the processor's own
 * enabled/disabled toggle is not enough on its own — a saved Gateway Key must still be a real,
 * live one for the current Backoffice Key, or the method must not be offered at checkout.
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
require_once dirname( __DIR__, 2 ) . '/lib/helpers/ifthenpay-data-formatter.php';
require_once dirname( __DIR__, 2 ) . '/lib/helpers/ifthenpay-lp-method-catalog.php';
require_once dirname( __DIR__, 2 ) . '/lib/helpers/ifthenpay-lp-gateway-dataset.php';
require_once dirname( __DIR__, 2 ) . '/lib/helpers/ifthenpay-lp-enabled-method-gate.php';
require_once __DIR__ . '/../support/class-wp-error-stub.php';
require_once __DIR__ . '/../support/ifthenpay-http-fixtures.php';

/**
 * Enabled-method gate proof.
 */
final class EnabledMethodGateTest extends TestCase {

	/**
	 * Boots Brain Monkey and stubs the WP functions the client/catalog/dataset always touch.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs(
			array(
				'esc_html'      => static fn( $text ) => $text,
				'esc_html__'    => static fn( $text ) => $text,
				'__'            => static fn( $text ) => $text,
				'get_transient' => static fn() => false,
				'set_transient' => static fn() => true,
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
	 * Stubs the transport for a catalog fetch followed by a gateway-dataset fetch, in that order —
	 * the two real network calls IfthenpayLpGatewayDataset::get() makes underneath the gate.
	 */
	private function mock_catalog_then_gateway_responses(): void {
		Functions\when( 'is_wp_error' )->justReturn( false );

		Functions\expect( 'wp_remote_request' )
			->twice()
			->andReturn(
				ifthenpay_lp_mock_response( 200, ifthenpay_lp_fixture( 'method-catalog.json' ) ),
				ifthenpay_lp_mock_response( 200, ifthenpay_lp_fixture( 'gateway-dataset.json' ) )
			);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $response ) => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $response ) => $response['body'] );
	}

	/**
	 * No Gateway Key saved at all: not usable, no network call needed to know that.
	 */
	public function test_empty_gateway_key_is_not_usable_without_a_network_call(): void {
		Functions\expect( 'wp_remote_request' )->never();

		$this->assertFalse( IfthenpayLpEnabledMethodGate::is_usable( '', 'TEST-KEY-EMPTY-GW' ) );
	}

	/**
	 * A Gateway Key with no Backoffice Key saved: not usable either, same reasoning.
	 */
	public function test_empty_backoffice_key_is_not_usable_without_a_network_call(): void {
		Functions\expect( 'wp_remote_request' )->never();

		$this->assertFalse( IfthenpayLpEnabledMethodGate::is_usable( 'MODERN-GATEWAY', '' ) );
	}

	/**
	 * A Gateway Key that still appears in the live dataset is usable.
	 */
	public function test_gateway_key_present_in_dataset_is_usable(): void {
		$this->mock_catalog_then_gateway_responses();

		$this->assertTrue( IfthenpayLpEnabledMethodGate::is_usable( 'MODERN-GATEWAY', 'TEST-KEY-USABLE' ) );
	}

	/**
	 * A saved Gateway Key that no longer appears in the live dataset — revoked, or never valid —
	 * is not usable, even though both settings are non-empty.
	 */
	public function test_gateway_key_absent_from_dataset_is_not_usable(): void {
		$this->mock_catalog_then_gateway_responses();

		$this->assertFalse( IfthenpayLpEnabledMethodGate::is_usable( 'REVOKED-GATEWAY', 'TEST-KEY-REVOKED' ) );
	}

	/**
	 * A dataset fetch failure (transient outage) fails open — matching
	 * IfthenpayLpBackofficeKeyValidation's own save-time check, an outage must not take checkout
	 * down for an otherwise valid setup.
	 */
	public function test_dataset_fetch_failure_fails_open(): void {
		Functions\when( 'wp_remote_request' )->justReturn( new WP_Error( 'http_request_failed', 'timeout' ) );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$this->assertTrue( IfthenpayLpEnabledMethodGate::is_usable( 'MODERN-GATEWAY', 'TEST-KEY-OUTAGE' ) );
	}
}
