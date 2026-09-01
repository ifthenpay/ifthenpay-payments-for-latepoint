<?php
/**
 * Proves IfthenpayAdminFormRenderer::render_payments_configuration() (003 T-11, native-component
 * cleanup pass): a gateway record carries at most one account per method — verified live,
 * contracts/api.md operation #2 — so a method's toggle switch (LatePoint's own
 * OsFormHelper::toggler_field(), not a hand-rolled checkbox) is on exactly when the selected
 * gateway has an account for it, with the account key traveling in a hidden field rather than a
 * dropdown of choices that no longer exist.
 *
 * @package ifthenpay-payments-for-latepoint
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/views/ifthenpay-admin-form-renderer.php';
require_once __DIR__ . '/../support/class-os-settings-helper-stub.php';
require_once __DIR__ . '/../support/class-os-form-helper-stub.php';

/**
 * Payments configuration render proof.
 */
final class PaymentsConfigurationTest extends TestCase {

	/**
	 * Boots Brain Monkey and stubs the WP functions the renderer touches.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		OsSettingsHelper::$values = array();

		Functions\stubs(
			array(
				'esc_html'   => static fn( $text ) => $text,
				'esc_html__' => static fn( $text ) => $text,
				'esc_attr'   => static fn( $text ) => $text,
				'esc_url'    => static fn( $text ) => $text,
			)
		);

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	/**
	 * Tears down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A method with an account for the selected gateway: the row is not marked disabled, its
	 * toggle switch is off by default (nothing saved yet) but clickable, and its account key
	 * travels in a hidden field — not a dropdown, since there is nothing left to choose between.
	 */
	public function test_method_with_account_renders_enabled_row_and_hidden_account_field(): void {
		OsSettingsHelper::$values['ifthenpay_gateway_key'] = 'GATEWAY-1';

		ob_start();
		IfthenpayAdminFormRenderer::render_payments_configuration(
			array( 'GATEWAY-1' => 'GATEWAY-1' ),
			array( 'GATEWAY-1' => array( 'MBWAY' => 'HLP-000001' ) ),
			array(
				'MBWAY' => array(
					'position' => 1,
					'image'    => '',
					'tooltip'  => '',
					'label'    => 'mbway',
				),
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'is-disabled', $html );
		$this->assertStringContainsString( 'class="ifthenpay-method-account"', $html );
		$this->assertStringContainsString( 'value="HLP-000001"', $html );
		$this->assertStringNotContainsString( 'ifthenpay-method-body', $html );
	}

	/**
	 * The method's own ifthenpay code (MB, MBWAY, …) is visible next to its display name, not
	 * only present as a `data-entity` attribute — a merchant reading ifthenpay's own docs or
	 * talking to their support team needs the code, not just the display label.
	 */
	public function test_method_code_is_visible_next_to_the_name(): void {
		OsSettingsHelper::$values['ifthenpay_gateway_key'] = 'GATEWAY-1';

		ob_start();
		IfthenpayAdminFormRenderer::render_payments_configuration(
			array( 'GATEWAY-1' => 'GATEWAY-1' ),
			array( 'GATEWAY-1' => array( 'MBWAY' => 'HLP-000001' ) ),
			array(
				'MBWAY' => array(
					'position' => 1,
					'image'    => '',
					'tooltip'  => '',
					'label'    => 'mbway',
				),
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<span class="ifthenpay-method-code">(MBWAY)</span>', $html );
	}

	/**
	 * A checked, saved method renders its toggle switch already on.
	 */
	public function test_checked_method_renders_toggler_on(): void {
		OsSettingsHelper::$values['ifthenpay_gateway_key']                   = 'GATEWAY-1';
		OsSettingsHelper::$values['ifthenpay_payment_methods_configuration'] = '{"MBWAY":{"checked":true}}';

		ob_start();
		IfthenpayAdminFormRenderer::render_payments_configuration(
			array( 'GATEWAY-1' => 'GATEWAY-1' ),
			array( 'GATEWAY-1' => array( 'MBWAY' => 'HLP-000001' ) ),
			array(
				'MBWAY' => array(
					'position' => 1,
					'image'    => '',
					'tooltip'  => '',
					'label'    => 'mbway',
				),
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'os-toggler on', $html );
		$this->assertStringNotContainsString( 'os-toggler off', $html );
	}

	/**
	 * A method with no account for the selected gateway: the row is marked disabled (locks the
	 * toggle via CSS — the native toggler has no disabled state of its own), and shows "No
	 * accounts" plus the Activate link instead of a hidden field.
	 */
	public function test_method_without_account_renders_disabled_row_and_no_accounts_message(): void {
		OsSettingsHelper::$values['ifthenpay_gateway_key'] = 'GATEWAY-1';

		ob_start();
		IfthenpayAdminFormRenderer::render_payments_configuration(
			array( 'GATEWAY-1' => 'GATEWAY-1' ),
			array(),
			array(
				'MBWAY' => array(
					'position' => 1,
					'image'    => '',
					'tooltip'  => '',
					'label'    => 'mbway',
				),
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'is-disabled', $html );
		$this->assertStringContainsString( 'ifthenpay-method-body', $html );
		$this->assertStringContainsString( 'No accounts.', $html );
		$this->assertStringNotContainsString( 'class="ifthenpay-method-account"', $html );
	}
}
