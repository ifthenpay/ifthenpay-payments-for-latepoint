<?php
/**
 * Proves IfthenpayLpDataFormatter::build_pay_by_link_payload()'s full payload shape: every field
 * ifthenpay's Pay By Link endpoint expects, the "Intent #{id} - {admin description}" description,
 * and the three return URLs built from the intent's own get_page_url_with_intent() (falling back
 * to home_url() only when that isn't a valid URL).
 *
 * @package ifthenpay-payments-for-latepoint
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/models/ifthenpay-lp-data-formatter.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/ifthenpay-lp-pay-by-link-method-eligibility.php';
require_once __DIR__ . '/../support/class-os-settings-helper-stub.php';
require_once __DIR__ . '/../support/class-data-formatter-test-intent.php';

/**
 * Pay By Link payload proof.
 */
final class DataFormatterTest extends TestCase {

	/**
	 * Boots Brain Monkey and stubs the WP functions the formatter touches. Settings are seeded
	 * empty so build_accounts_string() and get_selected_method() take their early-return paths —
	 * both are already proved independently elsewhere (GatewayDatasetTest,
	 * PayByLinkMethodEligibilityTest); this test is about the payload's own shape.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		OsSettingsHelper::$values = array( 'ifthenpay_description' => 'Booking payment' );

		Functions\stubs(
			array(
				'__'                   => static fn( $text ) => $text,
				'get_locale'           => static fn() => 'en_US',
				'wp_http_validate_url' => static fn( $url ) => ( false !== filter_var( $url, FILTER_VALIDATE_URL ) ) ? $url : false,
				'home_url'             => static fn( $path = '/' ) => 'https://example.test' . $path,
				'add_query_arg'        => static fn( $args, $url ) => $url . '?' . http_build_query( $args ),
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
	 * Every field, with a real return-URL base from the intent.
	 */
	public function test_payload_has_every_field_with_intent_url(): void {
		$intent  = new IfthenpayLpDataFormatterTestIntent( 42, 'https://example.test/booking/resume?latepoint_order_intent_key=abc' );
		$payload = IfthenpayLpDataFormatter::build_pay_by_link_payload( $intent, 'lp-a1b2c3d4e5f6', 25 );

		$this->assertSame( 'lp-a1b2c3d4e5f6', $payload['id'] );
		$this->assertSame( '25.00', $payload['amount'] );
		$this->assertSame( 'Intent #42 - Booking payment', $payload['description'] );
		$this->assertSame( 'en', $payload['lang'] );
		$this->assertSame( '', $payload['accounts'] );
		$this->assertSame( '', $payload['selected_method'] );
		$this->assertSame( 'true', $payload['otp'] );

		foreach ( array( 'success', 'cancel', 'error' ) as $type ) {
			$url = $payload[ $type . '_url' ];
			$this->assertStringStartsWith( 'https://example.test/booking/resume?latepoint_order_intent_key=abc?', $url );
			$this->assertStringContainsString( 'ifthenpay_return=' . $type, $url );
			$this->assertStringContainsString( 'token=lp-a1b2c3d4e5f6', $url );
			$this->assertStringContainsString( 'txid=%5BTRANSACTIONID%5D', $url );
		}
	}

	/**
	 * An intent with no page URL captured yet (get_page_url_with_intent() returns a non-empty
	 * schemeless string in that case, not an empty one) falls back to home_url().
	 */
	public function test_payload_falls_back_to_home_url_when_intent_has_no_valid_url(): void {
		$intent  = new IfthenpayLpDataFormatterTestIntent( 7, '' );
		$payload = IfthenpayLpDataFormatter::build_pay_by_link_payload( $intent, 'lp-token', 10 );

		$this->assertStringStartsWith( 'https://example.test/?', $payload['success_url'] );
		$this->assertStringStartsWith( 'https://example.test/?', $payload['cancel_url'] );
		$this->assertStringStartsWith( 'https://example.test/?', $payload['error_url'] );
	}
}
