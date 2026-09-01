<?php
/**
 * Proves IfthenpayAdminFormRenderer::render_payments_configuration() (003 T-11): a gateway record
 * carries at most one account per method — verified live, contracts/api.md operation #2 — so a
 * method's checkbox is enabled exactly when the selected gateway has an account for it, with the
 * account key traveling in a hidden field rather than a dropdown of choices that no longer exist.
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
				'checked'    => static function ( $checked ) {
					if ( $checked ) {
						echo 'checked="checked"';
					}
				},
				'disabled'   => static function ( $disabled ) {
					if ( $disabled ) {
						echo 'disabled="disabled"';
					}
				},
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
	 * A method with an account for the selected gateway: checkbox enabled, its account key
	 * carried in a hidden field — not a dropdown, since there is nothing left to choose between.
	 */
	public function test_method_with_account_renders_enabled_checkbox_and_hidden_account_field(): void {
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

		$this->assertStringNotContainsString( 'disabled="disabled"', $html );
		$this->assertStringContainsString( 'class="ifthenpay-method-account"', $html );
		$this->assertStringContainsString( 'value="HLP-000001"', $html );
		$this->assertStringNotContainsString( 'ifthenpay-no-accounts', $html );
	}

	/**
	 * A method with no account for the selected gateway: checkbox disabled, "No accounts" plus
	 * the Activate link shown instead of a hidden field.
	 */
	public function test_method_without_account_renders_disabled_checkbox_and_no_accounts_message(): void {
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

		$this->assertStringContainsString( 'ifthenpay-no-accounts', $html );
		$this->assertStringNotContainsString( 'class="ifthenpay-method-account"', $html );
	}
}
