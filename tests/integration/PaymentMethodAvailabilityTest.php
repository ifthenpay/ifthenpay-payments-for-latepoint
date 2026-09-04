<?php
/**
 * Proves IfthenpayLpPaymentMethodAvailability::add_enabled_payment_methods_to_payment_times() gates
 * PayByLink (ifthenpay_gateway) the same way it already gates Multibanco (ifthenpay_multibanco):
 * silently absent from the offered methods, not merely failing once picked, when the merchant's own
 * enabled methods carry no live account on the selected gateway.
 *
 * @package ifthenpay-payments-for-latepoint
 */

// phpcs:ignore Squiz.Commenting.FileComment.Missing -- docblock above is the file comment; the sniff misclassifies it when a require is the first statement.
require_once __DIR__ . '/../support/ifthenpay-http-fixtures.php';

/**
 * Payment method availability proof.
 */
class PaymentMethodAvailabilityTest extends WP_UnitTestCase {

	private const BACKOFFICE_KEY = '1234-5678-9012-3456';

	/**
	 * Mocks the ifthenpay HTTP layer first — saving the Backoffice Key goes through the plugin's
	 * own save-time validation, which makes a real network call unless intercepted.
	 */
	protected function setUp(): void {
		parent::setUp();
		ifthenpay_lp_reset_method_catalog_cache();

		add_filter( 'pre_http_request', array( $this, 'mock_ifthenpay_http' ), 10, 3 );

		OsSettingsHelper::save_setting_by_name( 'enable_payment_processor_ifthenpay', LATEPOINT_VALUE_ON );
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_backoffice_key', self::BACKOFFICE_KEY );
	}

	/**
	 * Answers every ifthenpay HTTP call this test needs, branching on URL — same shape as
	 * DeferredCheckoutTest's own mock, kept separate since this file's tests toggle the gateway
	 * dataset response itself (fail_gateway_fetch()) rather than only the settings around it.
	 *
	 * @param mixed               $preempt As passed by the pre_http_request filter; unused.
	 * @param array<string,mixed> $args    As passed by the pre_http_request filter; unused.
	 * @param string              $url     The request URL — determines which fixture to answer with.
	 * @return array{response: array{code:int,message:string}, body:string, headers: array<empty>}|mixed
	 */
	public function mock_ifthenpay_http( $preempt, $args, $url ) {
		if ( false !== strpos( $url, 'getEntidadeSubentidadeJsonV2' ) ) {
			return array(
				'response' => array(
					'code'    => 200,
					'message' => '',
				),
				'body'     => '[]',
				'headers'  => array(),
			);
		}
		if ( false !== strpos( $url, 'gateway/methods/available' ) ) {
			return ifthenpay_lp_mock_response( 200, ifthenpay_lp_fixture( 'method-catalog.json' ) );
		}
		if ( false !== strpos( $url, 'gateway/get' ) ) {
			return ifthenpay_lp_mock_response( 200, ifthenpay_lp_fixture( 'gateway-dataset.json' ) );
		}

		return $preempt;
	}

	/**
	 * The enabled-methods filter's own empty accumulator, the shape LatePoint always calls it with.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function empty_payment_times(): array {
		return array(
			LATEPOINT_PAYMENT_TIME_NOW   => array(),
			LATEPOINT_PAYMENT_TIME_LATER => array(),
		);
	}

	/**
	 * MODERN-GATEWAY (tests/fixtures/ifthenpay/gateway-dataset.json) carries a live MBWAY account —
	 * enabling it is enough on its own for PayByLink to be offered.
	 */
	public function test_pay_by_link_is_offered_when_an_enabled_method_has_a_live_account(): void {
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_gateway_key', 'MODERN-GATEWAY' );
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_payment_methods_configuration', array( 'MBWAY' ) );

		$payment_times = IfthenpayLpPaymentMethodAvailability::add_enabled_payment_methods_to_payment_times( $this->empty_payment_times() );

		$this->assertArrayHasKey( 'ifthenpay_gateway', $payment_times[ LATEPOINT_PAYMENT_TIME_NOW ] );
	}

	/**
	 * LEGACY-GATEWAY carries no MBWAY account at all (only a static Multibanco key) — enabling MBWAY
	 * for it is a configuration nobody can actually pay through, so PayByLink must not be offered,
	 * silently, the same way Multibanco already disappears rather than failing once picked.
	 */
	public function test_pay_by_link_is_hidden_when_the_enabled_method_has_no_account_on_this_gateway(): void {
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_gateway_key', 'LEGACY-GATEWAY' );
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_payment_methods_configuration', array( 'MBWAY' ) );

		$payment_times = IfthenpayLpPaymentMethodAvailability::add_enabled_payment_methods_to_payment_times( $this->empty_payment_times() );

		$this->assertArrayNotHasKey( 'ifthenpay_gateway', $payment_times[ LATEPOINT_PAYMENT_TIME_NOW ] );
	}

	/**
	 * MODERN-GATEWAY has a live MBWAY account, but the merchant only enabled MB — a real account
	 * existing on the gateway is not enough on its own; the merchant must have actually enabled a
	 * Pay By Link-eligible method for it to count.
	 */
	public function test_pay_by_link_is_hidden_when_only_a_deferred_method_is_enabled(): void {
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_gateway_key', 'MODERN-GATEWAY' );
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_payment_methods_configuration', array( 'MB' ) );

		$payment_times = IfthenpayLpPaymentMethodAvailability::add_enabled_payment_methods_to_payment_times( $this->empty_payment_times() );

		$this->assertArrayNotHasKey( 'ifthenpay_gateway', $payment_times[ LATEPOINT_PAYMENT_TIME_NOW ] );
	}

	/**
	 * An ifthenpay outage while fetching the gateway dataset must not itself remove PayByLink from
	 * an otherwise valid setup — same fail-open reasoning as every other gate in this class.
	 */
	public function test_pay_by_link_stays_offered_when_the_gateway_dataset_fetch_fails(): void {
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_gateway_key', 'MODERN-GATEWAY' );
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_payment_methods_configuration', array( 'MBWAY' ) );

		remove_filter( 'pre_http_request', array( $this, 'mock_ifthenpay_http' ), 10 );
		add_filter( 'pre_http_request', array( $this, 'fail_gateway_fetch' ), 10, 3 );

		$payment_times = IfthenpayLpPaymentMethodAvailability::add_enabled_payment_methods_to_payment_times( $this->empty_payment_times() );

		$this->assertArrayHasKey( 'ifthenpay_gateway', $payment_times[ LATEPOINT_PAYMENT_TIME_NOW ] );
	}

	/**
	 * Every ifthenpay HTTP call fails — simulates a full outage.
	 *
	 * @param mixed               $preempt As passed by the pre_http_request filter; unused.
	 * @param array<string,mixed> $args    As passed by the pre_http_request filter; unused.
	 * @param string              $url     As passed by the pre_http_request filter; unused.
	 * @return WP_Error
	 */
	public function fail_gateway_fetch( $preempt, $args, $url ) {
		return new WP_Error( 'http_request_failed', 'timeout' );
	}

	/**
	 * Regression: gating PayByLink independently must not change how Multibanco is decided — only
	 * MB is enabled, MODERN-GATEWAY has a live MB account, so Multibanco is offered and PayByLink
	 * (no Pay By Link-eligible method enabled) is not — each method's own gate, no interaction.
	 */
	public function test_multibanco_is_unaffected_by_the_pay_by_link_gate(): void {
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_gateway_key', 'MODERN-GATEWAY' );
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_payment_methods_configuration', array( 'MB' ) );

		$payment_times = IfthenpayLpPaymentMethodAvailability::add_enabled_payment_methods_to_payment_times( $this->empty_payment_times() );

		$this->assertArrayHasKey( 'ifthenpay_multibanco', $payment_times[ LATEPOINT_PAYMENT_TIME_LATER ] );
		$this->assertArrayNotHasKey( 'ifthenpay_gateway', $payment_times[ LATEPOINT_PAYMENT_TIME_NOW ] );
	}
}
