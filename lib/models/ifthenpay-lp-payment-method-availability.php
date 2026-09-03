<?php
/**
 * Which ifthenpay payment methods this add-on supports, and which of those are actually usable
 * right now for the merchant's current settings — every `latepoint_*payment_method*` /
 * `latepoint_*payment_time*` filter callback this add-on registers. Extracted out of the main
 * addon class so "is Multibanco offered at checkout" is answerable (and testable) without the
 * full LatePoint bootstrap.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless: every method is static, and the processor code is a class constant.
 */
class IfthenpayLpPaymentMethodAvailability {

	public const PROCESSOR_CODE = 'ifthenpay';

	/**
	 * This add-on's supported methods, unconditionally — whether or not they're currently usable.
	 * See usable_supported_payment_methods() for the filtered, "offer this at checkout" version.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_supported_payment_methods(): array {
		return array(
			// Pay By Link, paid during checkout — 'now' is correct here; see
			// IfthenpayLpPaymentTimes for why this value has to be right, not just present.
			'ifthenpay_gateway'    => array(
				'name'      => 'ifthenpay Gateway',
				'label'     => 'ifthenpay Gateway',
				'image_url' => IfthenpayPaymentsForLatepoint::images_url() . 'ifthenpay_simbolo.png',
				'code'      => 'ifthenpay_checkout',
				'time_type' => 'now',
			),
			'ifthenpay_multibanco' => array(
				'name'      => __( 'Multibanco', 'ifthenpay-payments-for-latepoint' ),
				'label'     => __( 'Multibanco reference', 'ifthenpay-payments-for-latepoint' ),
				// No dedicated Multibanco asset shipped yet; reuses the processor's own symbol.
				'image_url' => IfthenpayPaymentsForLatepoint::images_url() . 'ifthenpay_simbolo.png',
				'code'      => 'ifthenpay_multibanco',
				'time_type' => 'later',
			),
		);
	}

	/**
	 * The `latepoint_payment_processors` filter callback.
	 *
	 * @param array<string,mixed> $payment_processors The filter's own accumulator.
	 * @return array<string,mixed>
	 */
	public static function register_payment_processor( array $payment_processors ): array {
		$payment_processors[ self::PROCESSOR_CODE ] = array(
			'code'      => self::PROCESSOR_CODE,
			'name'      => __( 'ifthenpay', 'ifthenpay-payments-for-latepoint' ),
			'image_url' => IfthenpayPaymentsForLatepoint::images_url() . 'processor-logo.png',
		);
		return $payment_processors;
	}

	/**
	 * The `latepoint_all_payment_methods` filter callback.
	 *
	 * @param array<string,mixed> $payment_methods The filter's own accumulator.
	 * @return array<string,mixed>
	 */
	public static function register_payment_methods( array $payment_methods ): array {
		return array_merge( $payment_methods, self::get_supported_payment_methods() );
	}

	/**
	 * The `latepoint_enabled_payment_methods` filter callback.
	 *
	 * @param array<string,mixed> $enabled_payment_methods The filter's own accumulator.
	 * @return array<string,mixed>
	 */
	public static function register_enabled_payment_methods( array $enabled_payment_methods ): array {
		if ( OsPaymentsHelper::is_payment_processor_enabled( self::PROCESSOR_CODE ) ) {
			$enabled_payment_methods = array_merge( $enabled_payment_methods, self::usable_supported_payment_methods() );
		}
		return $enabled_payment_methods;
	}

	/**
	 * The `latepoint_get_all_payment_times` filter callback.
	 *
	 * @param array<string,mixed> $payment_times The filter's own accumulator.
	 * @return array<string,mixed>
	 */
	public static function add_all_payment_methods_to_payment_times( array $payment_times ): array {
		return IfthenpayLpPaymentTimes::add_methods( $payment_times, self::get_supported_payment_methods(), self::PROCESSOR_CODE );
	}

	/**
	 * The `latepoint_get_enabled_payment_times` filter callback.
	 *
	 * @param array<string,mixed> $payment_times The filter's own accumulator.
	 * @return array<string,mixed>
	 */
	public static function add_enabled_payment_methods_to_payment_times( array $payment_times ): array {
		if ( OsPaymentsHelper::is_payment_processor_enabled( self::PROCESSOR_CODE ) ) {
			$payment_times = IfthenpayLpPaymentTimes::add_methods( $payment_times, self::usable_supported_payment_methods(), self::PROCESSOR_CODE );
		}

		return $payment_times;
	}

	/**
	 * The processor toggle alone is not enough — the saved Gateway Key must still be a
	 * real, live one for the current Backoffice Key, checked fresh every time (no caching of
	 * its own; IfthenpayLpGatewayDataset::get() already caches per request).
	 */
	private static function is_gateway_key_usable(): bool {
		return IfthenpayLpEnabledMethodGate::is_usable(
			(string) OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key', '' ),
			(string) OsSettingsHelper::get_settings_value( 'ifthenpay_backoffice_key', '' )
		);
	}

	/**
	 * Multibanco needs more than a usable gateway key: the merchant must have checked "MB" in
	 * Payment Methods (IfthenpayLpAdminFormRenderer::get_saved_enabled_methods() — one setting
	 * covers both the "Pay Now" and "Pay Later" sections, the split there is display-only), and
	 * the selected gateway must actually carry an MB account. Otherwise the method would be
	 * offered at checkout only to fail at reference-creation time with a confusing error.
	 *
	 * A dataset fetch failure fails open, same reasoning as is_gateway_key_usable(): an outage
	 * must not take checkout down for an otherwise valid setup. If it recurs at the moment of
	 * checkout, IfthenpayLpPaymentProcessor::process_deferred_payment_by_intent() fails that one
	 * attempt gracefully instead.
	 */
	private static function is_multibanco_usable(): bool {
		if ( ! in_array( 'MB', IfthenpayLpAdminFormRenderer::get_saved_enabled_methods(), true ) ) {
			return false;
		}

		$backoffice_key = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_backoffice_key', '' );
		$gateway_key    = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key', '' );
		if ( '' === $backoffice_key || '' === $gateway_key ) {
			return false;
		}

		$dataset = IfthenpayLpGatewayDataset::get( $backoffice_key );
		if ( null === $dataset ) {
			return true;
		}

		return isset( $dataset['accounts'][ $gateway_key ]['MB'] );
	}

	/**
	 * This add-on's supported methods, filtered down to the ones actually usable right now —
	 * ifthenpay_gateway needs only a usable gateway key; ifthenpay_multibanco additionally needs
	 * is_multibanco_usable(). Shared by both the payment-times filter and the enabled-methods
	 * filter so the two can never disagree about which methods are currently offered.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function usable_supported_payment_methods(): array {
		if ( ! self::is_gateway_key_usable() ) {
			return array();
		}

		$methods = self::get_supported_payment_methods();
		if ( isset( $methods['ifthenpay_multibanco'] ) && ! self::is_multibanco_usable() ) {
			unset( $methods['ifthenpay_multibanco'] );
		}

		return $methods;
	}
}
