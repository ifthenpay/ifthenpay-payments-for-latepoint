<?php
/**
 * Every `latepoint_process_payment_for_*_intent` filter callback this add-on registers — the
 * actual "does this payment succeed, and how" business logic, extracted out of the main addon
 * class so it's unit-testable in isolation (Brain Monkey) instead of only reachable through the
 * full LatePoint bootstrap.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless by design: every method is static, and the processor code is a class constant, not
 * instance state — there is nothing here that varies per request or per instance.
 */
class IfthenpayLpPaymentProcessor {

	public const PROCESSOR_CODE = 'ifthenpay';

	/**
	 * Used whenever the merchant's own validity setting is missing or zero — a validity value must
	 * always be sent to ifthenpay, since its own API default is no expiry at all, which would hold
	 * a booking slot forever. Public: also the settings field's own placeholder
	 * (IfthenpayLpAdminFormRenderer::render_multibanco_timing_fields()), so the two never drift.
	 */
	public const DEFAULT_MULTIBANCO_VALIDITY_DAYS = 3;

	/**
	 * Same reasoning as DEFAULT_MULTIBANCO_VALIDITY_DAYS, own constant since the two settings are
	 * independent — a merchant can raise or lower one without touching the other.
	 */
	public const DEFAULT_PAYSHOP_VALIDITY_DAYS = 3;

	/**
	 * The `latepoint_process_payment_for_order_intent` filter callback — a booking checkout.
	 *
	 * @param array<string,mixed> $result       The filter's own accumulator.
	 * @param OsOrderIntentModel  $order_intent The order intent being converted.
	 * @return array<string,mixed>
	 */
	public static function process_payments_for_order_intent( array $result, OsOrderIntentModel $order_intent ): array {
		if ( ! OsPaymentsHelper::should_processor_handle_payment_for_order_intent( self::PROCESSOR_CODE, $order_intent ) ) {
			return $result;
		}

		$method = $order_intent->get_payment_data_value( 'method' );
		if ( 'ifthenpay_multibanco' === $method ) {
			return self::process_deferred_multibanco_payment_by_intent( $order_intent );
		}
		if ( 'ifthenpay_payshop' === $method ) {
			return self::process_deferred_payshop_payment_by_intent( $order_intent );
		}
		if ( 'ifthenpay_gateway' !== $method ) {
			return $result;
		}
		return self::process_payment_by_intent( $order_intent );
	}

	/**
	 * The `latepoint_process_payment_for_transaction_intent` filter callback — paying an existing
	 * invoice directly. Deferred methods cannot be offered here:
	 * OsTransactionIntentModel::convert_to_transaction() (verified against LatePoint 5.6.9
	 * source) requires an immediately-successful payment result and aborts the whole conversion
	 * otherwise — unlike the order path, there is no "commit unpaid, settle later" contract to
	 * rely on. See IfthenpayLpSettlement's own file-level scope note.
	 *
	 * @param array<string,mixed>      $result             The filter's own accumulator.
	 * @param OsTransactionIntentModel $transaction_intent The transaction intent being converted.
	 * @return array<string,mixed>
	 */
	public static function process_payment_for_transaction_intent( array $result, OsTransactionIntentModel $transaction_intent ): array {
		if ( ! OsPaymentsHelper::should_processor_handle_payment_for_transaction_intent( self::PROCESSOR_CODE, $transaction_intent ) ) {
			return $result;
		}

		$method = $transaction_intent->get_payment_data_value( 'method' );
		if ( 'ifthenpay_multibanco' === $method ) {
			return self::intent_error( $transaction_intent, __( 'Multibanco is not available for this payment. Please choose another method.', 'ifthenpay-payments-for-latepoint' ) );
		}
		if ( 'ifthenpay_payshop' === $method ) {
			return self::intent_error( $transaction_intent, __( 'Payshop is not available for this payment. Please choose another method.', 'ifthenpay-payments-for-latepoint' ) );
		}
		if ( 'ifthenpay_gateway' !== $method ) {
			return $result;
		}
		return self::process_payment_by_intent( $transaction_intent );
	}

	/**
	 * Shared intent-processing logic for both ORDER and TRANSACTION.
	 *
	 * @param OsOrderIntentModel|OsTransactionIntentModel $intent_model The intent being converted.
	 * @return array<string,mixed>
	 */
	private static function process_payment_by_intent( $intent_model ): array {
		$token = $intent_model->get_payment_data_value( 'token' );
		if ( ! $token ) {
			return self::intent_error( $intent_model, __( 'Missing payment token', 'ifthenpay-payments-for-latepoint' ) );
		}

		$payment = IfthenpayLpTransactionRepository::find_by_token( $token );
		if ( ! $payment ) {
			return self::intent_error( $intent_model, __( 'Payment record not found', 'ifthenpay-payments-for-latepoint' ) );
		}

		if ( $payment->status === 'PAID' ) {
			return array(
				'status'    => LATEPOINT_STATUS_SUCCESS,
				'processor' => self::PROCESSOR_CODE,
				'charge_id' => $token,
				'kind'      => LATEPOINT_TRANSACTION_KIND_CAPTURE,
			);
		}

		$msg = $payment->status === 'CANCELLED'
			? __( 'Payment was cancelled', 'ifthenpay-payments-for-latepoint' )
			: __( 'Payment failed', 'ifthenpay-payments-for-latepoint' );

		return self::intent_error( $intent_model, $msg );
	}

	/**
	 * Backfills `notes` on the transaction LatePoint core itself creates for a realtime payment
	 * (`latepoint_transaction_created`, fired right after its own `$transaction->save()` —
	 * `lib/models/order_intent_model.php`) — core sets token/method/amount/etc. but never notes,
	 * unlike IfthenpayLpSettlement::apply_state_change()'s own transaction (deferred/callback/manual),
	 * which already carries one. Only ever reachable via the realtime polling path: settle_payment()
	 * needs an already-converted order, and this order is still being converted at the point this
	 * fires, so a callback settling it first is not possible here.
	 *
	 * @param OsTransactionModel $transaction As passed by the hook.
	 */
	public static function backfill_realtime_transaction_notes( OsTransactionModel $transaction ): void {
		if ( self::PROCESSOR_CODE !== $transaction->processor || ! empty( $transaction->notes ) ) {
			return;
		}

		$record = IfthenpayLpTransactionRepository::find_by_token( (string) $transaction->token );
		if ( ! $record || 'realtime' !== $record->kind ) {
			return;
		}

		$txid = IfthenpayLpTransactionRepository::decode_method_data( $record )['transaction_id'] ?? '';

		$notes = IfthenpayLpSettlement::build_transaction_notes( $record, 'ifthenpay transaction ID', $txid, 'polling' );
		$transaction->update_attributes( array( 'notes' => $notes ) );
	}

	/**
	 * A reference must never outlive the appointment it pays for — the merchant's own setting
	 * (already defaulted/floored by the caller) is a ceiling, not the value sent as-is. Shared by
	 * both deferred methods since the rule itself must never drift between them — a genuine
	 * correctness rule, not incidental duplication.
	 *
	 * $days_until_appointment is null only when the intent carries no booking item at all (not a
	 * real deferred-checkout scenario today, but left unclamped rather than fatal if it ever
	 * happens). max(0, ...) never returns a negative value even if this intent somehow reached
	 * checkout below the minimum lead time (IfthenpayLpPaymentMethodAvailability's own gate is what
	 * should normally prevent that — this is the defensive backstop for a resumed or legacy intent,
	 * not the primary guarantee).
	 *
	 * @param int      $validity_days          The merchant's own setting, already defaulted/floored.
	 * @param int|null $days_until_appointment As returned by IfthenpayLpAppointmentLeadTime::days_until_earliest_booking().
	 */
	private static function clamp_validity_to_appointment( int $validity_days, ?int $days_until_appointment ): int {
		if ( null === $days_until_appointment ) {
			return $validity_days;
		}

		return max( 0, min( $validity_days, $days_until_appointment - 1 ) );
	}

	/**
	 * Generates a Multibanco reference for a deferred checkout and persists it. Returns a
	 * non-success result WITHOUT calling $order_intent->add_error() — verified directly against
	 * LatePoint's own source: OsOrderIntentModel::convert_to_order() aborts conversion only on
	 * $intent->get_error(), a falsy $transaction does not stop it, so this is exactly how native
	 * "Pay Later" behaves. Adding an error here would fail the whole booking instead of committing
	 * it unpaid.
	 *
	 * @param OsOrderIntentModel $order_intent The order intent being converted.
	 * @return array<string,mixed>
	 */
	private static function process_deferred_multibanco_payment_by_intent( OsOrderIntentModel $order_intent ): array {
		$amount = IfthenpayLpDataFormatter::format_amount( $order_intent->charge_amount );

		$backoffice_key = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_backoffice_key', '' );
		$gateway_key    = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key', '' );
		$dataset        = '' !== $backoffice_key ? IfthenpayLpGatewayDataset::get( $backoffice_key ) : null;
		$mb_key         = $dataset['accounts'][ $gateway_key ]['MB'] ?? '';

		if ( '' === $mb_key ) {
			return self::intent_error( $order_intent, __( 'Multibanco is not currently available. Please choose another payment method.', 'ifthenpay-payments-for-latepoint' ) );
		}

		$validity_days = (int) OsSettingsHelper::get_settings_value( 'ifthenpay_multibanco_validity_days', self::DEFAULT_MULTIBANCO_VALIDITY_DAYS );
		if ( $validity_days <= 0 ) {
			// IfthenpayLpMultibancoValidityValidation blocks a save below its own MIN_DAYS (1), so
			// this only catches a value saved before that floor existed, or written outside the
			// validator — a missing or zero setting must never mean "no expiry".
			$validity_days = self::DEFAULT_MULTIBANCO_VALIDITY_DAYS;
		}
		$validity_days = self::clamp_validity_to_appointment(
			$validity_days,
			IfthenpayLpAppointmentLeadTime::days_until_earliest_booking( $order_intent->build_cart_object() )
		);

		try {
			$reference = IfthenpayLpMultibancoReference::create( $mb_key, $order_intent->intent_key, $amount, IfthenpayLpExpiry::to_multibanco_days( $validity_days ) );
		} catch ( IfthenpayLpApiException $e ) {
			return self::intent_error( $order_intent, __( 'Could not generate a Multibanco reference right now. Please try again or choose another payment method.', 'ifthenpay-payments-for-latepoint' ) );
		}

		IfthenpayLpTransactionRepository::insert(
			array(
				'token'       => $order_intent->intent_key,
				'request_id'  => $reference->request_id,
				'intent_id'   => $order_intent->id,
				'kind'        => 'deferred',
				'method'      => 'MB',
				'status'      => 'PENDING',
				'amount'      => $amount,
				'gateway_key' => $gateway_key,
				'entity'      => $reference->entity,
				'reference'   => $reference->reference,
				'expires_at'  => IfthenpayLpExpiry::to_expires_at_datetime( $reference->expiry_date ),
			)
		);

		return array(
			'status'  => LATEPOINT_STATUS_ERROR,
			'message' => '',
		);
	}

	/**
	 * Generates a Payshop reference for a deferred checkout and persists it — same contract as
	 * process_deferred_multibanco_payment_by_intent(), differing only where Payshop's own API
	 * actually differs: no entity, expiry sent (and reconstructed for `expires_at`) as an absolute
	 * `YYYYMMDD` date rather than a day count, since ifthenpay's own create response never echoes
	 * an expiry back the way Multibanco's does.
	 *
	 * @param OsOrderIntentModel $order_intent The order intent being converted.
	 * @return array<string,mixed>
	 */
	private static function process_deferred_payshop_payment_by_intent( OsOrderIntentModel $order_intent ): array {
		$amount = IfthenpayLpDataFormatter::format_amount( $order_intent->charge_amount );

		$backoffice_key = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_backoffice_key', '' );
		$gateway_key    = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key', '' );
		$dataset        = '' !== $backoffice_key ? IfthenpayLpGatewayDataset::get( $backoffice_key ) : null;
		$payshop_key    = $dataset['accounts'][ $gateway_key ]['PAYSHOP'] ?? '';

		if ( '' === $payshop_key ) {
			return self::intent_error( $order_intent, __( 'Payshop is not currently available. Please choose another payment method.', 'ifthenpay-payments-for-latepoint' ) );
		}

		$validity_days = (int) OsSettingsHelper::get_settings_value( 'ifthenpay_payshop_validity_days', self::DEFAULT_PAYSHOP_VALIDITY_DAYS );
		if ( $validity_days <= 0 ) {
			// Same reasoning as the Multibanco branch: IfthenpayLpPayshopValidityValidation blocks
			// a save below its own MIN_DAYS (1), so this only catches a value saved before that
			// floor existed, or written outside the validator.
			$validity_days = self::DEFAULT_PAYSHOP_VALIDITY_DAYS;
		}
		$validity_days = self::clamp_validity_to_appointment(
			$validity_days,
			IfthenpayLpAppointmentLeadTime::days_until_earliest_booking( $order_intent->build_cart_object() )
		);

		$expiry_date = IfthenpayLpExpiry::to_date( $validity_days );

		try {
			$reference = IfthenpayLpPayshopReference::create( $payshop_key, $order_intent->intent_key, $amount, $expiry_date );
		} catch ( IfthenpayLpApiException $e ) {
			return self::intent_error( $order_intent, __( 'Could not generate a Payshop reference right now. Please try again or choose another payment method.', 'ifthenpay-payments-for-latepoint' ) );
		}

		IfthenpayLpTransactionRepository::insert(
			array(
				'token'       => $order_intent->intent_key,
				'request_id'  => $reference->request_id,
				'intent_id'   => $order_intent->id,
				'kind'        => 'deferred',
				'method'      => 'PAYSHOP',
				'status'      => 'PENDING',
				'amount'      => $amount,
				'gateway_key' => $gateway_key,
				'entity'      => null,
				'reference'   => $reference->reference,
				'expires_at'  => IfthenpayLpExpiry::to_expires_at_datetime_from_ymd( $expiry_date ),
			)
		);

		return array(
			'status'  => LATEPOINT_STATUS_ERROR,
			'message' => '',
		);
	}

	/**
	 * Shared failure path — every non-success result across this class adds the same error to the
	 * intent and returns the same shape. For the deferred flow specifically, this must stop the
	 * booking — the customer needs to pick another method, not commit to a payment that was never
	 * created.
	 *
	 * @param OsOrderIntentModel|OsTransactionIntentModel $intent_model The intent being converted.
	 * @param string                                      $message      Already-translated, customer-facing message.
	 * @return array<string,mixed>
	 */
	private static function intent_error( $intent_model, string $message ): array {
		$intent_model->add_error( 'payment_error', $message );
		return array(
			'status'  => LATEPOINT_STATUS_ERROR,
			'message' => $message,
		);
	}
}
