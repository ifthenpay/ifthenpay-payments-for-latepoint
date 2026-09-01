<?php
/**
 * One-time upgrade cleanup (003 T-14) — a pre-003 site's stored `ifthenpay_gateway_key` was
 * chosen from the old mobile API's gateway list, which is not the same context-scoped dataset
 * this version validates against (IfthenpayLpGatewayDataset). Rather than trust a value that
 * might coincidentally still work, the migration deletes it outright: single release, manual
 * reconfiguration, no overlap path (see plan.md §7). FR-13 already means an unusable
 * configuration offers no ifthenpay method at checkout, so nothing fails mid-payment either way —
 * this is about not leaving a stale secret in the database, not about checkout safety.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes settings a pre-003 install stored under a data source this version no longer trusts.
 */
class IfthenpayLpLegacySettingsCleanup {

	private const MIGRATION_VERSION        = '1.0.0';
	private const MIGRATION_VERSION_OPTION = 'ifthenpay_lp_legacy_settings_cleanup_version';

	/**
	 * `ifthenpay_gateway_key` is the one named in plan.md §7. The other two are settings T-11
	 * retired at the same time — nothing reads or writes them any more (replaced by
	 * IfthenpayLpGatewayDataset / IfthenpayLpMethodCatalog, fetched live instead of cached in a
	 * setting) — so a pre-003 site's stored values for them are equally stale, not just unused.
	 *
	 * @var string[]
	 */
	private const OBSOLETE_SETTING_NAMES = array(
		'ifthenpay_gateway_key',
		'ifthenpay_gateway_options',
		'ifthenpay_available_methods',
	);

	/**
	 * Deletes the obsolete settings once. Safe to call on every request (cheap no-op once done)
	 * and on a fresh install (nothing to delete, still stamps the version so it never re-runs).
	 */
	public static function maybe_run(): void {
		if ( get_option( self::MIGRATION_VERSION_OPTION ) === self::MIGRATION_VERSION ) {
			return;
		}

		foreach ( self::OBSOLETE_SETTING_NAMES as $setting_name ) {
			OsSettingsHelper::remove_setting_by_name( $setting_name );
		}

		update_option( self::MIGRATION_VERSION_OPTION, self::MIGRATION_VERSION );
	}
}
