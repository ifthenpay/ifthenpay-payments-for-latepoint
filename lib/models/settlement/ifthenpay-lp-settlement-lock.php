<?php
/**
 * Cross-request mutex for settle_payment() and, later, the expiry job — both must serialise on
 * the same key so a payment and an expiry sweep landing at the same moment cannot race.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deliberately built on MySQL's own GET_LOCK()/RELEASE_LOCK() — a session-scoped advisory mutex
 * that is independent of any SQL transaction. The contract's own suggestion (`SELECT ... FOR
 * UPDATE` inside an explicit transaction) was tried first and rejected: WP's own test harness
 * (WP_UnitTestCase) already wraps every test in a real `START TRANSACTION`, and MySQL has no
 * nested transactions — a second `START TRANSACTION` issued from inside this class would silently
 * COMMIT that outer one, breaking test isolation. GET_LOCK() has no such interaction and provides
 * the same guarantee (acquired before any read, held until the state change is durable), plus a
 * useful property FOR UPDATE doesn't have: the lock releases automatically if the connection ever
 * drops, so a crashed process can never leave settlement jammed for a key.
 */
class IfthenpayLpSettlementLock {

	/**
	 * How long GET_LOCK() waits for a lock already held by another process before giving up.
	 * Comfortably inside ifthenpay's own callback tolerance.
	 */
	private const LOCK_TIMEOUT_SECONDS = 10;

	/**
	 * Fallback-path only: how long an option-based lock is honoured before it's treated as
	 * abandoned by a crashed process, rather than jamming that key forever.
	 */
	private const OPTION_LOCK_TTL = 30;

	/**
	 * Runs $work() while holding an exclusive lock for $lock_key, releasing it afterwards
	 * regardless of how $work() returns or throws.
	 *
	 * @param string   $lock_key Any stable identifier for what's being serialised — settle_payment()
	 *                           uses the ifthenpay request id.
	 * @param callable $work     Runs while the lock is held; its return value is passed through.
	 * @return mixed Whatever $work() returns.
	 * @throws IfthenpayLpLockUnavailableException If the lock could not be acquired at all —
	 *                                              callers should treat this as retryable, not as a
	 *                                              rejection of the underlying request.
	 */
	public static function with_lock( string $lock_key, callable $work ) {
		global $wpdb;
		$name = self::mysql_lock_name( $lock_key );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- GET_LOCK() is a session primitive, not a cacheable data query.
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_TIMEOUT_SECONDS ) );

		// NULL means GET_LOCK() itself errored (exotic host/permissions) — degrade rather than
		// settle without any mutual exclusion at all.
		if ( null === $acquired ) {
			return self::with_option_lock( $lock_key, $work );
		}

		if ( '1' !== $acquired ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $lock_key is an internal identifier (e.g. an ifthenpay request id), never rendered to a browser.
			throw new IfthenpayLpLockUnavailableException( "Timed out waiting for lock: {$lock_key}" );
		}

		try {
			return $work();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * GET_LOCK() names are capped at 64 characters on MySQL — hashed so an unexpectedly long
	 * $lock_key can never silently truncate into, and collide with, a different one.
	 *
	 * @param string $lock_key As passed to with_lock().
	 */
	private static function mysql_lock_name( string $lock_key ): string {
		return 'ifthenpay_lp_' . substr( md5( $lock_key ), 0, 40 );
	}

	/**
	 * Last-resort fallback for the rare host where GET_LOCK() is unusable. An atomic add_option()
	 * call is the mutex: it only succeeds when the option doesn't already exist. A lock older than
	 * OPTION_LOCK_TTL is assumed abandoned (its owning process crashed without releasing it) and is
	 * cleared before retrying once — accepted as a narrow, rare-host-only race, in exchange for
	 * never jamming a key forever.
	 *
	 * @param string   $lock_key As passed to with_lock().
	 * @param callable $work     As passed to with_lock().
	 * @throws IfthenpayLpLockUnavailableException If the option-based lock is currently held and
	 *                                              not yet stale, or add_option() itself fails.
	 */
	private static function with_option_lock( string $lock_key, callable $work ) {
		$option = 'ifthenpay_lp_lock_' . md5( $lock_key );
		$now    = time();

		$existing = get_option( $option );
		if ( false !== $existing && ( $now - (int) $existing ) < self::OPTION_LOCK_TTL ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- see note above.
			throw new IfthenpayLpLockUnavailableException( "Timed out waiting for lock: {$lock_key}" );
		}
		if ( false !== $existing ) {
			delete_option( $option );
		}

		if ( ! add_option( $option, $now, '', 'no' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- see note above.
			throw new IfthenpayLpLockUnavailableException( "Timed out waiting for lock: {$lock_key}" );
		}

		try {
			return $work();
		} finally {
			delete_option( $option );
		}
	}
}
