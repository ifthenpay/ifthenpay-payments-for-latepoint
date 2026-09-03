<?php
/**
 * Every exception this plugin's own ifthenpay API layer throws — one small, tightly-related
 * hierarchy (a base plus three subclasses, always used together), kept in one file rather than
 * four near-empty ones.
 *
 * @package ifthenpay-payments-for-latepoint
 */

// phpcs:disable Generic.Commenting.DocComment.ShortNotCapital -- "ifthenpay" is a proper noun, always lowercase.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base for every exception this client throws — catch this to handle any ifthenpay call failure
 * generically, or catch a specific subclass below to branch on terminal-vs-transport.
 */
class IfthenpayLpApiException extends Exception {}

/**
 * ifthenpay rejected the credentials (HTTP 401/403). Terminal — do not retry, and this is the
 * one failure mode that says something concrete about the key itself.
 */
class IfthenpayLpCredentialException extends IfthenpayLpApiException {}

/**
 * Everything else: no route to ifthenpay, a timeout, a 5xx, or a body that could not be parsed
 * when JSON was expected. Says nothing about the credentials — callers fail open on the settings
 * page and fail closed at checkout, never the reverse.
 */
class IfthenpayLpTransportException extends IfthenpayLpApiException {}

/**
 * Thrown by IfthenpayLpSettlementLock when a lock could not be acquired at all — either another
 * process is genuinely holding it right now, or every locking mechanism available failed.
 * Callers treat this as "try again later" (a retryable failure), never as a rejection — the
 * request itself may be entirely legitimate. Not part of the IfthenpayLpApiException hierarchy —
 * it has nothing to do with an ifthenpay API call, it's a local locking failure.
 */
class IfthenpayLpLockUnavailableException extends Exception {}
