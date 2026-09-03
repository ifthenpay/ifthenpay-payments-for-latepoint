<?php
/**
 * Proves register_callback_on_settings_updated()'s cache-invalidation wiring: saving a Backoffice
 * Key clears IfthenpayLpGatewayDataset's cache for it immediately, so the very next read reflects
 * the save instead of the cache's own short TTL.
 *
 * @package ifthenpay-payments-for-latepoint
 */

/**
 * Settings-save cache invalidation proof.
 */
class SettingsUpdatedCacheInvalidationTest extends WP_UnitTestCase {

	/**
	 * How many times the mocked gateway/get endpoint has been hit this test.
	 *
	 * @var int
	 */
	private int $gateway_get_calls = 0;

	/**
	 * Mocks gateway/methods/available (a fixed catalog, needed by IfthenpayLpGatewayDataset's own
	 * fetch) and gateway/get — the first call returns the fixture's two gateways, every call after
	 * returns a single, differently-named one, so the test can tell a fresh fetch from a stale
	 * cache hit by which shape comes back.
	 */
	private function mock_gateway_endpoints(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, 'gateway/methods/available' ) ) {
					return $this->fixture_response( 'method-catalog.json' );
				}
				if ( false !== strpos( $url, 'gateway/get' ) ) {
					++$this->gateway_get_calls;
					if ( 1 === $this->gateway_get_calls ) {
						return $this->fixture_response( 'gateway-dataset.json' );
					}
					return array(
						'response' => array(
							'code'    => 200,
							'message' => '',
						),
						'body'     => wp_json_encode(
							array(
								array(
									'Alias'      => 'FRESH-GATEWAY',
									'GatewayKey' => 'FRESH-GATEWAY',
									'MBWAY'      => 'MBWAY | ACC-FRESH',
								),
							)
						),
						'headers'  => array(),
					);
				}
				return $preempt;
			},
			10,
			3
		);
	}

	/**
	 * Loads a fixture file into a `wp_remote_request()`-shaped 200 response.
	 *
	 * @param string $name Fixture file name under tests/fixtures/ifthenpay/.
	 * @return array{response: array{code:int,message:string}, body:string, headers: array<empty>}
	 */
	private function fixture_response( string $name ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local fixture file, not a remote URL.
		$body = (string) file_get_contents( __DIR__ . '/../fixtures/ifthenpay/' . $name );

		return array(
			'response' => array(
				'code'    => 200,
				'message' => '',
			),
			'body'     => $body,
			'headers'  => array(),
		);
	}

	/**
	 * Saving the Backoffice Key invalidates the previously cached dataset for it — the next read
	 * re-fetches instead of returning what was cached before the save.
	 */
	public function test_saving_backoffice_key_invalidates_its_cached_dataset(): void {
		$this->mock_gateway_endpoints();

		$before = IfthenpayLpGatewayDataset::get( 'boKey-cache-test' );
		$this->assertArrayHasKey( 'LEGACY-GATEWAY', $before['gatewaykeys'] );
		$this->assertSame( 1, $this->gateway_get_calls );

		global $LATEPOINT_ADDON_PAYMENTS_IFTHENPAY;
		$LATEPOINT_ADDON_PAYMENTS_IFTHENPAY->register_callback_on_settings_updated(
			array( 'ifthenpay_backoffice_key' => 'boKey-cache-test' )
		);

		$after = IfthenpayLpGatewayDataset::get( 'boKey-cache-test' );
		$this->assertArrayHasKey( 'FRESH-GATEWAY', $after['gatewaykeys'] );
		$this->assertArrayNotHasKey( 'LEGACY-GATEWAY', $after['gatewaykeys'] );
		$this->assertSame( 2, $this->gateway_get_calls );
	}

	/**
	 * A save that doesn't touch the Backoffice Key at all leaves the cached dataset alone.
	 */
	public function test_unrelated_setting_save_does_not_invalidate_the_cache(): void {
		$this->mock_gateway_endpoints();

		IfthenpayLpGatewayDataset::get( 'boKey-untouched' );
		$this->assertSame( 1, $this->gateway_get_calls );

		global $LATEPOINT_ADDON_PAYMENTS_IFTHENPAY;
		$LATEPOINT_ADDON_PAYMENTS_IFTHENPAY->register_callback_on_settings_updated(
			array( 'ifthenpay_description' => 'Something unrelated' )
		);

		IfthenpayLpGatewayDataset::get( 'boKey-untouched' );
		$this->assertSame( 1, $this->gateway_get_calls );
	}
}
