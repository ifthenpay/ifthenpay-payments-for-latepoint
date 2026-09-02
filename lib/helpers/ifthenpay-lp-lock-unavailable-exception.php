<?php
/**
 * Thrown by IfthenpayLpSettlementLock when a lock could not be acquired at all — either another
 * process is genuinely holding it right now, or every locking mechanism available failed.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Callers treat this as "try again later" (a retryable failure), never as a rejection — the
 * request itself may be entirely legitimate.
 */
class IfthenpayLpLockUnavailableException extends Exception {}
