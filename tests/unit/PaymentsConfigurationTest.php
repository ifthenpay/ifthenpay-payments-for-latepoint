<?php
/**
 * Proves IfthenpayLpAdminFormRenderer::render_payments_configuration(): a gateway record carries at
 * most one account per method — verified live against ifthenpay's own API — so a method's
 * checkbox (LatePoint's own OsFormHelper::checkbox_field()) is natively `disabled` exactly when
 * the selected gateway has no account for it. Nothing beyond the enabled method codes is stored;
 * IfthenpayLpDataFormatter looks the account key up live at checkout time instead. Also proves the
 * always-present hidden fallback field this method renders alongside the real checkboxes, and that
 * its own empty-string value never reads back as an enabled method (see
 * IfthenpayLpDataFormatter::build_accounts_string() for the same guard on the checkout-time read of
 * this same setting).
 *
 * @package ifthenpay-payments-for-latepoint
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/views/ifthenpay-lp-admin-form-renderer.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/ifthenpay-lp-pay-by-link-method-eligibility.php';
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
		IfthenpayLpAdminFormRenderer::render_payments_configuration(
			'GATEWAY-1',
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
		$this->assertStringContainsString( 'name="settings[ifthenpay_payment_methods_configuration][]" value="MBWAY" />', $html );
	}

	/**
	 * The account key behind a method is visible on its row — not the method's own ifthenpay code,
	 * which the icon and name already identify. The code has no `data-entity`-only fallback either:
	 * a merchant needs to see which ifthenpay account a row actually charges to, not just recognize
	 * the method itself.
	 */
	public function test_account_key_is_visible_on_the_row(): void {
		OsSettingsHelper::$values['ifthenpay_gateway_key'] = 'GATEWAY-1';

		ob_start();
		IfthenpayLpAdminFormRenderer::render_payments_configuration(
			'GATEWAY-1',
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

		// The account-key span holds the real key, not the method's own code — the two would be
		// easy to confuse since both are short, uppercase-looking strings.
		$this->assertStringContainsString( '<span class="ifthenpay-method-account-key">HLP-000001</span>', $html );
		$this->assertStringNotContainsString( '<span class="ifthenpay-method-account-key">MBWAY</span>', $html );
	}

	/**
	 * A saved, enabled method renders its checkbox already checked.
	 */
	public function test_enabled_method_renders_checkbox_checked(): void {
		OsSettingsHelper::$values['ifthenpay_gateway_key']                   = 'GATEWAY-1';
		OsSettingsHelper::$values['ifthenpay_payment_methods_configuration'] = array( 'MBWAY' );

		ob_start();
		IfthenpayLpAdminFormRenderer::render_payments_configuration(
			'GATEWAY-1',
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
		IfthenpayLpAdminFormRenderer::render_payments_configuration(
			'GATEWAY-1',
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
		IfthenpayLpAdminFormRenderer::render_payments_configuration(
			'GATEWAY-1',
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
	 * "Pay Later Configuration" section, separate from Pay Now Configuration; a merchant can still
	 * check them (for whenever this plugin actually acts on them), but the separate section itself
	 * is what says "this behaves differently", not a disabled/greyed-out checkbox.
	 */
	public function test_mb_and_payshop_render_in_the_pay_later_section_not_pay_now(): void {
		OsSettingsHelper::$values['ifthenpay_gateway_key'] = 'GATEWAY-1';

		ob_start();
		IfthenpayLpAdminFormRenderer::render_payments_configuration(
			'GATEWAY-1',
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

		$pay_now_pos   = strpos( $html, 'Pay Now Configuration' );
		$pay_later_pos = strpos( $html, 'Pay Later Configuration' );
		$mb_pos        = strpos( $html, 'data-entity="MB"' );
		$mbway_pos     = strpos( $html, 'data-entity="MBWAY"' );

		$this->assertNotFalse( $pay_now_pos );
		$this->assertNotFalse( $pay_later_pos );
		// MBWAY sits after the "Pay Now Configuration" heading but before "Pay Later
		// Configuration"; MB sits after "Pay Later Configuration".
		$this->assertTrue( $pay_now_pos < $mbway_pos && $mbway_pos < $pay_later_pos );
		$this->assertTrue( $pay_later_pos < $mb_pos );
	}

	/**
	 * Default Method's own catalog fixture and render call, shared by the tests below — three
	 * eligible methods (MBWAY, CCARD, PIX) plus one ineligible one (MB), each with an account for
	 * GATEWAY-1, only some of them enabled. Account keys are the realistic `"CODE | value"` shape
	 * confirmed live against ifthenpay's own API — every eligible-as-default method's raw value
	 * already carries its own code, which is exactly what test_default_method_lists_eligible_methods_even_when_not_enabled()
	 * below needs to catch a re-introduced duplicate-code bug.
	 *
	 * @param string[] $enabled_methods Saved enabled method codes.
	 */
	private function render_default_method_fixture( array $enabled_methods ): string {
		OsSettingsHelper::$values['ifthenpay_gateway_key']                   = 'GATEWAY-1';
		OsSettingsHelper::$values['ifthenpay_payment_methods_configuration'] = $enabled_methods;

		$labels  = array(
			'MB'    => 'multibanco',
			'MBWAY' => 'mbway',
			'CCARD' => 'credit card',
			'PIX'   => 'pix',
		);
		$catalog = array();
		foreach ( $labels as $code => $label ) {
			$catalog[ $code ] = array(
				'position' => array_search( $code, array_keys( $labels ), true ),
				'image'    => '',
				'tooltip'  => '',
				'label'    => $label,
			);
		}

		ob_start();
		IfthenpayLpAdminFormRenderer::render_payments_configuration(
			'GATEWAY-1',
			array(
				'GATEWAY-1' => array(
					'MB'    => 'MB | HLP-000001',
					'MBWAY' => 'MBWAY | HLP-000002',
					'CCARD' => 'CCARD | HLP-000003',
					'PIX'   => 'PIX | HLP-000004',
				),
			),
			$catalog
		);
		$html = (string) ob_get_clean();

		$select_start = strpos( $html, 'name="settings[ifthenpay_default_method]"' );
		$select_start = strrpos( substr( $html, 0, $select_start ), '<select' );
		$select_end   = strpos( $html, '</select>', $select_start );

		return substr( $html, $select_start, $select_end - $select_start );
	}

	/**
	 * Multibanco never gets an `<option>` at all — not merely disabled — since it is never
	 * eligible as a default regardless of enabled state, per
	 * IfthenpayLpPayByLinkMethodEligibility::is_eligible_as_default().
	 */
	public function test_default_method_never_offers_an_ineligible_method_as_an_option(): void {
		$select_html = $this->render_default_method_fixture( array( 'MB', 'MBWAY' ) );

		$this->assertStringNotContainsString( 'value="MB"', $select_html );
	}

	/**
	 * An eligible method that isn't currently enabled still gets an `<option>` — just a `disabled`
	 * one — so the merchant sees the full set of possible defaults up front, not an empty dropdown
	 * that only grows as boxes are checked. Each option's text is the method's own display name,
	 * matching its checkbox row above — not its account key, which would read as a duplicate once
	 * the two are placed side by side in the same dropdown ("PIX" next to "PIX | HLP-000004" reads
	 * like two different things when they're the same one).
	 */
	public function test_default_method_lists_eligible_methods_even_when_not_enabled(): void {
		$select_html = $this->render_default_method_fixture( array( 'MBWAY' ) );

		$this->assertStringContainsString( '<option value="MBWAY">MBWAY</option>', $select_html );
		$this->assertStringContainsString( '<option value="CCARD" disabled>CREDIT CARD</option>', $select_html );
		$this->assertStringContainsString( '<option value="PIX" disabled>PIX</option>', $select_html );
	}

	/**
	 * The account key itself never appears in an option's text — one option per method is already
	 * unambiguous on its own, unlike a checkbox row, which needs it to say which ifthenpay account
	 * that specific row charges to.
	 */
	public function test_default_method_option_never_shows_the_account_key(): void {
		$select_html = $this->render_default_method_fixture( array( 'MBWAY' ) );

		$this->assertStringNotContainsString( 'HLP-000002', $select_html );
	}

	/**
	 * Unchecking every method's own checkbox submits no `[]` entry for this field at all — and
	 * LatePoint's own SettingsController::update() only saves setting names actually present in
	 * the submitted `settings` array, confirmed in its own source — so without an always-present
	 * fallback entry, disabling every previously-enabled method and saving would silently leave the
	 * old ones enabled. This hidden field is what keeps this setting's key in the request even
	 * then.
	 */
	public function test_hidden_reset_field_is_always_present(): void {
		ob_start();
		IfthenpayLpAdminFormRenderer::render_payments_configuration(
			'GATEWAY-1',
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

		$this->assertStringContainsString( '<input type="hidden" name="settings[ifthenpay_payment_methods_configuration][]" value="" />', $html );
	}

	/**
	 * A save with every method unchecked persists this setting as an array holding only the hidden
	 * fallback's own empty string (`['']`) — real checkbox codes are never empty, so this must read
	 * back as "nothing enabled", not as a phantom method that happens to be checked.
	 */
	public function test_saved_empty_string_entry_from_the_hidden_field_is_not_treated_as_a_method(): void {
		OsSettingsHelper::$values['ifthenpay_gateway_key']                   = 'GATEWAY-1';
		OsSettingsHelper::$values['ifthenpay_payment_methods_configuration'] = array( '' );

		ob_start();
		IfthenpayLpAdminFormRenderer::render_payments_configuration(
			'GATEWAY-1',
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

		$this->assertStringNotContainsString( 'checked', $html );
	}
}
