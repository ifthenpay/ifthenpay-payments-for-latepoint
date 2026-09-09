<?php
/**
 * Proves two things in IfthenpayLpAdminFormRenderer, both used by Backoffice Configuration's own
 * Gateway Key row and by the "Connect" preview's `gateway_key_html` response field:
 *
 * - resolve_selected_gateway_key(): the saved `ifthenpay_gateway_key` setting when it still names
 *   one of the current gateway keys, that gateway dataset's first key otherwise (including a
 *   stale key from a Backoffice Key this site no longer uses) — a `<select>` with no `selected`
 *   option shows its first `<option>` regardless of what the rest of the page computes from it, so
 *   without this fallback that visual default and the accounts actually looked up elsewhere would
 *   silently disagree.
 * - render_gateway_key_row(): nothing at all with no gateway keys yet, the `<select>` otherwise.
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
 * Gateway Key resolution and row-render proof.
 */
final class GatewayKeyRowTest extends TestCase {

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
	 * No gateway keys at all: nothing is selected, regardless of what's saved.
	 */
	public function test_no_gatewaykeys_resolves_to_empty_string(): void {
		OsSettingsHelper::$values['ifthenpay_gateway_key'] = 'GATEWAY-1';

		$this->assertSame( '', IfthenpayLpAdminFormRenderer::resolve_selected_gateway_key( array() ) );
	}

	/**
	 * Nothing saved yet: falls back to the first gateway key.
	 */
	public function test_no_saved_key_resolves_to_first_gatewaykey(): void {
		$resolved = IfthenpayLpAdminFormRenderer::resolve_selected_gateway_key(
			array(
				'GATEWAY-1' => 'GATEWAY-1',
				'GATEWAY-2' => 'GATEWAY-2',
			)
		);

		$this->assertSame( 'GATEWAY-1', $resolved );
	}

	/**
	 * A saved key that still names a real gateway key wins over the first-key fallback.
	 */
	public function test_saved_key_still_present_is_kept(): void {
		OsSettingsHelper::$values['ifthenpay_gateway_key'] = 'GATEWAY-2';

		$resolved = IfthenpayLpAdminFormRenderer::resolve_selected_gateway_key(
			array(
				'GATEWAY-1' => 'GATEWAY-1',
				'GATEWAY-2' => 'GATEWAY-2',
			)
		);

		$this->assertSame( 'GATEWAY-2', $resolved );
	}

	/**
	 * A saved key from a Backoffice Key this site no longer uses (or a gateway key since revoked)
	 * doesn't exist among the current gateway keys — falls back to the first one, the same as
	 * nothing being saved at all, rather than silently keeping a selection that can't be honoured.
	 */
	public function test_stale_saved_key_falls_back_to_first_gatewaykey(): void {
		OsSettingsHelper::$values['ifthenpay_gateway_key'] = 'OLD-GATEWAY-FROM-A-DIFFERENT-KEY';

		$resolved = IfthenpayLpAdminFormRenderer::resolve_selected_gateway_key(
			array( 'GATEWAY-1' => 'GATEWAY-1' )
		);

		$this->assertSame( 'GATEWAY-1', $resolved );
	}

	/**
	 * No gateway keys: the row renders nothing at all, not an empty `<select>`.
	 */
	public function test_render_gateway_key_row_with_no_gatewaykeys_is_empty(): void {
		$html = IfthenpayLpAdminFormRenderer::render_gateway_key_row( array(), '' );

		$this->assertSame( '', $html );
	}

	/**
	 * With gateway keys, the row renders the `<select>` with the resolved key selected.
	 */
	public function test_render_gateway_key_row_with_gatewaykeys_renders_select(): void {
		$html = IfthenpayLpAdminFormRenderer::render_gateway_key_row(
			array( 'GATEWAY-1' => 'GATEWAY-1' ),
			'GATEWAY-1'
		);

		$this->assertStringContainsString( '<select name="settings[ifthenpay_gateway_key]">', $html );
		$this->assertStringContainsString( '<option value="GATEWAY-1" selected>', $html );
	}
}
