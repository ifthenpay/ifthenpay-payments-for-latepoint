<?php
/**
 * Proves IfthenpayAdminFormRenderer::render_connection_status() (003 T-10): the three states
 * reachable from a saved Backoffice Key — a rejected key can never be one of them, since 003 T-09
 * already blocks that save; "Rejected" is the "Connect" preview's own state, not this method's.
 *
 * @package ifthenpay-payments-for-latepoint
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/views/ifthenpay-admin-form-renderer.php';

/**
 * Connection status render proof.
 */
final class ConnectionStatusTest extends TestCase {

	/**
	 * Boots Brain Monkey and stubs the WP escaping/translation functions the renderer touches.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs(
			array(
				'esc_html'   => static fn( $text ) => $text,
				'esc_html__' => static fn( $text ) => $text,
				'esc_attr'   => static fn( $text ) => $text,
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
	 * A fetch failure (`null`) renders the grey/neutral "error" pill, not the red "disabled" one
	 * — a transport hiccup is not the merchant's problem to fix.
	 */
	public function test_null_dataset_renders_neutral_could_not_check_state(): void {
		ob_start();
		IfthenpayAdminFormRenderer::render_connection_status( null );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'os-column-status-error', $html );
		$this->assertStringNotContainsString( 'os-column-status-disabled', $html );
	}

	/**
	 * An empty gatewaykeys list is the normal first-run state — the amber "pending" pill, plus
	 * onboarding steps — never the same as the failure state above.
	 */
	public function test_empty_gatewaykeys_renders_pending_state_with_onboarding(): void {
		ob_start();
		IfthenpayAdminFormRenderer::render_connection_status(
			array(
				'gatewaykeys' => array(),
				'accounts'    => array(),
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'os-column-status-pending', $html );
		$this->assertStringContainsString( 'ifthenpay-onboarding-steps', $html );
	}

	/**
	 * A non-empty gatewaykeys list is the green "active"/connected pill, with no onboarding
	 * steps shown.
	 */
	public function test_non_empty_gatewaykeys_renders_connected_state(): void {
		ob_start();
		IfthenpayAdminFormRenderer::render_connection_status(
			array(
				'gatewaykeys' => array( 'GATEWAY-1' => 'GATEWAY-1' ),
				'accounts'    => array(),
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'os-column-status-active', $html );
		$this->assertStringNotContainsString( 'ifthenpay-onboarding-steps', $html );
	}
}
