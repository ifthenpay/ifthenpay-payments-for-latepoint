<?php
/**
 * Uninstall handler — clears this plugin's own state (native WP options, transients, the scheduled
 * expiry-sweep cron, and every LatePoint setting this addon owns exclusively) so a full removal
 * doesn't leave orphaned rows in wp_options indefinitely.
 *
 * Deliberately leaves both the active `ifthenpay_transactions` table and the `_legacy` table it
 * migrates from untouched. The same reasoning that already governs never DROPping `_legacy` — an
 * audit trail settling disputes raised months later — applies at least as strongly to the active
 * table, which holds the real settlement records. Dropping payment history on uninstall would be a
 * deliberate decision for a future release with its own retention policy, not a silent default.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach (
	array(
		'ifthenpay_lp_transactions_schema_version',
		'ifthenpay_lp_legacy_settings_cleanup_version',
		'ifthenpay_lp_callback_status',
		'latepoint-payments-ifthenpay_addon_db_version',
	) as $option_name
) {
	delete_option( $option_name );
}

delete_transient( 'ifthenpay_lp_method_catalog' );

// Keyed per Backoffice Key (IfthenpayLpGatewayDataset::transient_key()), so there is no single known
// key to pass to delete_transient() — every value this plugin could ever have used shares this
// prefix. Also short-lived (1 minute) and would self-expire regardless; cleaned here for completeness.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall cleanup, not a request-path query.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_ifthenpay_lp_gateway_dataset_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_ifthenpay_lp_gateway_dataset_' ) . '%'
	)
);

// Loaded explicitly for its HOOK constant — nothing from this plugin's normal bootstrap has run at
// this point (WordPress calls this file standalone, not through includes()).
require_once __DIR__ . '/lib/models/settlement/ifthenpay-lp-expiry-sweep.php';
wp_clear_scheduled_hook( IfthenpayLpExpirySweep::HOOK );

// LatePoint's own settings API — only reachable when LatePoint itself is still loaded. It may not
// be: if both plugins are being bulk-deleted together and LatePoint's own files are removed first,
// there is nothing left to call, and no LatePoint settings left in its database either.
if ( class_exists( 'OsSettingsHelper' ) ) {
	foreach (
		array(
			'ifthenpay_backoffice_key',
			'ifthenpay_gateway_key',
			'ifthenpay_payment_methods_configuration',
			'ifthenpay_default_method',
			'ifthenpay_multibanco_validity_days',
			'ifthenpay_description',
		) as $setting_name
	) {
		OsSettingsHelper::remove_setting_by_name( $setting_name );
	}
}
