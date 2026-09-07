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
		 * Public endpoint for “TRANSACTION” checkout. Resolves the invoice by its opaque
		 * `access_key`, the same param LatePoint core's own payment_form.php view already emits as a
		 * hidden field alongside `invoice_id` (verified at
		 * latepoint/lib/views/invoices/payment_form.php:43,50) and the same mechanism
		 * OsInvoicesController::payment_form()/summary_before_payment() use themselves — never the
		 * raw sequential id, which anyone could enumerate to read another customer's charge amount
		 * and generate a live Pay By Link for someone else's invoice.
		 */
		public function get_transaction_ifthenpay_options() {
			try {
				// LatePoint core's own get_invoice_by_key() declares an `: OsInvoiceModel` return
				// type, but for a non-empty key matching zero rows its internal
				// get_results_as_models() call returns a bare `[]` instead (model.php's own
				// set_limit(1) branch only unwraps to a single model when at least one row matched) —
				// PHP throws a TypeError from inside the helper itself. VERIFIED: reproduced with a
				// real non-matching key. A tampered/garbage key must be rejected the same as an empty
				// or genuinely-not-found one, not surface as a fatal error.
				$invoice = OsInvoicesHelper::get_invoice_by_key( sanitize_text_field( $this->params['key'] ?? '' ) );
			} catch ( TypeError $e ) {
				$invoice = null;
			}
			if ( ! $invoice || $invoice->is_new_record() ) {
				$this->send_json(
					array(
						'status'  => LATEPOINT_STATUS_ERROR,
						'message' => __( 'Invalid invoice', 'ifthenpay-payments-for-latepoint' ),
					)
				);
				return;
			}

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
				$token       = $intent_model->intent_key;
				$gateway_key = OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key' );
				$payload     = IfthenpayLpDataFormatter::build_pay_by_link_payload( $intent_model, $token, $amount );

				// An empty accounts field doesn't reject the link — ifthenpay creates it anyway and
				// its hosted page falls back to every method configured on the gateway, silently
				// ignoring this merchant's own Payment Methods selection. Must never reach that call.
				if ( '' === $payload['accounts'] ) {
					$this->send_json(
						array(
							'status'  => LATEPOINT_STATUS_ERROR,
							'message' => __( 'No payment methods are currently enabled. Please contact the site owner.', 'ifthenpay-payments-for-latepoint' ),
						)
					);
					return;
				}

				$api_result = IfthenpayLpPayByLink::create( $gateway_key, $payload );

				IfthenpayLpTransactionRepository::insert(
					array(
						'token'         => $token,
						'intent_id'     => $intent_model->id,
						'kind'          => 'realtime',
						'method'        => IfthenpayLpTransactionRepository::METHOD_PAYBYLINK,
						// Same formatted value already sent to ifthenpay in $payload — lets
						// IfthenpayLpSettlement::settle_locked() actually run its amount-mismatch
						// guard for a realtime payment, same as it already does for a deferred one;
						// that guard is a no-op whenever a record's own amount is null.
						'amount'        => $payload['amount'],
						'paybylink_url' => $api_result->redirect_url,
						'pin_code'      => $api_result->pin_code,
						// Needed so the inbound callback route (ifthenpay-lp/v1/callback) can
						// authenticate a real async notification for this payment, on gateways
						// where ifthenpay also sends one for realtime methods — without this, every
						// such callback would fail anti-phishing verification against an empty key.
						'gateway_key'   => $gateway_key,
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
				// Never $e->getMessage() here — IfthenpayLpApiClient's own transport failures embed
				// the raw WP_Error string (e.g. a cURL error), which has no business reaching an
				// anonymous customer's payment modal. Same generic wording as the empty-accounts
				// branch above, for the same reason: this is our own side failing, not something the
				// customer can act on.
				$this->send_json(
					array(
						'status'  => LATEPOINT_STATUS_ERROR,
						'message' => __( 'Could not start this payment right now. Please try again or contact the site owner.', 'ifthenpay-payments-for-latepoint' ),
					)
				);
			}
		}

		/**
		 * Handle overlay callback and update payment status — the polling fallback for realtime
		 * methods, called repeatedly by the browser (front.js) while the response says 'pending'.
		 * All the decision logic lives in resolve_payment_status_from_modal_url(), kept separate so
		 * it's testable without a real HTTP/AJAX round trip; this method's only job is to gather
		 * $this->params and hand the result to send_json().
		 *
		 * @return void Sends JSON with status, message and pending.
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
		 * At the point this runs, the order does not exist yet — the browser only submits the
		 * booking form (which is what actually creates it, via convert_to_order()) once this
		 * returns a non-pending result. So a verified payment is marked PAID directly on our own
		 * row, not through IfthenpayLpSettlement::settle_payment(), which needs a real order to
		 * settle against and would always fail here with "order not ready". The existing, unchanged
		 * process_payment_by_intent() reads this PAID status once the form submits, and LatePoint's
		 * own convert_to_order() creates the transaction/invoice/booking natively from it — the
		 * same, already-proven realtime path. settle_payment() is for the inbound callback route
		 * (which only ever fires once an order can exist) and the deferred/manual re-check paths.
		 *
		 * Uses record_verification($settle=true) (status PAID + settled_at together, in the same
		 * write as the method_data record and the method correction) rather than a bare status
		 * update — $txid itself is recorded in method_data, not the request_id column: request_id is
		 * ifthenpay's own settlement/refund identifier (IfthenpayLpSettlement's idempotency key),
		 * a genuinely different value from the transaction id, confirmed never interchangeable
		 * between the two. This row's own request_id therefore stays whatever it already was — null,
		 * unless a real async callback for this same realtime payment (the gateway_key stored at
		 * checkout time lets one authenticate) arrives and populates it. Until then, such a callback
		 * finds no row by request_id and is rejected — safe (this payment is already settled, so
		 * nothing is lost), just an imprecise acknowledgement back to ifthenpay.
		 *
		 * A row already PAID is never downgraded, and ifthenpay's own verification — never the
		 * browser's self-reported $type, which anyone holding a payment_token could otherwise send as
		 * 'cancel' to cancel someone else's in-flight payment — decides whether to settle, for every
		 * $type, not only 'success'.
		 *
		 * Locked on the token once a decision is ready to write (apply_polling_outcome()) — the same
		 * key IfthenpayLpCallbackRestController::settle_realtime() also locks on, since the inbound
		 * callback for this same realtime payment can genuinely arrive mid-poll. Without a shared
		 * lock, this browser call and that callback could both read the row before either writes —
		 * harmless when both agree the payment succeeded, but a real risk if this call is about to
		 * write CANCELLED/FAILED at the exact moment the callback is confirming payment. The outbound
		 * verify_transaction() call itself stays outside the lock — an HTTP round trip must never be
		 * held while blocking that other writer for the same token.
		 *
		 * @param string $type  The browser's own report: 'success' | 'cancel' | anything else. Not
		 *                      trusted on its own — see above.
		 * @param string $txid  ifthenpay's transaction id, from the redirect return URL.
		 * @param string $token Our own correlation handle (the repository row's token column).
		 * @return array{status:string,message:string,pending:bool}
		 */
		private function resolve_payment_status_from_modal_url( string $type, string $txid, string $token ): array {
			try {
				$record = IfthenpayLpTransactionRepository::find_by_token( $token );
				if ( ! $record ) {
					return $this->terminal_error( __( 'Payment record not found', 'ifthenpay-payments-for-latepoint' ) );
				}

				if ( 'PAID' === $record->status ) {
					return $this->paid_response();
				}

				$confirmation = '' !== $txid ? self::verify_transaction( $txid ) : null;

				return IfthenpayLpSettlementLock::with_lock(
					$token,
					function () use ( $type, $txid, $token, $confirmation ) {
						return $this->apply_polling_outcome( $type, $txid, $token, $confirmation );
					}
				);
			} catch ( IfthenpayLpLockUnavailableException $e ) {
				// A concurrent write for this same token — most likely the inbound callback route
				// settling it right now. Ask the browser to poll again rather than guessing.
				return array(
					'status'  => LATEPOINT_STATUS_ERROR,
					'message' => '',
					'pending' => true,
				);
			} catch ( Exception $e ) {
				return $this->terminal_error( $e->getMessage() );
			}
		}

		/**
		 * Runs with the lock for $token already held — re-reads the record fresh, since the pre-lock
		 * checks in resolve_payment_status_from_modal_url() (and $confirmation's own outbound call)
		 * may already be stale by the time this runs, e.g. the callback route settling this same row
		 * in between.
		 *
		 * @param string      $type         As passed to resolve_payment_status_from_modal_url().
		 * @param string      $txid         As passed to resolve_payment_status_from_modal_url().
		 * @param string      $token        As passed to resolve_payment_status_from_modal_url().
		 * @param object|null $confirmation As returned by verify_transaction(), resolved before the
		 *                                  lock was acquired.
		 * @phpstan-param object{payment_method:string,amount:string,order_id:string}|null $confirmation
		 * @return array{status:string,message:string,pending:bool}
		 */
		private function apply_polling_outcome( string $type, string $txid, string $token, ?object $confirmation ): array {
			$record = IfthenpayLpTransactionRepository::find_by_token( $token );
			if ( ! $record ) {
				return $this->terminal_error( __( 'Payment record not found', 'ifthenpay-payments-for-latepoint' ) );
			}

			if ( 'PAID' === $record->status ) {
				return $this->paid_response();
			}

			if ( null !== $confirmation ) {
				IfthenpayLpTransactionRepository::record_verification( $token, $txid, $confirmation, 'polling', true );

				if ( $confirmation->order_id === $token ) {
					return $this->paid_response();
				}
			}

			if ( 'success' === $type ) {
				// The redirect says success, but ifthenpay doesn't confirm it yet — a real,
				// if narrow, propagation delay (MBWAY via SIBS in particular can take a while
				// to report back; this is why the old blocking retry loop ran up to 45s). Ask
				// the browser to poll again rather than declaring failure outright.
				return array(
					'status'  => LATEPOINT_STATUS_ERROR,
					'message' => '',
					'pending' => true,
				);
			}

			// A txid ifthenpay doesn't recognise at all ($confirmation still null here) —
			// recorded anyway so a disputed cancel/failure ("I paid, it shows cancelled") has a
			// starting point to investigate. Skipped when $confirmation is already set: that
			// branch above already recorded this same transaction_id (plus more).
			if ( '' !== $txid && null === $confirmation ) {
				IfthenpayLpTransactionRepository::update_method_data( $token, array( 'transaction_id' => $txid ) );
			}

			if ( 'cancel' === $type ) {
				IfthenpayLpTransactionRepository::update_status( $token, 'CANCELLED' );

				return $this->terminal_error( __( 'Payment cancelled', 'ifthenpay-payments-for-latepoint' ) );
			}

			IfthenpayLpTransactionRepository::update_status( $token, 'FAILED' );

			return $this->terminal_error( __( 'Payment failed due to payment verification error', 'ifthenpay-payments-for-latepoint' ) );
		}

		/**
		 * Confirms a txid with ifthenpay directly (IfthenpayLpTransactionStatus). A transport
		 * failure is treated the same as "not confirmed yet", not a hard error — an ifthenpay
		 * outage here must fall through to the same 'pending: true' / poll-again behaviour as a
		 * genuine propagation delay, never a terminal failure for the customer. Callers must still
		 * check the returned order_id against their own token — this only confirms the txid is a
		 * real, completed payment, not that it belongs to this booking.
		 *
		 * @param string $txid ifthenpay's transaction id.
		 * @return object{payment_method:string,amount:string,order_id:string}|null
		 */
		private static function verify_transaction( string $txid ): ?object {
			try {
				return IfthenpayLpTransactionStatus::check( $txid );
			} catch ( IfthenpayLpApiException $e ) {
				return null;
			}
		}

		/**
		 * A confirmed-paid response.
		 *
		 * @return array{status:string,message:string,pending:bool}
		 */
		private function paid_response(): array {
			return array(
				'status'  => LATEPOINT_STATUS_SUCCESS,
				'message' => __( 'Payment completed', 'ifthenpay-payments-for-latepoint' ),
				'pending' => false,
			);
		}

		/**
		 * A non-pending failure response — the browser stops polling on this.
		 *
		 * @param string $message Already-translated, customer-facing message.
		 * @return array{status:string,message:string,pending:bool}
		 */
		private function terminal_error( string $message ): array {
			return array(
				'status'  => LATEPOINT_STATUS_ERROR,
				'message' => $message,
				'pending' => false,
			);
		}
	}

endif;
