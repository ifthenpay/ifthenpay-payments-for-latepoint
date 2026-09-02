<?php
/**
 * Proves IfthenpayAdminFormRenderer::render_payments_configuration(): a gateway record carries at
 * most one account per method — verified live against ifthenpay's own API — so a method's
 * checkbox (LatePoint's own OsFormHelper::checkbox_field()) is natively `disabled` exactly when
 * the selected gateway has no account for it. Nothing beyond the enabled method codes is stored;
 * IfthenpayDataFormatter looks the account key up live at checkout time instead.
 *
 * @package ifthenpay-payments-for-latepoint
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/views/ifthenpay-admin-form-renderer.php';
require_once dirname( __DIR__, 2 ) . '/lib/helpers/ifthenpay-lp-pay-by-link-method-eligibility.php';
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
	}

	/**
	 * Tears down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A method with an account for the selected gateway: its checkbox is not disabled.
	 */
	public function test_method_with_account_renders_enabled_checkbox(): void {
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
		$this->assertStringNotContainsString( 'disabled', $html );
	}

	/**
	 * The account key behind a method is visible next to its display name — not the method's own
	 * ifthenpay code, which the icon and name already identify. The code has no `data-entity`-only
	 * fallback either: a merchant needs to see which ifthenpay account a row actually charges to,
	 * not just recognize the method itself.
	 */
	public function test_account_key_is_visible_next_to_the_name(): void {
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

		$this->assertStringContainsString( '<span class="ifthenpay-method-account-key">(HLP-000001)</span>', $html );
		$this->assertStringNotContainsString( '(MBWAY)', $html );
	}

	/**
	 * A saved, enabled method renders its checkbox already checked.
	 */
	public function test_enabled_method_renders_checkbox_checked(): void {
		OsSettingsHelper::$values['ifthenpay_gateway_key']                   = 'GATEWAY-1';
		OsSettingsHelper::$values['ifthenpay_payment_methods_configuration'] = array( 'MBWAY' );

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

		$this->assertStringContainsString( 'checked', $html );
	}

	/**
	 * A method with no account for the selected gateway: its checkbox is natively `disabled` —
	 * browsers already exclude a disabled field from submission, so it cannot be saved as enabled
	 * without this plugin doing anything else about it — and the "No accounts" note is present.
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

		$this->assertStringContainsString( 'is-disabled', $html );
		$this->assertStringContainsString( ' disabled', $html );
		$this->assertStringContainsString( 'ifthenpay-no-accounts', $html );
		$this->assertStringContainsString( 'No accounts.', $html );
	}

	/**
	 * A method with no account for the selected gateway is never checked, even if it was saved as
	 * enabled while a different gateway had an account for it — a stale "enabled" state must not
	 * look selected for a gateway it doesn't apply to.
	 */
	public function test_stale_enabled_state_does_not_check_a_now_unavailable_method(): void {
		OsSettingsHelper::$values['ifthenpay_gateway_key']                   = 'GATEWAY-1';
		OsSettingsHelper::$values['ifthenpay_payment_methods_configuration'] = array( 'MBWAY' );

		ob_start();
		IfthenpayAdminFormRenderer::render_payments_configuration(
			array( 'GATEWAY-1' => 'GATEWAY-1' ),
			array(), // No accounts at all for GATEWAY-1.
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

		$this->assertStringNotContainsString( 'checked', $html );
	}

	/**
	 * MB and PAYSHOP — never offered by Pay By Link at all, per ifthenpay — render under their own
	 * "Deferred" group, separate from the PBL-eligible methods; a merchant can still check them
	 * (for whenever this plugin actually acts on them), but the grouping itself is what says "this
	 * behaves differently", not a disabled/greyed-out checkbox.
	 */
	public function test_mb_and_payshop_render_in_the_deferred_group_not_pay_now(): void {
		OsSettingsHelper::$values['ifthenpay_gateway_key'] = 'GATEWAY-1';

		ob_start();
		IfthenpayAdminFormRenderer::render_payments_configuration(
			array( 'GATEWAY-1' => 'GATEWAY-1' ),
			array(
				'GATEWAY-1' => array(
					'MB'    => 'HLP-000001',
					'MBWAY' => 'HLP-000002',
				),
			),
			array(
				'MB'    => array(
					'position' => 1,
					'image'    => '',
					'tooltip'  => '',
					'label'    => 'multibanco',
				),
				'MBWAY' => array(
					'position' => 2,
					'image'    => '',
					'tooltip'  => '',
					'label'    => 'mbway',
				),
			)
		);
		$html = (string) ob_get_clean();

		$pay_now_pos  = strpos( $html, 'Pay Now' );
		$deferred_pos = strpos( $html, 'Deferred' );
		$mb_pos       = strpos( $html, 'data-entity="MB"' );
		$mbway_pos    = strpos( $html, 'data-entity="MBWAY"' );

		$this->assertNotFalse( $pay_now_pos );
		$this->assertNotFalse( $deferred_pos );
		// MBWAY sits after the "Pay Now" heading but before "Deferred"; MB sits after "Deferred".
		$this->assertTrue( $pay_now_pos < $mbway_pos && $mbway_pos < $deferred_pos );
		$this->assertTrue( $deferred_pos < $mb_pos );
	}

	/**
	 * Default Method offers only methods PBL can actually be told to pre-select — MB, PAYSHOP,
	 * GOOGLE, and APPLE are all excluded even when enabled, per
	 * IfthenpayLpPayByLinkMethodEligibility::is_eligible_as_default().
	 */
	public function test_default_method_excludes_ineligible_enabled_methods(): void {
		OsSettingsHelper::$values['ifthenpay_gateway_key']                   = 'GATEWAY-1';
		OsSettingsHelper::$values['ifthenpay_payment_methods_configuration'] = array( 'MB', 'MBWAY', 'GOOGLE' );

		ob_start();
		IfthenpayAdminFormRenderer::render_payments_configuration(
			array( 'GATEWAY-1' => 'GATEWAY-1' ),
			array(
				'GATEWAY-1' => array(
					'MB'     => 'HLP-000001',
					'MBWAY'  => 'HLP-000002',
					'GOOGLE' => 'HLP-000003',
				),
			),
			array(
				'MB'     => array(
					'position' => 1,
					'image'    => '',
					'tooltip'  => '',
					'label'    => 'multibanco',
				),
				'MBWAY'  => array(
					'position' => 2,
					'image'    => '',
					'tooltip'  => '',
					'label'    => 'mbway',
				),
				'GOOGLE' => array(
					'position' => 6,
					'image'    => '',
					'tooltip'  => '',
					'label'    => 'google pay',
				),
			)
		);
		$html = (string) ob_get_clean();

		$select_start = strpos( $html, 'name="settings[ifthenpay_default_method]"' );
		$select_start = strrpos( substr( $html, 0, $select_start ), '<select' );
		$select_end   = strpos( $html, '</select>', $select_start );
		$select_html  = substr( $html, $select_start, $select_end - $select_start );

		$this->assertStringContainsString( 'MBWAY', $select_html );
		$this->assertStringNotContainsString( 'MB<', $select_html );
		$this->assertStringNotContainsString( 'GOOGLE', $select_html );
	}
}
