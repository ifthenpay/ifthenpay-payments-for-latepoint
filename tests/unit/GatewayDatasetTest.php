<?php
/**
 * Proves IfthenpayLpGatewayDataset: per-request caching, the MB/Multibanco field-name
 * mapping, that an invisible catalog method is excluded even with real account data in the raw
 * record, and that every method's raw field value ("{METHOD} | {accountKey}", confirmed live) has
 * that display prefix stripped down to the bare account key — the shape ifthenpay's own
 * accounts-field documentation gives (`MBWAY|MBWAY-KEY`, `CCARD|CCARD-KEY`, ...) and the shape the
 * Multibanco reference API's `mbKey` param independently requires (rejects the prefixed form,
 * 401/403; accepts the bare key). Multibanco can also carry a static key ("11687 | 991", a raw
 * entidade/subentidade pair with no "MB | " prefix) instead of a dynamic one, depending on what
 * the merchant assigned to that gateway — passed through unchanged, since there is no prefix to
 * strip and no reliable way to tell a static key's entidade from an arbitrary prefix otherwise.
 *
 * Each test uses its own Backoffice Key value — IfthenpayLpGatewayDataset's per-request cache is
 * a static property that persists across tests in the same process, so a shared key would leak
 * state between them.
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
require_once dirname( __DIR__, 2 ) . '/lib/models/api/ifthenpay-lp-gateway-dataset.php';
require_once __DIR__ . '/../support/class-wp-error-stub.php';
require_once __DIR__ . '/../support/ifthenpay-http-fixtures.php';

/**
 * Gateway dataset proof.
 */
final class GatewayDatasetTest extends TestCase {

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
	 * Stubs the transport for a catalog fetch followed by a gateway-dataset fetch, in that order.
	 */
	private function mock_catalog_then_gateway_responses(): void {
		Functions\when( 'is_wp_error' )->justReturn( false );

		$catalog_body = ifthenpay_lp_fixture( 'method-catalog.json' );
		$gateway_body = ifthenpay_lp_fixture( 'gateway-dataset.json' );

		Functions\expect( 'wp_remote_request' )
			->twice()
			->andReturn(
				ifthenpay_lp_mock_response( 200, $catalog_body ),
				ifthenpay_lp_mock_response( 200, $gateway_body )
			);
		// Extract from the actual response passed in, rather than a fixed value per call — with
		// two different bodies across two calls, that is the only way to get the right body back
		// to the right caller regardless of call order.
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $response ) => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $response ) => $response['body'] );
	}

	/**
	 * Both gateways appear in gatewaykeys, keyed by GatewayKey.
	 */
	public function test_gatewaykeys_lists_every_gateway(): void {
		$this->mock_catalog_then_gateway_responses();

		$dataset = IfthenpayLpGatewayDataset::get( 'TEST-KEY-GATEWAYKEYS' );

		$this->assertSame(
			array(
				'LEGACY-GATEWAY' => 'LEGACY-GATEWAY',
				'MODERN-GATEWAY' => 'MODERN-GATEWAY',
			),
			$dataset['gatewaykeys']
		);
	}

	/**
	 * The second fixture gateway: every catalog-visible method's raw field value has its
	 * "{METHOD} | " display prefix stripped down to the bare account key ("MBWAY | ACC-000005" ->
	 * "ACC-000005"), correctly for the MB → Multibanco field-name mapping too
	 * ("MB | ACC-000008" -> "ACC-000008") — the shape ifthenpay's own accounts-field
	 * documentation gives, and the shape the Multibanco reference API's `mbKey` parameter actually
	 * accepts (confirmed live: the prefixed form gets a 401/403 "credentials rejected", the bare
	 * form succeeds).
	 */
	public function test_modern_gateway_accounts_are_extracted_including_multibanco_mapping(): void {
		$this->mock_catalog_then_gateway_responses();

		$dataset = IfthenpayLpGatewayDataset::get( 'TEST-KEY-MODERN' );

		// Order follows the catalog's own iteration order (IsVisible entries only), not
		// insertion order in the raw gateway record — not part of the contract, so compare
		// unordered.
		$this->assertEqualsCanonicalizing(
			array(
				'MB'      => 'ACC-000008',
				'MBWAY'   => 'ACC-000005',
				'PAYSHOP' => 'ACC-000006',
				'CCARD'   => 'ACC-000002',
				'GOOGLE'  => 'ACC-000004',
				'APPLE'   => 'ACC-000001',
				'PIX'     => 'ACC-000007',
			),
			$dataset['accounts']['MODERN-GATEWAY']
		);
	}

	/**
	 * COFIDIS has real account data in the fixture ("COFIDIS | ACC-000003" on MODERN-GATEWAY),
	 * but the catalog fixture marks it `IsVisible: false` — matching the real, live-verified
	 * catalog. The intersection must still exclude it: real account data
	 * in the gateway record is not enough on its own to offer a method.
	 */
	public function test_invisible_catalog_method_is_excluded_even_with_real_account_data(): void {
		$this->mock_catalog_then_gateway_responses();

		$dataset = IfthenpayLpGatewayDataset::get( 'TEST-KEY-COFIDIS' );

		$this->assertArrayNotHasKey( 'COFIDIS', $dataset['accounts']['MODERN-GATEWAY'] );
	}

	/**
	 * Multibanco can also carry a static key ("11687 | 991", entidade | subentidade) instead of a
	 * dynamic one — kept whole here, since it carries no "MB | " prefix to strip and there is no
	 * reliable way to tell a static key's entidade from an arbitrary prefix otherwise.
	 */
	public function test_multibanco_value_is_kept_whole_not_split(): void {
		$this->mock_catalog_then_gateway_responses();

		$dataset = IfthenpayLpGatewayDataset::get( 'TEST-KEY-LEGACY' );

		$this->assertSame( array( 'MB' => '11687 | 991' ), $dataset['accounts']['LEGACY-GATEWAY'] );
	}

	/**
	 * Two calls for the same key hit the network once — the per-request cache.
	 */
	public function test_second_call_for_the_same_key_is_cached(): void {
		$this->mock_catalog_then_gateway_responses();

		$first  = IfthenpayLpGatewayDataset::get( 'TEST-KEY-CACHED' );
		$second = IfthenpayLpGatewayDataset::get( 'TEST-KEY-CACHED' );

		$this->assertSame( $first, $second );
	}

	/**
	 * A catalog fetch failure propagates as null — "could not find out", not an empty dataset.
	 */
	public function test_catalog_failure_propagates_as_null(): void {
		Functions\when( 'wp_remote_request' )->justReturn( new WP_Error( 'http_request_failed', 'timeout' ) );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$this->assertNull( IfthenpayLpGatewayDataset::get( 'TEST-KEY-CATALOG-FAIL' ) );
	}

	/**
	 * A genuinely empty gateway list (200, `[]`) is a real, empty dataset — not null. This is the
	 * normal first-run state: a valid Backoffice Key with no gateway keys for this context yet.
	 */
	public function test_empty_gateway_list_is_a_real_empty_dataset_not_null(): void {
		Functions\when( 'is_wp_error' )->justReturn( false );
		$catalog_body = ifthenpay_lp_fixture( 'method-catalog.json' );
		$empty_body   = ifthenpay_lp_fixture( 'empty-array.json' );

		Functions\expect( 'wp_remote_request' )
			->twice()
			->andReturn(
				ifthenpay_lp_mock_response( 200, $catalog_body ),
				ifthenpay_lp_mock_response( 200, $empty_body )
			);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $response ) => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $response ) => $response['body'] );

		$dataset = IfthenpayLpGatewayDataset::get( 'TEST-KEY-EMPTY' );

		$this->assertNotNull( $dataset );
		$this->assertSame( array(), $dataset['gatewaykeys'] );
		$this->assertSame( array(), $dataset['accounts'] );
	}
}
