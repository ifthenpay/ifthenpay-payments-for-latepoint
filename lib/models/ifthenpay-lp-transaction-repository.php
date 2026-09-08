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

	private const SCHEMA_VERSION        = '1.3.0';
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
			intent_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(20) NOT NULL,
			method VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
			amount DECIMAL(10,2) DEFAULT NULL,
			gateway_key VARCHAR(255) DEFAULT NULL,
			request_id VARCHAR(255) DEFAULT NULL,
			entity VARCHAR(20) DEFAULT NULL,
			reference VARCHAR(255) DEFAULT NULL,
			expires_at DATETIME DEFAULT NULL,
			pin_code VARCHAR(255) DEFAULT NULL,
			paybylink_url VARCHAR(255) DEFAULT NULL,
			checkout_snapshot LONGTEXT DEFAULT NULL,
			resolved_at DATETIME DEFAULT NULL,
			settled_at DATETIME DEFAULT NULL,
			settled_by VARCHAR(20) DEFAULT NULL,
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
	 * Every still-outstanding deferred payment, regardless of expiry — the ifthenpay Tools page's
	 * own listing, so a merchant/support agent can see and manually re-check one without needing
	 * its token ahead of time. Soonest-to-expire first: the ones most worth checking before the
	 * expiry sweep would cancel them.
	 *
	 * @return object[]
	 */
	public static function find_pending_deferred(): array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table has no user-controlled part (built from $wpdb->prefix); values are placeholders.
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table has no user-controlled part (built from $wpdb->prefix); the %s placeholders cover every real value.
				"SELECT * FROM `{$table}` WHERE status = %s AND kind = %s ORDER BY expires_at IS NULL, expires_at ASC",
				'PENDING',
				'deferred'
			)
		);
	}

	/**
	 * A realtime row still counts as "in flight" for this long after settling before it's eligible
	 * to be flagged unclaimed — long enough that a customer's own browser has certainly either
	 * finished submitting the booking form or genuinely died trying, not so long that a merchant
	 * loses real time noticing an actual double-charge.
	 */
	private const UNCLAIMED_GRACE_PERIOD_SECONDS = 15 * MINUTE_IN_SECONDS;

	/**
	 * Realtime payments that settled PAID but were never claimed by a real LatePoint transaction —
	 * the customer's own browser died before convert_to_order() ran (or, in the worst case, a retry
	 * on the same order_intent went on to pay and convert separately, leaving this one a genuine,
	 * unrecorded-anywhere-else second charge — see IfthenpayLpTransactionRepository's own callers in
	 * payments-ifthenpay-checkout-controller.php for why intent_key reuse used to make this
	 * possible). `settled_at` past the grace period excludes a checkout still genuinely mid-submit.
	 *
	 * Joined by token against LatePoint core's own transactions table — verified reliable:
	 * OsPaymentsHelper::process_payment_for_order_intent()/process_payment_for_transaction_intent()
	 * both set `$transaction->token` unconditionally from this add-on's own `charge_id` return value
	 * (payments_helper.php:359-360, :405-406), which is exactly the token this add-on minted for
	 * this row (IfthenpayLpPaymentProcessor::process_payment_by_intent()) — a match there proves the
	 * row genuinely was claimed by a real booking/order, not merely that some order_intent nearby
	 * eventually converted.
	 *
	 * Accepted cost, not an oversight: LatePoint core's own transactions table declares `token` as
	 * plain `TEXT` with no index at all, so the NOT EXISTS below is an unindexed scan of that table —
	 * one holding every payment this site has ever processed, across every processor, not only
	 * ifthenpay's own. Not fixable without a core schema change. Bounded in practice by this query's
	 * own outer row count staying small (the grace period and `resolved_at IS NULL` filters already
	 * limit that), same reasoning find_pending_deferred()'s own missing index already accepts.
	 *
	 * @return object[]
	 */
	public static function find_unclaimed_realtime(): array {
		global $wpdb;
		$table    = self::table_name();
		$lp_table = LATEPOINT_TABLE_TRANSACTIONS;
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - self::UNCLAIMED_GRACE_PERIOD_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- both table names have no user-controlled part ($table from $wpdb->prefix; LATEPOINT_TABLE_TRANSACTIONS is LatePoint core's own constant); the %s placeholders cover every real value.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.* FROM `{$table}` t
				WHERE t.kind = %s AND t.status = %s AND t.resolved_at IS NULL
				AND t.settled_at IS NOT NULL AND t.settled_at < %s
				AND NOT EXISTS ( SELECT 1 FROM `{$lp_table}` lt WHERE lt.token = t.token )
				ORDER BY t.settled_at ASC",
				'realtime',
				'PAID',
				$cutoff
			)
		);
		// phpcs:enable
	}

	/**
	 * Stops a row appearing in find_unclaimed_realtime() without touching status/settled_at or
	 * anything about the payment itself — the underlying paid row and its history stay exactly as
	 * they are, this only records that a merchant has manually resolved it with their customer.
	 * Re-validates the row is actually the kind of thing find_unclaimed_realtime() would surface
	 * before touching it — the same defensive pattern every other mutation this table's own callers
	 * make (cancel_now()'s own cancel_locked(), IfthenpayLpManualRecheck::run()) already follows;
	 * without it, a stale or hand-crafted token could stamp resolved_at on a row this listing was
	 * never actually showing.
	 *
	 * @param string $token Our correlation handle.
	 */
	public static function mark_resolved( string $token ): bool {
		$record = self::find_by_token( $token );
		if ( ! $record || 'realtime' !== $record->kind || 'PAID' !== $record->status || null !== $record->resolved_at ) { // @phpstan-ignore-line property.notFound
			return false;
		}

		return self::update_columns( $token, array( 'resolved_at' => current_time( 'mysql', true ) ), $record );
	}

	/**
	 * The customer dashboard's own batch-prefetch: warms find_by_intent_id()'s own wp_cache entry
	 * for every one of $customer_id's own order intents in 2 queries total, regardless of how many
	 * bookings that customer has. Without this, for_order()/for_booking() (IfthenpayLpReferenceDisplay)
	 * each run their own find_by_intent_id() call once per booking tile LatePoint's dashboard
	 * renders (`latepoint_customer_dashboard_after_booking_info_tile`, once per booking in a loop) —
	 * this is called once, before that loop starts
	 * (`latepoint_customer_dashboard_before_appointments`), so every one of those later calls
	 * transparently hits cache instead. Deliberately does not also prefetch/cache the
	 * OsOrderItemModel/OsOrderIntentModel::where() lookups those same callers make on the way here —
	 * only this add-on's own table is this repository's concern; reaching into LatePoint's own model
	 * layer to cache its lookups too would mean duplicating LatePoint's own ORM behavior. So this
	 * takes the add-on's own per-dashboard query cost from O(3N) to O(2N + 2), not O(1)/O(N) — 2
	 * batch queries here, plus LatePoint's own unavoidable 2 lookups per booking.
	 *
	 * `order_intents` has its own `customer_id` column, separate from `order_id` — that's what makes
	 * a one-query prefetch possible without needing the specific booking list LatePoint's own
	 * `latepoint_customer_dashboard_before_appointments` hook doesn't pass (only `$customer`).
	 *
	 * @param int $customer_id The dashboard's own logged-in customer.
	 */
	public static function prime_cache_for_customer( int $customer_id ): void {
		global $wpdb;

		// Own perf-critical priming path, same justification as find_expired_pending();
		// LATEPOINT_TABLE_ORDER_INTENTS has no user-controlled part, the %d placeholder covers the
		// only real value.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$intent_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM `' . LATEPOINT_TABLE_ORDER_INTENTS . '` WHERE customer_id = %d AND order_id IS NOT NULL',
				$customer_id
			)
		);
		// phpcs:enable
		if ( ! $intent_ids ) {
			return;
		}

		$table        = self::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $intent_ids ), '%d' ) );
		// $table has no user-controlled part; $placeholders is a fixed count of %d literals, one per
		// entry in $intent_ids, which $wpdb->prepare() itself fills in and %d-casts (a single array
		// argument after the query is WordPress core's own documented way to fill N placeholders at
		// once, not an unprepared value).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE intent_id IN ({$placeholders})", $intent_ids )
		);
		// phpcs:enable

		$by_intent = array();
		foreach ( $rows as $row ) {
			$by_intent[ (int) $row->intent_id ] = $row;
		}

		foreach ( $intent_ids as $id ) {
			// The exact key shape find_one() computes for 'intent_id' — must match exactly, and
			// negative-cache a miss (false, not skipped) the same way find_one() does, so a later
			// find_by_intent_id() for a customer-owned intent with no transaction row never falls
			// through to a real query either.
			wp_cache_set( 'intent_id_' . (string) $id, $by_intent[ (int) $id ] ?? false, self::CACHE_GROUP );
		}
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
	 * Stamps a record settled — status PAID, settled_at, and settled_by (real, queryable columns,
	 * not method_data) all together, in one update. Written last inside settle_payment()'s locked
	 * section, only once the LatePoint state change it guards has actually succeeded (see
	 * IfthenpayLpSettlement).
	 *
	 * @param string $token  Our correlation handle.
	 * @param string $source 'callback' | 'polling' | 'manual' — same vocabulary as
	 *                        IfthenpayLpSettlement::settle_payment()'s own $source.
	 */
	public static function mark_settled( string $token, string $source ): bool {
		return self::update_columns(
			$token,
			array(
				'status'     => 'PAID',
				'settled_at' => current_time( 'mysql', true ),
				'settled_by' => $source,
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
		return self::decode_json_column( $record->method_data ?? null );
	}

	/**
	 * Decodes a record's own checkout_snapshot column, null-safe — the identity/booking info
	 * OsPaymentsIfthenpayCheckoutController::build_unclaimed_snapshot() captures at checkout time.
	 * Deliberately its own column, not folded into method_data: the two hold entirely unrelated
	 * kinds of information — settlement/verification metadata (written by record_verification(),
	 * read by IfthenpayLpPaymentProcessor::backfill_realtime_transaction_notes()) versus a
	 * checkout-time identity/booking capture (written once at insert, read by the ifthenpay Tools
	 * page's own Unclaimed Realtime Payments listing) — that happen to both be per-row JSON. Sharing
	 * one blob for both would only make an already-dense payload harder to reason about, for no real
	 * benefit: nothing ever needs both at once.
	 *
	 * @param object $record As returned by find_by_token()/find_by_request_id().
	 * @return array<string,mixed>
	 */
	public static function decode_checkout_snapshot( object $record ): array {
		return self::decode_json_column( $record->checkout_snapshot ?? null );
	}

	/**
	 * Shared null-safe JSON-column decode — json_decode(null) is itself deprecated as of PHP 8.1,
	 * and a column can legitimately be empty (nothing has ever written to it) or hold something
	 * that decodes to anything other than an array.
	 *
	 * @param string|null $raw The column's own raw value.
	 * @return array<string,mixed>
	 */
	private static function decode_json_column( ?string $raw ): array {
		if ( empty( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Records an IfthenpayLpTransactionStatus::check() confirmation into method_data — always,
	 * regardless of whether $confirmation->order_id ends up matching this row's own token, so a
	 * mismatch is explainable later (verified_order_id here vs. token) instead of looking identical
	 * to a genuine "ifthenpay never confirmed it" case. `ifthenpay_response` is the endpoint's own
	 * raw, decoded JSON body (`$confirmation->raw`, when the caller set it — check() itself always
	 * does; the callback route's own locally-built stand-in $confirmation does not, since a webhook
	 * is received, not fetched from an endpoint) — kept verbatim alongside the narrower
	 * `verified_*` fields already derived from it, for support/dispute lookups that need to see
	 * exactly what ifthenpay actually returned, not just what this add-on made of it. When
	 * order_id matches, `method` is corrected to the confirmed value in the same write — and, when
	 * $settle is true, so is settling the row (status PAID + settled_at + settled_by), the same
	 * shape as mark_settled(). All of this is one find_by_token() and one update_columns() call,
	 * not up to three: the realtime polling path and manual re-check both used to chain
	 * record+correct-method(+settle) as separate calls, each paying for its own re-fetch since
	 * every write here clears the token cache entry the next one's own internal fetch would
	 * otherwise hit.
	 *
	 * @param string $token          Our correlation handle.
	 * @param string $identifier     The identifier this confirmation was checked against. A real,
	 *                                ifthenpay-verified txid for the realtime polling and manual
	 *                                re-check paths — both call IfthenpayLpTransactionStatus::check()
	 *                                first. NEVER a real txid for the realtime webhook path: ifthenpay
	 *                                confirmed a Pay By Link webhook's own request_id is not accepted
	 *                                by the transaction-status endpoint, and no separate "check by
	 *                                request_id" endpoint exists either — it is a genuinely different,
	 *                                unrelated identifier. See $identifier_key.
	 * @param object $confirmation   As returned by IfthenpayLpTransactionStatus::check() for the
	 *                                verified paths; a locally-built, never-independently-confirmed
	 *                                stand-in for the webhook path (see settle_realtime_locked()).
	 * @param string $source         'callback' | 'polling' | 'manual' — same vocabulary as
	 *                                IfthenpayLpSettlement::settle_payment()'s own $source. Only
	 *                                written to the row (as settled_by) when $settle is true; the
	 *                                manual re-check path passes 'manual' here for self-documentation
	 *                                even though it settles separately, through settle_payment(),
	 *                                which stamps settled_by itself via mark_settled().
	 * @param bool   $settle         Whether to also mark the row settled when order_id matches —
	 *                                the realtime polling path settles here directly; manual
	 *                                re-check settles separately, through settle_payment().
	 * @param string $identifier_key Which method_data key $identifier is stored under —
	 *                                'transaction_id' (default) only when it genuinely is one, or a
	 *                                caller-supplied distinct key otherwise (see the webhook path's
	 *                                own 'callback_request_id'), so a later reader — see
	 *                                IfthenpayLpPaymentProcessor::backfill_realtime_transaction_notes() —
	 *                                never has to guess which kind of identifier it found.
	 * @phpstan-param object{payment_method:string,amount:string,order_id:string,raw?:array<string,mixed>} $confirmation
	 */
	public static function record_verification( string $token, string $identifier, object $confirmation, string $source, bool $settle = false, string $identifier_key = 'transaction_id' ): bool {
		$record = self::find_by_token( $token );
		if ( ! $record ) {
			return false;
		}

		$method_data = array_merge(
			self::decode_method_data( $record ),
			array(
				$identifier_key           => $identifier,
				'verified_payment_method' => $confirmation->payment_method,
				'verified_amount'         => $confirmation->amount,
				'verified_order_id'       => $confirmation->order_id,
			)
		);
		if ( isset( $confirmation->raw ) ) {
			$method_data['ifthenpay_response'] = $confirmation->raw;
		}

		$columns = array( 'method_data' => wp_json_encode( $method_data ) );
		if ( $confirmation->order_id === $token ) {
			$columns['method'] = $confirmation->payment_method;
			if ( $settle ) {
				$columns['status']     = 'PAID';
				$columns['settled_at'] = current_time( 'mysql', true );
				$columns['settled_by'] = $source;
			}
		}

		return self::update_columns( $token, $columns, $record );
	}

	/**
	 * Shared column update by token, with cache invalidation. A row is cacheable by any of three
	 * unique-ish columns (find_by_token(), find_by_request_id(), find_by_intent_id()) — all three
	 * entries are cleared, not just the one this method was called with, or a caller that only ever
	 * looks a row up by one of the other columns (settle_payment() always looks up by request_id;
	 * IfthenpayLpReferenceDisplay::for_order() always looks up by intent_id) would keep seeing a
	 * stale, pre-update copy for the lifetime of that cache entry — exactly what happened before
	 * IfthenpayLpSettlement started firing `latepoint_transaction_created` only after this method's
	 * own mark_settled() call: a listener reading via find_by_intent_id() in the same request, before
	 * intent_id was ever accounted for here, could still have observed a stale PENDING row even with
	 * that ordering fixed, the moment some other caller read it via intent_id earlier in the same
	 * request. This closes that gap at its source rather than relying on call-order alone.
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
			if ( $before && null !== $before->intent_id && '' !== $before->intent_id ) {
				wp_cache_delete( 'intent_id_' . $before->intent_id, self::CACHE_GROUP );
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
