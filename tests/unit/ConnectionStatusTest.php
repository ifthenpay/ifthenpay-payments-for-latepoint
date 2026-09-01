<?php
/**
 * Proves the two small status-pill renderers in IfthenpayAdminFormRenderer, both built on the
 * same render_status_pill():
 *
 * - render_connection_status(): silent for a fully usable key — the "Disconnect" button
 *   already says that — and only pills the two states it can't say on its own, "couldn't check"
 *   and "connected but nothing to configure yet". A rejected key can never reach this method at
 *   all, since save-time validation already blocks that save; "Rejected" is the "Connect"
 *   preview's own state, not this one's.
 * - render_callback_status(): silent unless a callback registration attempt is on
 *   record and failed — success and "never attempted" both render nothing.
 *
 * @package ifthenpay-payments-for-latepoint
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/views/ifthenpay-admin-form-renderer.php';
require_once __DIR__ . '/../support/class-os-form-helper-stub.php';

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

		$this->assertStringContainsString( 'ifthenpay-status-error', $html );
		$this->assertStringNotContainsString( 'ifthenpay-status-disabled', $html );
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

		$this->assertStringContainsString( 'payment-processor-status-charges-disabled', $html );
		$this->assertStringContainsString( 'ifthenpay-onboarding-steps', $html );
	}

	/**
	 * A non-empty gatewaykeys list is a fully usable key — nothing to say here, since the
	 * "Disconnect" button (rendered elsewhere, by render_backoffice_configuration()) already says
	 * that plainly.
	 */
	public function test_non_empty_gatewaykeys_renders_nothing(): void {
		ob_start();
		IfthenpayAdminFormRenderer::render_connection_status(
			array(
				'gatewaykeys' => array( 'GATEWAY-1' => 'GATEWAY-1' ),
				'accounts'    => array(),
			)
		);
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}

	/**
	 * No saved key: the Connect/Disconnect button is in "connect" mode.
	 */
	public function test_empty_backoffice_key_renders_connect_mode(): void {
		ob_start();
		IfthenpayAdminFormRenderer::render_backoffice_configuration( '' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'mode-connect', $html );
		$this->assertStringContainsString( 'data-mode="connect"', $html );
	}

	/**
	 * A saved key: the button is in "disconnect" mode — this is the only signal that a key is
	 * usable, since render_connection_status() itself renders nothing for that case.
	 */
	public function test_saved_backoffice_key_renders_disconnect_mode(): void {
		ob_start();
		IfthenpayAdminFormRenderer::render_backoffice_configuration( '1234-5678-9012-3456' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'mode-disconnect', $html );
		$this->assertStringContainsString( 'data-mode="disconnect"', $html );
	}

	/**
	 * No status ever recorded (null) renders nothing — a fresh install or a Gateway Key that was
	 * never saved is not a failure worth a merchant's attention.
	 */
	public function test_callback_status_null_renders_nothing(): void {
		ob_start();
		IfthenpayAdminFormRenderer::render_callback_status( null );
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}

	/**
	 * A successful registration renders nothing either — only a confirmed failure is worth
	 * surfacing.
	 */
	public function test_callback_status_success_renders_nothing(): void {
		ob_start();
		IfthenpayAdminFormRenderer::render_callback_status(
			array(
				'success'       => true,
				'message'       => '',
				'registered_at' => 12345,
			)
		);
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}

	/**
	 * A confirmed failure renders the alert pill with the stored reason — a merchant sees exactly
	 * why, without re-entering the form.
	 */
	public function test_callback_status_failure_renders_error_pill_with_reason(): void {
		ob_start();
		IfthenpayAdminFormRenderer::render_callback_status(
			array(
				'success'       => false,
				'message'       => 'ifthenpay did not accept this callback URL.',
				'registered_at' => 12345,
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ifthenpay-status-error', $html );
		$this->assertStringContainsString( 'ifthenpay did not accept this callback URL.', $html );
	}
}
