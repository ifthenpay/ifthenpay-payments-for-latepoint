<?php
/**
 * Repository for {prefix}ifthenpay_transactions.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single payment record shared by every method and both flows (realtime and deferred). One
 * table, one UNIQUE(request_id), so settlement finds a record without knowing the method first.
 */
class IfthenpayLpTransactionRepository {

	private const SCHEMA_VERSION        = '1.0.0';
	private const SCHEMA_VERSION_OPTION = 'ifthenpay_lp_transactions_schema_version';
	private const CACHE_GROUP           = 'ifthenpay_lp_transactions';

	/**
	 * Method value for rows migrated from — or newly created through — the realtime Pay By Link
	 * flow. The flow only ever knows it went through Pay By Link, never which underlying ifthenpay
	 * method (MB WAY, card, …) the customer picked on ifthenpay's own hosted page, so a real
	 * per-method code (`MB`, `MBWAY`, …) is not available here the way it is for deferred methods.
	 */
	public const METHOD_PAYBYLINK = 'PAYBYLINK';

	/**
	 * The fully qualified table name.
	 */
	private static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'ifthenpay_transactions';
	}

	/**
	 * Creates the table on first activation, or upgrades it when the stored schema version is
	 * behind — runs on every request via `init`, not only on activation, so an in-place plugin
	 * update (no reactivation) still gets the schema change.
	 */
	public static function maybe_upgrade_schema(): void {
		if ( get_option( self::SCHEMA_VERSION_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}

		self::create_table();
		self::migrate_legacy_pending_and_retire();
		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * Idempotent: dbDelta only ever adds/adjusts columns and indexes, never drops data.
	 */
	public static function create_table(): void {
		global $wpdb;
		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token VARCHAR(255) NOT NULL,
			request_id VARCHAR(255) DEFAULT NULL,
			intent_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(20) NOT NULL,
			method VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
			amount DECIMAL(10,2) DEFAULT NULL,
			gateway_key VARCHAR(255) DEFAULT NULL,
			entity VARCHAR(20) DEFAULT NULL,
			reference VARCHAR(255) DEFAULT NULL,
			expires_at DATETIME DEFAULT NULL,
			pin_code VARCHAR(255) DEFAULT NULL,
			paybylink_url VARCHAR(255) DEFAULT NULL,
			settled_at DATETIME DEFAULT NULL,
			method_data LONGTEXT DEFAULT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			UNIQUE KEY request_id (request_id),
			KEY status_expires (status, expires_at),
			KEY intent_id (intent_id)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Inserts a new record. Callers pass only the columns they have — every method/kind populates
	 * a different subset of the nullable ones.
	 *
	 * @param array<string,mixed> $data Column => value. Must include token, intent_id, kind, method.
	 * @return int|false Insert id, or false if the database rejected the row (e.g. duplicate
	 *                    token/request_id).
	 */
	public static function insert( array $data ) {
		global $wpdb;
		$data += array( 'status' => 'PENDING' );

		$inserted = $wpdb->insert( self::table_name(), $data );

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * The settlement lookup: finds a record by ifthenpay's request_id alone, with no prior
	 * knowledge of which method or flow produced it.
	 *
	 * @param string $request_id ifthenpay's requestId, treated as an opaque string.
	 */
	public static function find_by_request_id( string $request_id ): ?object {
		return self::find_one( 'request_id', $request_id );
	}

	/**
	 * Finds a record by our own token.
	 *
	 * @param string $token Our correlation handle.
	 */
	public static function find_by_token( string $token ): ?object {
		return self::find_one( 'token', $token );
	}

	/**
	 * Sets the status column.
	 *
	 * @param string $token  Our correlation handle.
	 * @param string $status New status value.
	 */
	public static function update_status( string $token, string $status ): bool {
		return self::update_columns( $token, array( 'status' => $status ) );
	}

	/**
	 * Stamps a record settled — status PAID and settled_at now, together, in one update. Written
	 * last inside settle_payment()'s locked section, only once the LatePoint state change it
	 * guards has actually succeeded (see IfthenpayLpSettlement).
	 *
	 * @param string $token Our correlation handle.
	 */
	public static function mark_settled( string $token ): bool {
		return self::update_columns(
			$token,
			array(
				'status'     => 'PAID',
				'settled_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Sets the request_id column after the fact — the realtime flow's own creation response
	 * (IfthenpayLpPayByLink::create()) carries no RequestId field at all, unlike the reference
	 * APIs, so there is nothing to store at insert time. The polling verification step
	 * (OsPaymentsIfthenpayCheckoutController::update_payment_repo_by_modal_url()) links the
	 * ifthenpay txid it already has onto the row right as it confirms that payment is real,
	 * treating it as this row's own settlement identifier — settle_payment()'s idempotency key —
	 * the same way a reference-API request_id is for deferred methods.
	 *
	 * @param string $token      Our correlation handle.
	 * @param string $request_id ifthenpay's own identifier for this payment.
	 */
	public static function set_request_id( string $token, string $request_id ): bool {
		return self::update_columns( $token, array( 'request_id' => $request_id ) );
	}

	/**
	 * Merges data into the method_data JSON column, preserving keys already there. For anything
	 * genuinely method-specific that arrives after the initial insert.
	 *
	 * @param string              $token Our correlation handle.
	 * @param array<string,mixed> $data  Keys to set or overwrite.
	 */
	public static function update_method_data( string $token, array $data ): bool {
		$record = self::find_by_token( $token );
		if ( ! $record ) {
			return false;
		}

		$existing = $record->method_data ? json_decode( $record->method_data, true ) : array();
		$merged   = array_merge( is_array( $existing ) ? $existing : array(), $data );

		return self::update_columns( $token, array( 'method_data' => wp_json_encode( $merged ) ) );
	}

	/**
	 * Shared column update by token, with cache invalidation. A row is cacheable by either of two
	 * unique columns (find_by_token(), find_by_request_id()) — both entries are cleared, not just
	 * the one this method was called with, or a caller that only ever looks a row up by the other
	 * column (settle_payment() always looks up by request_id) would keep seeing a stale, pre-update
	 * copy for the lifetime of that cache entry.
	 *
	 * @param string              $token Our correlation handle.
	 * @param array<string,mixed> $data  Column => value.
	 */
	private static function update_columns( string $token, array $data ): bool {
		global $wpdb;
		$before  = self::find_by_token( $token );
		$updated = (bool) $wpdb->update( self::table_name(), $data, array( 'token' => $token ) );

		if ( $updated ) {
			wp_cache_delete( "token_{$token}", self::CACHE_GROUP );
			// Not a truthy check: a real request_id of "0" (seen in production — research.md) is
			// falsy in PHP but must still have its cache entry cleared.
			if ( $before && null !== $before->request_id && '' !== $before->request_id ) {
				wp_cache_delete( "request_id_{$before->request_id}", self::CACHE_GROUP );
			}
		}

		return $updated;
	}

	/**
	 * One-time upgrade step: copies still-PENDING rows from the old, single-purpose
	 * {prefix}ifthenpay_payments table into this one, then renames the old table to `_legacy` so
	 * it stays readable for audits — never DROP on upgrade. No-ops on a fresh install (old table
	 * never existed) or if it already ran (the `_legacy` table is already there).
	 *
	 * The old table never recorded a per-record gateway_key, amount, or expiry, so migrated rows
	 * leave those NULL. That is safe here: migrated rows are always `kind = 'realtime'`, and the
	 * realtime flow does not use gateway_key/amount/expiry validation — those only matter to the
	 * deferred callback path, which never sees a realtime-kind record.
	 */
	private static function migrate_legacy_pending_and_retire(): void {
		global $wpdb;
		$old_table    = $wpdb->prefix . 'ifthenpay_payments';
		$legacy_table = $old_table . '_legacy';

		$legacy_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy_table ) ) === $legacy_table;
		if ( $legacy_exists ) {
			return;
		}

		$old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) === $old_table;
		if ( ! $old_exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $old_table has no user-controlled part (built from $wpdb->prefix); a one-time upgrade step, not a request-path query.
		$pending = $wpdb->get_results( "SELECT token, intent_id, paybylink_url FROM `{$old_table}` WHERE status = 'PENDING'" );

		foreach ( $pending as $row ) {
			self::insert(
				array(
					'token'         => $row->token,
					'intent_id'     => (int) $row->intent_id,
					'kind'          => 'realtime',
					'method'        => self::METHOD_PAYBYLINK,
					'status'        => 'PENDING',
					'paybylink_url' => $row->paybylink_url,
				)
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- table names can't be placeholders; both are built from $wpdb->prefix, no user-controlled part.
		$wpdb->query( "RENAME TABLE `{$old_table}` TO `{$legacy_table}`" );
	}

	/**
	 * Shared single-row lookup by an indexed column.
	 *
	 * @param string $column Always a fixed literal from a method above, never external input —
	 *                        interpolated as an identifier because %s placeholders are for values,
	 *                        not column names.
	 * @param string $value  The value to match.
	 */
	private static function find_one( string $column, string $value ): ?object {
		global $wpdb;
		$cache_key = "{$column}_{$value}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $column is a fixed internal literal, not user input; $table has no user-controlled part either.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$column}` = %s", $value ) );

		wp_cache_set( $cache_key, $row ? $row : false, self::CACHE_GROUP );

		return $row ? $row : null;
	}
}
