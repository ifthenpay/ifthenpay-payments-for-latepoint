<?php
/**
 * Proves IfthenpayLpMethodCatalog: the cross-request TTL cache, and that IsVisible:
 * false entries never reach the formatted output — verified live against the real catalog
 * (COFIDIS and BIZUM are IsVisible: false today).
 *
 * @package ifthenpay-payments-for-latepoint
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/models/api/ifthenpay-lp-exceptions.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/api/ifthenpay-lp-api-client.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/ifthenpay-lp-data-formatter.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/api/ifthenpay-lp-method-catalog.php';
require_once __DIR__ . '/../support/class-wp-error-stub.php';
require_once __DIR__ . '/../support/ifthenpay-http-fixtures.php';

/**
 * Method catalog proof.
 */
final class MethodCatalogTest extends TestCase {

	/**
	 * Boots Brain Monkey and stubs the WP functions the client/catalog always touch.
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
	 * A cache miss fetches, formats, and stores the result for next time.
	 */
	public function test_cache_miss_fetches_and_caches(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		$body = ifthenpay_lp_fixture( 'method-catalog.json' );
		Functions\expect( 'wp_remote_request' )->once()->andReturn( ifthenpay_lp_mock_response( 200, $body ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\expect( 'set_transient' )->once();

		$catalog = IfthenpayLpMethodCatalog::get();

		$this->assertIsArray( $catalog );
		$this->assertArrayHasKey( 'MB', $catalog );
	}

	/**
	 * IsVisible: false entries (COFIDIS, BIZUM as of the live check behind this fixture) never
	 * reach the formatted catalog.
	 */
	public function test_invisible_methods_are_excluded(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		$body = ifthenpay_lp_fixture( 'method-catalog.json' );
		Functions\when( 'wp_remote_request' )->justReturn( ifthenpay_lp_mock_response( 200, $body ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( 'set_transient' )->justReturn( true );

		$catalog = IfthenpayLpMethodCatalog::get();

		$this->assertArrayNotHasKey( 'COFIDIS', $catalog );
		$this->assertArrayNotHasKey( 'BIZUM', $catalog );
		$this->assertArrayHasKey( 'MBWAY', $catalog );
	}

	/**
	 * A cache hit never calls the API.
	 */
	public function test_cache_hit_skips_the_network_call(): void {
		Functions\when( 'get_transient' )->justReturn( array( 'MB' => array( 'position' => 1 ) ) );
		Functions\expect( 'wp_remote_request' )->never();

		$catalog = IfthenpayLpMethodCatalog::get();

		$this->assertSame( array( 'MB' => array( 'position' => 1 ) ), $catalog );
	}

	/**
	 * A fetch failure returns null, not an empty catalog — the caller needs to tell "ifthenpay
	 * currently offers nothing" from "could not find out" apart.
	 */
	public function test_fetch_failure_returns_null(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_remote_request' )->justReturn( new WP_Error( 'http_request_failed', 'Connection timed out' ) );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$this->assertNull( IfthenpayLpMethodCatalog::get() );
	}
}
