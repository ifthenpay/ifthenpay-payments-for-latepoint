<?php
/**
 * The Backoffice Key save-time validation decision — kept separate from the
 * `latepoint_model_validate` hook glue (main plugin file) so it is unit-testable without a real
 * OsSettingsModel/WordPress boot, the same way IfthenpayLpKeyValidator's own tests work.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The three-step validation order, decided once here so the hook callback is pure glue: empty
 * is allowed (clears the field); format is checked locally, no network call, before anything
 * else; only a confirmed rejection (401/403) blocks the save — a transport failure must not,
 * since an ifthenpay outage must not lock a merchant out of their own settings page.
 */
class IfthenpayLpBackofficeKeyValidation {

	/**
	 * Decides whether a Backoffice Key value should block the save it came from.
	 *
	 * @param string $key Decrypted Backoffice Key value, or empty to mean "field cleared".
	 * @return string|null An error message to reject the save with, or null to allow it.
	 */
	public static function check( string $key ): ?string {
		if ( '' === $key ) {
			return null;
		}

		if ( ! IfthenpayLpKeyValidator::has_valid_format( $key ) ) {
			return __( 'Invalid Backoffice Key format. Expected 1234-5678-9012-3456.', 'ifthenpay-payments-for-latepoint' );
		}

		try {
			IfthenpayLpKeyValidator::verify_remote( $key );
		} catch ( IfthenpayLpCredentialException $e ) {
			return __( 'ifthenpay did not recognize this Backoffice Key.', 'ifthenpay-payments-for-latepoint' );
		} catch ( IfthenpayLpTransportException $e ) {
			// Fail open: says nothing about the key, so it does not block the save.
			return null;
		}

		return null;
	}
}
