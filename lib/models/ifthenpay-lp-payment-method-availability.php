<?php
/**
 * Which ifthenpay payment methods this add-on supports, and which of those are actually usable
 * right now for the merchant's current settings — every `latepoint_*payment_method*` /
 * `latepoint_*payment_time*` filter callback this add-on registers. Extracted out of the main
 * addon class so "is Multibanco or Payshop offered at checkout" is answerable (and testable)
 * without the full LatePoint bootstrap.
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
	 * Used whenever the merchant's own minimum-lead-time setting is missing or invalid. 2, not 1:
	 * at a 1-day minimum, a customer booking at 23:00 for a 09:00 appointment gets a two-hour real
	 * payment window — technically possible via homebanking, but a poor experience that reliably
	 * ends in a blocked slot and a cancellation. Public: also the settings field's own placeholder
	 * (IfthenpayLpAdminFormRenderer::render_multibanco_lead_time_field()), so the two never drift.
	 */
	public const DEFAULT_MULTIBANCO_LEAD_TIME_DAYS = 2;

	/**
	 * Same reasoning as DEFAULT_MULTIBANCO_LEAD_TIME_DAYS, own constant since the two settings are
	 * independent.
	 */
	public const DEFAULT_PAYSHOP_LEAD_TIME_DAYS = 2;

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
				'image_url' => IfthenpayPaymentsForLatepoint::images_url() . 'ifthenpay-gateway.png',
				'code'      => 'ifthenpay_checkout',
				'time_type' => 'now',
			),
			'ifthenpay_multibanco' => array(
				'name'      => __( 'Multibanco', 'ifthenpay-payments-for-latepoint' ),
				'label'     => __( 'Multibanco reference', 'ifthenpay-payments-for-latepoint' ),
				'image_url' => IfthenpayPaymentsForLatepoint::images_url() . 'multibanco-brand.png',
				'code'      => 'ifthenpay_multibanco',
				'time_type' => 'later',
			),
			'ifthenpay_payshop'    => array(
				'name'      => __( 'Payshop', 'ifthenpay-payments-for-latepoint' ),
				'label'     => __( 'Payshop reference', 'ifthenpay-payments-for-latepoint' ),
				'image_url' => IfthenpayPaymentsForLatepoint::images_url() . 'payshop-brand.png',
				'code'      => 'ifthenpay_payshop',
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
			'image_url' => IfthenpayPaymentsForLatepoint::images_url() . 'ifthenpay-brand.png',
		);
		return $payment_processors;
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
	 * A deferred method (Multibanco, Payshop) needs more than a usable gateway key: the merchant
	 * must have checked the method's own code in Payment Methods
	 * (IfthenpayLpAdminFormRenderer::get_saved_enabled_methods() — one setting covers both the
	 * "Pay Now" and "Pay Later" sections, the split there is display-only), the selected gateway
	 * must actually carry an account for that method, and the current checkout's own appointment
	 * must not be sooner than the merchant's own minimum lead time for it
	 * (is_appointment_far_enough_out()). Otherwise the method would be offered at checkout only to
	 * fail at reference-creation time with a confusing error, or produce a reference with no real
	 * payment window. One gate serves every deferred method — each caller passes its own method
	 * code and lead-time setting, the same way is_appointment_far_enough_out() itself is already
	 * parameterized, since the check itself never differs between methods, only the values.
	 *
	 * A dataset fetch failure fails open, same reasoning as is_gateway_key_usable(): an outage
	 * must not take checkout down for an otherwise valid setup. If it recurs at the moment of
	 * checkout, IfthenpayLpPaymentProcessor's own deferred-payment methods fail that one attempt
	 * gracefully instead.
	 *
	 * @param string $method_code       The ifthenpay account-map code, e.g. 'MB', 'PAYSHOP'.
	 * @param string $lead_time_setting The merchant setting to read (in whole days).
	 * @param int    $lead_time_default Used when the setting is missing, or below $lead_time_min.
	 * @param int    $lead_time_min     The setting's own validator floor.
	 */
	private static function is_deferred_method_usable( string $method_code, string $lead_time_setting, int $lead_time_default, int $lead_time_min ): bool {
		if ( ! in_array( $method_code, IfthenpayLpAdminFormRenderer::get_saved_enabled_methods(), true ) ) {
			return false;
		}

		$backoffice_key = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_backoffice_key', '' );
		$gateway_key    = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key', '' );
		if ( '' === $backoffice_key || '' === $gateway_key ) {
			return false;
		}

		if ( ! self::is_appointment_far_enough_out( $lead_time_setting, $lead_time_default, $lead_time_min ) ) {
			return false;
		}

		$dataset = IfthenpayLpGatewayDataset::get( $backoffice_key );
		if ( null === $dataset ) {
			return true;
		}

		return isset( $dataset['accounts'][ $gateway_key ][ $method_code ] );
	}

	/**
	 * PayByLink needs more than a usable gateway key: at least one of the merchant's enabled Pay Now
	 * methods must actually carry a live account on the selected gateway. Without this, ifthenpay
	 * would still create a link, but with an empty accounts field — its hosted page then falls back
	 * to every method configured on the gateway account itself, silently ignoring this merchant's
	 * own Payment Methods selection (IfthenpayLpDataFormatter::build_accounts_string()'s own
	 * docblock). Offering the method at checkout only to reject it once the customer picks it is the
	 * same failure mode is_deferred_method_usable() exists to avoid, so this mirrors it: gate list
	 * membership on the same live account data, rather than surfacing the failure only once the
	 * customer has already committed to this method.
	 *
	 * A dataset fetch failure fails open, same reasoning as is_gateway_key_usable(): an outage must
	 * not take checkout down for an otherwise valid setup.
	 */
	private static function is_pay_by_link_usable(): bool {
		$backoffice_key = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_backoffice_key', '' );
		$gateway_key    = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key', '' );
		if ( '' === $backoffice_key || '' === $gateway_key ) {
			return false;
		}

		$dataset = IfthenpayLpGatewayDataset::get( $backoffice_key );
		if ( null === $dataset ) {
			return true;
		}

		$accounts_for_gateway = $dataset['accounts'][ $gateway_key ] ?? array();
		foreach ( IfthenpayLpAdminFormRenderer::get_saved_enabled_methods() as $method_code ) {
			if ( IfthenpayLpPayByLinkMethodEligibility::is_listed_in_pay_by_link( $method_code ) && isset( $accounts_for_gateway[ $method_code ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Below a merchant's own minimum lead time, a deferred method is not offered at checkout at
	 * all — a reference needs a real payment window (IfthenpayLpAppointmentLeadTime's own docblock
	 * on why "days" is a calendar distance, not a rolling 24h window). Shared by every
	 * is_deferred_method_usable() call, each passing its own setting name, default and floor — the
	 * settings are independent per method, but the check itself is identical.
	 *
	 * Only touches cart state for a request that is genuinely mid-checkout: a cart cookie must
	 * already exist (OsCartsHelper::get_cart_uuid()) before reconstructing it — this filter also
	 * fires on requests that are not a checkout at all, and OsCartsHelper::get_or_create_cart()
	 * sets a fresh cookie as a side effect for a visitor who never had one (verified directly
	 * against LatePoint's own OsCartsHelper::create_cart() source).
	 *
	 * No cart yet, or a cart with no booking item in it — nothing to gate against — fails open,
	 * same reasoning as every other check in this class: an inconclusive state must never itself be
	 * why checkout breaks.
	 *
	 * @param string $setting_name The merchant setting to read (in whole days).
	 * @param int    $default_days Used when the setting is missing, or below $min_days.
	 * @param int    $min_days     The setting's own validator floor (e.g.
	 *                             IfthenpayLpMultibancoLeadTimeValidation::MIN_DAYS) — a value below
	 *                             it can only mean it was saved before that floor existed, or
	 *                             written outside the validator, never a deliberate "no minimum".
	 */
	private static function is_appointment_far_enough_out( string $setting_name, int $default_days, int $min_days ): bool {
		if ( empty( OsCartsHelper::get_cart_uuid() ) ) {
			return true;
		}

		$days_until = IfthenpayLpAppointmentLeadTime::days_until_earliest_booking( OsCartsHelper::get_or_create_cart() );
		if ( null === $days_until ) {
			return true;
		}

		$minimum = (int) OsSettingsHelper::get_settings_value( $setting_name, $default_days );
		if ( $minimum < $min_days ) {
			$minimum = $default_days;
		}

		return $days_until >= $minimum;
	}

	/**
	 * This add-on's supported methods, filtered down to the ones actually usable right now —
	 * ifthenpay_gateway additionally needs is_pay_by_link_usable(), ifthenpay_multibanco and
	 * ifthenpay_payshop additionally need is_deferred_method_usable(), each with its own method
	 * code and lead-time setting. All three fail the same way for the same reason: no live account
	 * behind the merchant's own selection means none of them should reach checkout only to fail
	 * once picked. Shared by both the payment-times filter and the enabled-methods filter so the
	 * two can never disagree about which methods are currently offered.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function usable_supported_payment_methods(): array {
		if ( ! self::is_gateway_key_usable() ) {
			return array();
		}

		$methods = self::get_supported_payment_methods();
		if ( isset( $methods['ifthenpay_gateway'] ) && ! self::is_pay_by_link_usable() ) {
			unset( $methods['ifthenpay_gateway'] );
		}
		if ( isset( $methods['ifthenpay_multibanco'] ) && ! self::is_deferred_method_usable( 'MB', 'ifthenpay_multibanco_lead_time_days', self::DEFAULT_MULTIBANCO_LEAD_TIME_DAYS, IfthenpayLpMultibancoLeadTimeValidation::MIN_DAYS ) ) {
			unset( $methods['ifthenpay_multibanco'] );
		}
		if ( isset( $methods['ifthenpay_payshop'] ) && ! self::is_deferred_method_usable( 'PAYSHOP', 'ifthenpay_payshop_lead_time_days', self::DEFAULT_PAYSHOP_LEAD_TIME_DAYS, IfthenpayLpPayshopLeadTimeValidation::MIN_DAYS ) ) {
			unset( $methods['ifthenpay_payshop'] );
		}

		return $methods;
	}
}
