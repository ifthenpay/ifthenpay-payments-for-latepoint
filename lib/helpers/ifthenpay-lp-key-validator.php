<?php
/**
 * Backoffice Key validation — local format check plus the remote call that actually
 * distinguishes a client from a non-client.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two independent checks, deliberately not combined into one call: a merchant should never wait
 * on the network for an obviously malformed key, and the two failure modes mean different things
 * to a caller deciding whether to save a settings field.
 */
class IfthenpayLpKeyValidator {

	private const FORMAT_PATTERN = '/^\d{4}(?:-\d{4}){3}$/';

	/**
	 * Entities/subentities endpoint — VERIFIED live: an unrecognized key answers 403 plain text
	 * ("Invalid Credentials"); a recognized key answers 200 with a JSON array, empty or not. This
	 * is deliberately not `/gateway/get`, which cannot tell "no gateway keys yet" from "unknown
	 * key".
	 */
	private const VALIDATION_URL = 'https://www.ifthenpay.com/IfmbWS/ifmbws.asmx/getEntidadeSubentidadeJsonV2';

	/**
	 * Local, no network call: `1234-5678-9012-3456`.
	 *
	 * @param string $key Backoffice Key to check.
	 */
	public static function has_valid_format( string $key ): bool {
		return 1 === preg_match( self::FORMAT_PATTERN, $key );
	}

	/**
	 * Confirms ifthenpay recognizes the key. Returning at all — regardless of how many entities
	 * come back, including none — means the key is valid; only the exception means otherwise.
	 *
	 * @param string $key Backoffice Key, already format-checked by the caller.
	 * @throws IfthenpayLpCredentialException The key is not recognized (403). Terminal — block
	 *                                         the save.
	 * @throws IfthenpayLpTransportException  Network failure, 5xx, or unparseable body. Says
	 *                                         nothing about the key — the caller fails open.
	 */
	public static function verify_remote( string $key ): void {
		IfthenpayLpApiClient::get(
			self::VALIDATION_URL . '?chavebackoffice=' . rawurlencode( $key ),
			IfthenpayLpApiClient::TIMEOUT_VALIDATION
		);
	}
}
