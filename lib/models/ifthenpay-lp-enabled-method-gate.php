<?php
/**
 * A saved processor toggle is not enough on its own to offer the ifthenpay method at checkout —
 * the saved Gateway Key must still be a real, live one. Kept separate from the plugin file's own
 * filter callbacks (main plugin file) so this decision is unit-testable without a real LatePoint
 * boot, the same way IfthenpayLpBackofficeKeyValidation's own hook glue works.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * See is_usable() for the fail-open behaviour this exists to encode.
 */
class IfthenpayLpEnabledMethodGate {

	/**
	 * Decides whether the saved Gateway Key is currently usable — present, and confirmed to still
	 * exist for this Backoffice Key. A site with no Backoffice Key, or a since-revoked Gateway
	 * Key, must not offer the method regardless of the processor's own enabled/disabled toggle.
	 *
	 * A fetch failure (IfthenpayLpGatewayDataset::get() returning null) fails open, matching
	 * IfthenpayLpBackofficeKeyValidation's own save-time check: a transient ifthenpay outage must
	 * not take checkout down for an otherwise valid setup, only a confirmed absence should.
	 *
	 * @param string $gateway_key     Saved `ifthenpay_gateway_key` setting value.
	 * @param string $backoffice_key  Saved `ifthenpay_backoffice_key` setting value.
	 */
	public static function is_usable( string $gateway_key, string $backoffice_key ): bool {
		if ( '' === $gateway_key || '' === $backoffice_key ) {
			return false;
		}

		$dataset = IfthenpayLpGatewayDataset::get( $backoffice_key );
		if ( null === $dataset ) {
			return true;
		}

		return isset( $dataset['gatewaykeys'][ $gateway_key ] );
	}
}
