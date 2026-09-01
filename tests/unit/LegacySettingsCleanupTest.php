<?php
/**
 * Proves IfthenpayLpLegacySettingsCleanup::maybe_run(): the obsolete settings are
 * deleted exactly once, a fresh install with nothing to delete still stamps the version so this
 * never has to run again, and a second call after that is a true no-op.
 *
 * @package ifthenpay-payments-for-latepoint
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/helpers/ifthenpay-lp-legacy-settings-cleanup.php';
require_once __DIR__ . '/../support/class-os-settings-helper-stub.php';

/**
 * Legacy settings cleanup proof.
 */
final class LegacySettingsCleanupTest extends TestCase {

	/**
	 * In-memory stand-in for get_option()/update_option(), reset before every test.
	 *
	 * @var array<string,mixed>
	 */
	private array $options = array();

	/**
	 * Boots Brain Monkey and stubs the WP option functions this class touches.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options            = array();
		OsSettingsHelper::$values = array();

		Functions\when( 'get_option' )->alias(
			function ( string $name, $default_value = false ) {
				return $this->options[ $name ] ?? $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
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
	 * A site with the obsolete settings still stored has them deleted the first time this runs.
	 */
	public function test_deletes_the_obsolete_settings_on_first_run(): void {
		OsSettingsHelper::$values = array(
			'ifthenpay_gateway_key'       => 'HLPD-000001',
			'ifthenpay_gateway_options'   => array( 'HLPD-000001' => 'HLPD-000001' ),
			'ifthenpay_available_methods' => array( 'MBWAY' => array() ),
			'ifthenpay_backoffice_key'    => '1234-5678-9012-3456',
		);

		IfthenpayLpLegacySettingsCleanup::maybe_run();

		$this->assertNull( OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key' ) );
		$this->assertNull( OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_options' ) );
		$this->assertNull( OsSettingsHelper::get_settings_value( 'ifthenpay_available_methods' ) );

		// Untouched — still an actively used setting, not part of this migration.
		$this->assertSame( '1234-5678-9012-3456', OsSettingsHelper::get_settings_value( 'ifthenpay_backoffice_key' ) );
	}

	/**
	 * A fresh install has nothing to delete, but still stamps the version so this never runs
	 * again — a plain no-op deletion, not an error.
	 */
	public function test_fresh_install_stamps_version_with_nothing_to_delete(): void {
		IfthenpayLpLegacySettingsCleanup::maybe_run();

		$this->assertSame( '1.0.0', $this->options['ifthenpay_lp_legacy_settings_cleanup_version'] );
	}

	/**
	 * A second call, after the version is already stamped, does not touch settings again — a
	 * merchant who re-saves a Gateway Key after upgrading must not have it wiped on every request.
	 */
	public function test_second_call_after_migration_does_not_delete_again(): void {
		IfthenpayLpLegacySettingsCleanup::maybe_run();

		OsSettingsHelper::$values['ifthenpay_gateway_key'] = 'FRESH-GATEWAY-KEY';

		IfthenpayLpLegacySettingsCleanup::maybe_run();

		$this->assertSame( 'FRESH-GATEWAY-KEY', OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key' ) );
	}
}
