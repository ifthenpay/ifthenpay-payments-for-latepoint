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
	 * Used whenever the merchant's own validity setting is missing or zero — D-2 requires a
	 * validity value is always sent to ifthenpay, since its own API default is no expiry at all,
	 * which would hold a booking slot forever. Public: also the settings field's own placeholder
	 * (IfthenpayLpAdminFormRenderer::render_multibanco_validity_field()), so the two never drift.
	 */
	public const DEFAULT_MULTIBANCO_VALIDITY_DAYS = 3;

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
			return self::process_deferred_payment_by_intent( $order_intent );
		}
		if ( 'ifthenpay_gateway' !== $method ) {
			return $result;
		}
		return self::process_payment_by_intent( $order_intent );
	}

	/**
	 * The `latepoint_process_payment_for_transaction_intent` filter callback — paying an existing
	 * invoice directly. Deferred methods cannot be offered here. Paying an existing invoice
	 * directly (OsTransactionIntentModel::convert_to_transaction(), verified against LatePoint
	 * 5.6.10 source) requires an immediately-successful payment result and aborts the whole
	 * conversion otherwise — unlike the order path, there is no "commit unpaid, settle later"
	 * contract to rely on. See IfthenpayLpSettlement's own file-level scope note.
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
			$msg = __( 'Multibanco is not available for this payment. Please choose another method.', 'ifthenpay-payments-for-latepoint' );
			$transaction_intent->add_error( 'payment_error', $msg );
			return array(
				'status'  => LATEPOINT_STATUS_ERROR,
				'message' => $msg,
			);
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
			$msg = __( 'Missing payment token', 'ifthenpay-payments-for-latepoint' );
			$intent_model->add_error( 'payment_error', $msg );
			return array(
				'status'  => LATEPOINT_STATUS_ERROR,
				'message' => $msg,
			);
		}

		$payment = IfthenpayLpTransactionRepository::find_by_token( $token );
		if ( ! $payment ) {
			$msg = __( 'Payment record not found', 'ifthenpay-payments-for-latepoint' );
			$intent_model->add_error( 'payment_error', $msg );
			return array(
				'status'  => LATEPOINT_STATUS_ERROR,
				'message' => $msg,
			);
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

		$intent_model->add_error( 'payment_error', $msg );
		return array(
			'status'  => LATEPOINT_STATUS_ERROR,
			'message' => $msg,
		);
	}

	/**
	 * Generates a Multibanco reference for a deferred checkout and persists it. Returns a
	 * non-success result WITHOUT calling $order_intent->add_error() — see research.md:
	 * OsOrderIntentModel::convert_to_order() aborts conversion only on $intent->get_error(), a
	 * falsy $transaction does not stop it, so this is exactly how native "Pay Later" behaves.
	 * Adding an error here would fail the whole booking instead of committing it unpaid.
	 *
	 * @param OsOrderIntentModel $order_intent The order intent being converted.
	 * @return array<string,mixed>
	 */
	private static function process_deferred_payment_by_intent( OsOrderIntentModel $order_intent ): array {
		$amount = number_format( (float) $order_intent->charge_amount, 2, '.', '' );

		$backoffice_key = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_backoffice_key', '' );
		$gateway_key    = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key', '' );
		$dataset        = '' !== $backoffice_key ? IfthenpayLpGatewayDataset::get( $backoffice_key ) : null;
		$mb_key         = $dataset['accounts'][ $gateway_key ]['MB'] ?? '';

		if ( '' === $mb_key ) {
			return self::deferred_payment_failed( $order_intent, __( 'Multibanco is not currently available. Please choose another payment method.', 'ifthenpay-payments-for-latepoint' ) );
		}

		$validity_days = (int) OsSettingsHelper::get_settings_value( 'ifthenpay_multibanco_validity_days', self::DEFAULT_MULTIBANCO_VALIDITY_DAYS );
		if ( $validity_days <= 0 ) {
			// IfthenpayLpMultibancoValidityValidation blocks a save below its own MIN_DAYS (1), so
			// this only catches a value saved before that floor existed, or written outside the
			// validator — a missing or zero setting must never mean "no expiry" (D-2).
			$validity_days = self::DEFAULT_MULTIBANCO_VALIDITY_DAYS;
		}

		try {
			$reference = IfthenpayLpMultibancoReference::create( $mb_key, $order_intent->intent_key, $amount, IfthenpayLpExpiry::to_multibanco_days( $validity_days ) );
		} catch ( IfthenpayLpApiException $e ) {
			return self::deferred_payment_failed( $order_intent, __( 'Could not generate a Multibanco reference right now. Please try again or choose another payment method.', 'ifthenpay-payments-for-latepoint' ) );
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
	 * Shared failure path for process_deferred_payment_by_intent(): unlike a successful deferred
	 * result, a failure to even generate a reference must stop the booking — the customer needs
	 * to pick another method, not commit to a payment that was never created.
	 *
	 * @param OsOrderIntentModel $order_intent The order intent being converted.
	 * @param string             $message      Already-translated, customer-facing message.
	 * @return array<string,mixed>
	 */
	private static function deferred_payment_failed( OsOrderIntentModel $order_intent, string $message ): array {
		$order_intent->add_error( 'payment_error', $message );
		return array(
			'status'  => LATEPOINT_STATUS_ERROR,
			'message' => $message,
		);
	}
}
