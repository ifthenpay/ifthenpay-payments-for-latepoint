<?php
/**
 * Uninstall handler.
 *
 * Drops nothing yet. An upgrade never DROPs a payments table — it renames
 * it to `_legacy` so the audit trail survives and can settle disputes
 * raised months later. The eventual DROP of that `_legacy` table belongs
 * here, in a future release, once its retention window has passed.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
