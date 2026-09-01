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
 * See contracts/data-model.md.
 */
class IfthenpayLpTransactionRepository {

	private const SCHEMA_VERSION        = '1.0.0';
	private const SCHEMA_VERSION_OPTION = 'ifthenpay_lp_transactions_schema_version';
	private const CACHE_GROUP           = 'ifthenpay_lp_transactions';

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
