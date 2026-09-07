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
	 * The fully qualified table name — public so a caller needing to join against this table
	 * directly (IfthenpayLpLapsedAppointmentDigest) doesn't have to re-derive `$wpdb->prefix .
	 * 'ifthenpay_transactions'` on its own.
	 */
	public static function table_name(): string {
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
	private static function create_table(): void {
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
	 * The customer-facing surfaces' own lookup: given the order/transaction intent id a checkout
	 * was created from, finds the payment record it produced — one row per intent by construction
	 * (each checkout attempt creates at most one), not enforced as a database constraint.
	 *
	 * @param int $intent_id An order or transaction intent id.
	 */
	public static function find_by_intent_id( int $intent_id ): ?object {
		return self::find_one( 'intent_id', (string) $intent_id );
	}

	/**
	 * The expiry sweep's own access path — deliberately restricted to `kind = 'deferred'`.
	 * A still-PENDING realtime row means the order was never created (send_ifthenpay_options()
	 * only ever inserts before checkout confirms), so there is nothing for the sweep to cancel
	 * there; only deferred methods (Multibanco) hold a slot open across a real waiting period.
	 * Uses the table's own `status_expires` index — no wp_cache layer, since this always runs
	 * against a fresh, possibly large set on an hourly cron tick, not a per-request lookup.
	 *
	 * @return object[]
	 */
	public static function find_expired_pending(): array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table has no user-controlled part (built from $wpdb->prefix); values are placeholders.
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table has no user-controlled part (built from $wpdb->prefix); the %s placeholders cover every real value.
				"SELECT * FROM `{$table}` WHERE status = %s AND kind = %s AND expires_at IS NOT NULL AND expires_at < %s",
				'PENDING',
				'deferred',
				current_time( 'mysql', true )
			)
		);
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

		$merged = array_merge( self::decode_method_data( $record ), $data );

		return self::update_columns( $token, array( 'method_data' => wp_json_encode( $merged ) ), $record );
	}

	/**
	 * Decodes a record's method_data column, null-safe — the column is empty on every row until
	 * something calls update_method_data(), and json_decode(null) is itself deprecated as of PHP
	 * 8.1.
	 *
	 * @param object $record As returned by find_by_token()/find_by_request_id().
	 * @return array<string,mixed>
	 */
	public static function decode_method_data( object $record ): array {
		if ( empty( $record->method_data ) ) {
			return array();
		}

		$decoded = json_decode( $record->method_data, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Records an IfthenpayLpTransactionStatus::check() confirmation into method_data — always,
	 * regardless of whether $confirmation->order_id ends up matching this row's own token, so a
	 * mismatch is explainable later (verified_order_id here vs. token) instead of looking identical
	 * to a genuine "ifthenpay never confirmed it" case. When it does match, `method` is corrected to
	 * the confirmed value in the same write — and, when $settle is true, so is settling the row
	 * (status PAID + settled_at), the same shape as mark_settled(). All of this is one
	 * find_by_token() and one update_columns() call, not up to three: the realtime polling path
	 * and manual re-check both used to chain record+correct-method(+settle) as separate calls, each
	 * paying for its own re-fetch since every write here clears the token cache entry the next
	 * one's own internal fetch would otherwise hit.
	 *
	 * @param string $token          Our correlation handle.
	 * @param string $transaction_id The identifier checked with ifthenpay — a txid for a realtime
	 *                                payment, this row's own request_id for a deferred one (see
	 *                                IfthenpayLpTransactionStatus's own docblock on why the two
	 *                                sometimes coincide and sometimes don't).
	 * @param object $confirmation   As returned by IfthenpayLpTransactionStatus::check().
	 * @param bool   $settle         Whether to also mark the row settled when order_id matches —
	 *                                the realtime polling path settles here directly; manual
	 *                                re-check settles separately, through settle_payment().
	 * @phpstan-param object{payment_method:string,amount:string,order_id:string} $confirmation
	 */
	public static function record_verification( string $token, string $transaction_id, object $confirmation, bool $settle = false ): bool {
		$record = self::find_by_token( $token );
		if ( ! $record ) {
			return false;
		}

		$method_data = array_merge(
			self::decode_method_data( $record ),
			array(
				'transaction_id'          => $transaction_id,
				'verified_payment_method' => $confirmation->payment_method,
				'verified_amount'         => $confirmation->amount,
				'verified_order_id'       => $confirmation->order_id,
			)
		);

		$columns = array( 'method_data' => wp_json_encode( $method_data ) );
		if ( $confirmation->order_id === $token ) {
			$columns['method'] = $confirmation->payment_method;
			if ( $settle ) {
				$columns['status']     = 'PAID';
				$columns['settled_at'] = current_time( 'mysql', true );
			}
		}

		return self::update_columns( $token, $columns, $record );
	}

	/**
	 * Shared column update by token, with cache invalidation. A row is cacheable by either of two
	 * unique columns (find_by_token(), find_by_request_id()) — both entries are cleared, not just
	 * the one this method was called with, or a caller that only ever looks a row up by the other
	 * column (settle_payment() always looks up by request_id) would keep seeing a stale, pre-update
	 * copy for the lifetime of that cache entry.
	 *
	 * @param string              $token  Our correlation handle.
	 * @param array<string,mixed> $data   Column => value.
	 * @param object|null         $before The record as it already stood, if a caller happens to
	 *                                    have just fetched it — skips a redundant find_by_token()
	 *                                    that would otherwise cache-miss (every call here clears
	 *                                    the token cache entry, so a caller chaining several writes
	 *                                    to the same token would pay for a fresh DB read each time).
	 */
	private static function update_columns( string $token, array $data, ?object $before = null ): bool {
		global $wpdb;
		$before  = $before ?? self::find_by_token( $token );
		$updated = (bool) $wpdb->update( self::table_name(), $data, array( 'token' => $token ) );

		if ( $updated ) {
			wp_cache_delete( "token_{$token}", self::CACHE_GROUP );
			// Not a truthy check: a real request_id of "0" (seen in production) is
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
	 * Shared single-row lookup by an indexed column. The row itself is $wpdb->get_row()'s own raw
	 * stdClass — deliberately not a typed model, since every column is nullable/optional depending
	 * on kind and every caller already treats it as a plain data bag. Every property access on a
	 * row returned from here (or from find_by_token()/find_by_request_id()/find_by_intent_id(),
	 * which all funnel through this) is therefore untyped as far as phpstan is concerned; test code
	 * marks each one with a bare property.notFound line suppression rather than repeating this
	 * explanation at every one of those call sites.
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
