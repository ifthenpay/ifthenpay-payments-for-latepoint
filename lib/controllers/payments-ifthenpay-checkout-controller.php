<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'OsPaymentsIfthenpayCheckoutController' ) ) :

	/**
	 * The front-end checkout AJAX endpoints — fetching a Pay By Link for an order or a standalone
	 * transaction, and handling the overlay's return callback. Public (guest-reachable): a
	 * customer paying for a booking is not necessarily logged in.
	 *
	 * @package ifthenpay-payments-for-latepoint
	 */
	class OsPaymentsIfthenpayCheckoutController extends OsController {

		public function __construct() {
			parent::__construct();

			$this->action_access['public'] = array_merge( $this->action_access['public'], array( 'get_order_ifthenpay_options', 'get_transaction_ifthenpay_options', 'update_payment_repo_by_modal_url' ) );
		}

		/**
		 * Public endpoint for “ORDER” checkout.
		 */
		public function get_order_ifthenpay_options() {
			OsStepsHelper::set_required_objects( $this->params );
			$amount = OsStepsHelper::$cart_object->specs_calculate_amount_to_charge();

			$booking_url  = $this->params['booking_form_page_url'] ?? wp_get_original_referer();
			$order_intent = OsOrderIntentHelper::create_or_update_order_intent(
				OsStepsHelper::$cart_object,
				OsStepsHelper::$restrictions,
				OsStepsHelper::$presets,
				$booking_url
			);

			$this->send_ifthenpay_options( $order_intent, $amount );
		}

		/**
		 * Public endpoint for “TRANSACTION” checkout.
		 */
		public function get_transaction_ifthenpay_options() {
			if ( ! filter_var( $this->params['invoice_id'], FILTER_VALIDATE_INT ) ) {
				wp_send_json_error( __( 'Invalid invoice ID', 'ifthenpay-payments-for-latepoint' ) );
			}
			$invoice = new OsInvoiceModel( $this->params['invoice_id'] );

			$transaction_intent = OsTransactionIntentHelper::create_or_update_transaction_intent(
				$invoice,
				$this->params
			);
			$amount             = $transaction_intent->specs_charge_amount;

			$this->send_ifthenpay_options( $transaction_intent, $amount );
		}

		/**
		 * Shared core: skip on zero, consume ifthenpay API, persist & respond.
		 *
		 * @param  object $intent_model  An OrderIntent or TransactionIntent instance.
		 * @param  float  $amount  How much to charge.
		 */
		private function send_ifthenpay_options( $intent_model, $amount ) {
			if ( $amount <= 0 ) {
				$this->send_json(
					array(
						'status'       => LATEPOINT_STATUS_SUCCESS,
						'skip_payment' => true,
						'message'      => __( 'Nothing to pay', 'ifthenpay-payments-for-latepoint' ),
					)
				);
				return;
			}

			try {
				$token      = $intent_model->intent_key;
				$payload    = IfthenpayDataFormatter::build_pay_by_link_payload( $intent_model, $token, $amount );
				$api_result = IfthenpayLpPayByLink::create(
					OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key' ),
					$payload
				);

				IfthenpayLpTransactionRepository::insert(
					array(
						'token'         => $token,
						'intent_id'     => $intent_model->id,
						'kind'          => 'realtime',
						'method'        => IfthenpayLpTransactionRepository::METHOD_PAYBYLINK,
						'paybylink_url' => $api_result->redirect_url,
					)
				);

				$this->send_json(
					array(
						'status'        => LATEPOINT_STATUS_SUCCESS,
						'token'         => $token,
						'paybylink_url' => esc_url_raw( $api_result->redirect_url ),
						'success_url'   => $payload['success_url'],
						'cancel_url'    => $payload['cancel_url'],
						'error_url'     => $payload['error_url'],
					)
				);
			} catch ( Exception $e ) {
				$this->send_json(
					array(
						'status'  => LATEPOINT_STATUS_ERROR,
						'message' => $e->getMessage(),
					)
				);
			}
		}

		/**
		 * Handle overlay callback and update payment status — the polling fallback for realtime
		 * methods. All the decision logic lives in resolve_payment_status_from_modal_url(), kept
		 * separate so it's testable without a real HTTP/AJAX round trip; this method's only job is
		 * to gather $this->params and hand the result to send_json().
		 *
		 * @return void Sends JSON with status and message.
		 */
		public function update_payment_repo_by_modal_url() {
			$this->send_json(
				$this->resolve_payment_status_from_modal_url(
					(string) ( $this->params['ifthenpay_return'] ?? '' ),
					(string) ( $this->params['txid'] ?? '' ),
					(string) ( $this->params['payment_token'] ?? '' )
				)
			);
		}

		/**
		 * Settlement itself goes through IfthenpayLpSettlement::settle_payment(), the same function
		 * the inbound callback route and the manual re-check action call: this method's own job is
		 * only to ask ifthenpay whether $txid is paid, then let settle_payment() do the actual state
		 * change (invariant 3, one settlement function).
		 *
		 * Security fix (FR-13, spec 001): the previous version wrote CANCELLED/FAILED straight from
		 * the browser's own $type, with no verification at all — anyone holding a payment_token
		 * could cancel another customer's in-flight payment, and a customer who closed the modal
		 * right after paying could have their own successful payment marked FAILED. Now: a row
		 * already PAID is never downgraded, and ifthenpay's own verification — never the browser's
		 * self-reported $type — decides whether to settle, for every $type, not only 'success'.
		 *
		 * @param string $type  The browser's own report: 'success' | 'cancel' | anything else. Not
		 *                      trusted on its own — see above.
		 * @param string $txid  ifthenpay's transaction id, from the redirect return URL.
		 * @param string $token Our own correlation handle (the repository row's token column).
		 * @return array{status:string,message:string}
		 */
		private function resolve_payment_status_from_modal_url( string $type, string $txid, string $token ): array {
			try {
				$record = IfthenpayLpTransactionRepository::find_by_token( $token );
				if ( ! $record ) {
					return array(
						'status'  => LATEPOINT_STATUS_ERROR,
						'message' => __( 'Payment record not found', 'ifthenpay-payments-for-latepoint' ),
					);
				}

				if ( 'PAID' === $record->status ) {
					return array(
						'status'  => LATEPOINT_STATUS_SUCCESS,
						'message' => __( 'Payment completed', 'ifthenpay-payments-for-latepoint' ),
					);
				}

				if ( '' !== $txid && IfthenpayAPIClient::get_payment_status_by_transaction_id( $txid ) ) {
					// The repository row's own token is the only correlation handle stored at
					// checkout time (send_ifthenpay_options() never had $txid to store); linking
					// $txid onto it here, right as we confirm ifthenpay considers it paid, is what
					// lets settle_payment() find this row by its own idempotency key afterwards.
					IfthenpayLpTransactionRepository::set_request_id( $token, $txid );

					$result = IfthenpayLpSettlement::settle_payment( $txid, array(), 'polling' );

					return $result->is_settled()
						? array(
							'status'  => LATEPOINT_STATUS_SUCCESS,
							'message' => __( 'Payment completed', 'ifthenpay-payments-for-latepoint' ),
						)
						: array(
							'status'  => LATEPOINT_STATUS_ERROR,
							'message' => __( 'Payment could not be confirmed. Please try again.', 'ifthenpay-payments-for-latepoint' ),
						);
				}

				if ( 'cancel' === $type ) {
					IfthenpayLpTransactionRepository::update_status( $token, 'CANCELLED' );

					return array(
						'status'  => LATEPOINT_STATUS_ERROR,
						'message' => __( 'Payment cancelled', 'ifthenpay-payments-for-latepoint' ),
					);
				}

				IfthenpayLpTransactionRepository::update_status( $token, 'FAILED' );
				IfthenpayLpTransactionRepository::update_method_data( $token, array( 'transaction_id' => $txid ) );

				return array(
					'status'  => LATEPOINT_STATUS_ERROR,
					'message' => __( 'Payment failed due to payment verification error', 'ifthenpay-payments-for-latepoint' ),
				);
			} catch ( Exception $e ) {
				return array(
					'status'  => LATEPOINT_STATUS_ERROR,
					'message' => $e->getMessage(),
				);
			}
		}
	}

endif;
