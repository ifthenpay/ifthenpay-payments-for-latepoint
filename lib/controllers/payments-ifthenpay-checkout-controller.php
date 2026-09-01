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
			// 1) Bootstrap cart/booking
			OsStepsHelper::set_required_objects( $this->params );
			$amount = OsStepsHelper::$cart_object->specs_calculate_amount_to_charge();

			// 2) Create or update the intent
			$booking_url  = $this->params['booking_form_page_url'] ?? wp_get_original_referer();
			$order_intent = OsOrderIntentHelper::create_or_update_order_intent(
				OsStepsHelper::$cart_object,
				OsStepsHelper::$restrictions,
				OsStepsHelper::$presets,
				$booking_url
			);

			// 3) Delegate the rest
			$this->send_ifthenpay_options( $order_intent, $amount );
		}


		/**
		 * Public endpoint for “TRANSACTION” checkout.
		 */
		public function get_transaction_ifthenpay_options() {
			// 1) Validate & load invoice
			if ( ! filter_var( $this->params['invoice_id'], FILTER_VALIDATE_INT ) ) {
				wp_send_json_error( 'Invalid invoice ID' );
			}
			$invoice = new OsInvoiceModel( $this->params['invoice_id'] );

			// 2) Create or update the intent
			$transaction_intent = OsTransactionIntentHelper::create_or_update_transaction_intent(
				$invoice,
				$this->params
			);
			$amount             = $transaction_intent->specs_charge_amount;

			// 3) Delegate the rest
			$this->send_ifthenpay_options( $transaction_intent, $amount );
		}


		/**
		 * Shared core: skip on zero, consume ifthenpay API, persist & respond.
		 *
		 * @param  object $intent_model  An OrderIntent or TransactionIntent instance.
		 * @param  float  $amount  How much to charge.
		 */
		private function send_ifthenpay_options( $intent_model, $amount ) {
			// Skip‐payment if free
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
				// 1) Server Side Token
				$token = $intent_model->intent_key;

				// 2) Build Payload & Generate Pay-by-Link
				$payload    = IfthenpayDataFormatter::build_pay_by_link_payload( $intent_model, $token, $amount );
				$api_result = IfthenpayLpPayByLink::create(
					OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key' ),
					$payload
				);

				// 3) Persist as PENDING
				IfthenpayLpTransactionRepository::insert(
					array(
						'token'         => $token,
						'intent_id'     => $intent_model->id,
						'kind'          => 'realtime',
						'method'        => IfthenpayLpTransactionRepository::METHOD_PAYBYLINK,
						'paybylink_url' => $api_result->redirect_url,
					)
				);

				// 4) Success JSON
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
		 * Handle overlay callback and update payment status.
		 *
		 * @return void Sends JSON with status and message.
		 */
		public function update_payment_repo_by_modal_url() {
			// Pull callback params
			$type  = $this->params['ifthenpay_return'];
			$txid  = $this->params['txid'];
			$token = $this->params['payment_token'];

			try {
				// Success path: verify then mark PAID
				if ( $type === 'success' && $this->verifyPaymentWithRetry( $txid ) ) {
					IfthenpayLpTransactionRepository::update_method_data( $token, array( 'transaction_id' => $txid ) );
					IfthenpayLpTransactionRepository::update_status( $token, 'PAID' );
					$this->send_json(
						array(
							'status'  => LATEPOINT_STATUS_SUCCESS,
							'message' => __( 'Payment completed', 'ifthenpay-payments-for-latepoint' ),
						)
					);
				}
				// Cancelled by user: mark CANCELLED and return error
				elseif ( $type === 'cancel' ) {
					IfthenpayLpTransactionRepository::update_status( $token, 'CANCELLED' );
					$this->send_json(
						array(
							'status'  => LATEPOINT_STATUS_ERROR,
							'message' => __( 'Payment cancelled', 'ifthenpay-payments-for-latepoint' ),
						)
					);
				}
				// All other cases (error or failed payment verification): mark FAILED and return error
				else {
					IfthenpayLpTransactionRepository::update_status( $token, 'FAILED' );
					IfthenpayLpTransactionRepository::update_method_data( $token, array( 'transaction_id' => $txid ) );
					$this->send_json(
						array(
							'status'  => LATEPOINT_STATUS_ERROR,
							'message' => __( 'Payment failed due to payment verification error', 'ifthenpay-payments-for-latepoint' ),
						)
					);
				}
			} catch ( Exception $e ) {
				// Exception fallback
				$this->send_json(
					array(
						'status'  => LATEPOINT_STATUS_ERROR,
						'message' => $e->getMessage(),
					)
				);
			}
		}

		/**
		 * Retry payment verification until success or timeout.
		 *
		 * @param string $txid    Transaction ID.
		 * @param int    $timeout Max seconds to retry.
		 * @param int    $interval Seconds between attempts.
		 * @return bool True if verified.
		 */
		private function verifyPaymentWithRetry( string $txid, int $timeout = 45, int $interval = 3 ): bool {
			$deadline = time() + $timeout;
			do {
				if ( IfthenpayAPIClient::get_payment_status_by_transaction_id( $txid ) ) {
					return true;
				}
				sleep( $interval );
			} while ( time() < $deadline );

			return false;
		}
	}

endif;
