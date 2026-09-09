<?php
/**
 * Proves two things in IfthenpayLpAdminFormRenderer:
 *
 * - get_connection_notice(): `null` for a fully usable key — the "Disconnect" button
 *   already says that — and a toast-ready `{type, message}` for the two states it can't say on
 *   its own, "couldn't check" and "connected but nothing to configure yet". A rejected key can
 *   never reach this method at all, since save-time validation already blocks that save;
 *   "Rejected" is the "Connect" preview's own state, not this one's. Both callers (the page's own
 *   render and the "Connect" preview) surface the result as a toast, not inline markup.
 * - render_callback_status(): silent unless a callback registration attempt is on
 *   record and failed — success and "never attempted" both render nothing.
 * - render_backoffice_configuration(): the Connect/Disconnect button's mode follows whether a key
 *   is saved, and the Gateway Key row renders alongside it once there are gateway keys to pick
 *   from (see GatewayKeyRowTest for resolve_selected_gateway_key() and render_gateway_key_row()
 *   on their own).
 *
 * @package ifthenpay-payments-for-latepoint
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/views/ifthenpay-lp-admin-form-renderer.php';
require_once __DIR__ . '/../support/class-os-settings-helper-stub.php';
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

		OsSettingsHelper::$values = array();

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
	 * A fetch failure (`null`) is a toast-ready "error" notice — a transport hiccup is not the
	 * merchant's problem to fix, but it is worth a heads-up.
	 */
	public function test_null_dataset_returns_error_notice(): void {
		$notice = IfthenpayLpAdminFormRenderer::get_connection_notice( null );

		$this->assertNotNull( $notice );
		$this->assertSame( 'error', $notice['type'] );
	}

	/**
	 * An empty gatewaykeys list is the normal first-run state — its own notice, including the
	 * ifthenpay helpdesk contact so the merchant knows the next step, never the same as the
	 * failure state above.
	 */
	public function test_empty_gatewaykeys_returns_notice_with_helpdesk_contact(): void {
		$notice = IfthenpayLpAdminFormRenderer::get_connection_notice(
			array(
				'gatewaykeys' => array(),
				'accounts'    => array(),
			)
		);

		$this->assertNotNull( $notice );
		$this->assertStringContainsString( 'helpdesk.ifthenpay.com', $notice['message'] );
	}

	/**
	 * A non-empty gatewaykeys list is a fully usable key — nothing to say here, since the
	 * "Disconnect" button (rendered elsewhere, by render_backoffice_configuration()) already says
	 * that plainly.
	 */
	public function test_non_empty_gatewaykeys_returns_null(): void {
		$notice = IfthenpayLpAdminFormRenderer::get_connection_notice(
			array(
				'gatewaykeys' => array( 'GATEWAY-1' => 'GATEWAY-1' ),
				'accounts'    => array(),
			)
		);

		$this->assertNull( $notice );
	}

	/**
	 * No saved key: the Connect/Disconnect button is in "connect" mode.
	 */
	public function test_empty_backoffice_key_renders_connect_mode(): void {
		ob_start();
		IfthenpayLpAdminFormRenderer::render_backoffice_configuration( '' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'mode-connect', $html );
		$this->assertStringContainsString( 'data-mode="connect"', $html );
	}

	/**
	 * A saved key: the button is in "disconnect" mode — this is the only signal that a key is
	 * usable, since get_connection_notice() itself returns null for that case.
	 */
	public function test_saved_backoffice_key_renders_disconnect_mode(): void {
		ob_start();
		IfthenpayLpAdminFormRenderer::render_backoffice_configuration( '1234-5678-9012-3456' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'mode-disconnect', $html );
		$this->assertStringContainsString( 'data-mode="disconnect"', $html );
	}

	/**
	 * A saved key with gateway keys of its own renders the Gateway Key row inside the same
	 * section — not only the Backoffice Key field and its button — since resolve_selected_gateway_key()
	 * and render_gateway_key_row() together are what let a merchant reload the page and immediately
	 * see (and change) which gateway this site charges through.
	 */
	public function test_saved_backoffice_key_with_gatewaykeys_renders_gateway_key_row(): void {
		ob_start();
		IfthenpayLpAdminFormRenderer::render_backoffice_configuration(
			'1234-5678-9012-3456',
			array( 'GATEWAY-1' => 'GATEWAY-1' ),
			'GATEWAY-1'
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<select name="settings[ifthenpay_gateway_key]">', $html );
	}

	/**
	 * No status ever recorded (null) renders nothing — a fresh install or a Gateway Key that was
	 * never saved is not a failure worth a merchant's attention.
	 */
	public function test_callback_status_null_renders_nothing(): void {
		ob_start();
		IfthenpayLpAdminFormRenderer::render_callback_status( null );
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}

	/**
	 * A successful registration renders nothing either — only a confirmed failure is worth
	 * surfacing.
	 */
	public function test_callback_status_success_renders_nothing(): void {
		ob_start();
		IfthenpayLpAdminFormRenderer::render_callback_status(
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
		IfthenpayLpAdminFormRenderer::render_callback_status(
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
