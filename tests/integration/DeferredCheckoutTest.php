<?php
/**
 * Proves IfthenpayLpPaymentProcessor's deferred-checkout path end to end: a real order intent,
 * real settings, a mocked ifthenpay HTTP layer (method catalog, gateway dataset, Multibanco
 * reference), through the plugin's real public filter entry point — the same one LatePoint calls
 * during checkout.
 *
 * @package ifthenpay-payments-for-latepoint
 */

// phpcs:ignore Squiz.Commenting.FileComment.Missing -- docblock above is the file comment; the sniff misclassifies it when a require is the first statement.
require_once __DIR__ . '/../support/ifthenpay-http-fixtures.php';

/**
 * Deferred checkout proof.
 */
class DeferredCheckoutTest extends WP_UnitTestCase {

	// Valid-format (IfthenpayLpKeyValidator::has_valid_format()), so saving it doesn't get
	// rejected by the plugin's own latepoint_model_validate hook before this test even starts.
	private const BACKOFFICE_KEY = '1234-5678-9012-3456';
	private const GATEWAY_KEY    = 'MODERN-GATEWAY'; // Matches tests/fixtures/ifthenpay/gateway-dataset.json, which has an "MB" account.

	/**
	 * The body of the last `multibanco/reference` request mock_ifthenpay_http() answered — captured
	 * so tests can assert on the actual `expiryDays` the validity clamp computed, not just that the
	 * call succeeded.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $last_multibanco_reference_request = null;

	/**
	 * Mocks the ifthenpay HTTP layer first — saving the Backoffice Key below goes through the
	 * plugin's own save-time validation (IfthenpayLpBackofficeKeyValidation), which makes a real
	 * network call unless intercepted. Then seeds the settings a working Multibanco checkout needs.
	 *
	 * Also resets IfthenpayLpMethodCatalog's own per-request in-memory cache — it has no key
	 * dimension to keep it isolated between tests by construction (see
	 * ifthenpay_lp_reset_method_catalog_cache()'s own docblock), and this file's own
	 * mock_ifthenpay_http() relies on gateway/methods/available actually being asked again.
	 */
	protected function setUp(): void {
		parent::setUp();
		ifthenpay_lp_reset_method_catalog_cache();

		add_filter( 'pre_http_request', array( $this, 'mock_ifthenpay_http' ), 10, 3 );

		OsSettingsHelper::save_setting_by_name( 'enable_payment_processor_ifthenpay', LATEPOINT_VALUE_ON );
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_backoffice_key', self::BACKOFFICE_KEY );
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_gateway_key', self::GATEWAY_KEY );
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_payment_methods_configuration', array( 'MB' ) );
	}

	/**
	 * Answers every ifthenpay HTTP call this test needs, branching on URL.
	 *
	 * @param mixed               $preempt As passed by the pre_http_request filter; unused.
	 * @param array<string,mixed> $args    As passed by the pre_http_request filter; unused.
	 * @param string              $url     The request URL — determines which fixture to answer with.
	 * @return array{response: array{code:int,message:string}, body:string, headers: array<empty>}
	 */
	public function mock_ifthenpay_http( $preempt, $args, $url ) {
		// The Backoffice Key save-time validator (see setUp()'s own note): any 200 with a
		// JSON-parseable body means "recognized", regardless of content.
		if ( false !== strpos( $url, 'getEntidadeSubentidadeJsonV2' ) ) {
			return array(
				'response' => array(
					'code'    => 200,
					'message' => '',
				),
				'body'     => '[]',
				'headers'  => array(),
			);
		}
		if ( false !== strpos( $url, 'gateway/methods/available' ) ) {
			return ifthenpay_lp_mock_response( 200, ifthenpay_lp_fixture( 'method-catalog.json' ) );
		}
		if ( false !== strpos( $url, 'gateway/get' ) ) {
			return ifthenpay_lp_mock_response( 200, ifthenpay_lp_fixture( 'gateway-dataset.json' ) );
		}
		if ( false !== strpos( $url, 'multibanco/reference' ) ) {
			$this->last_multibanco_reference_request = json_decode( (string) ( $args['body'] ?? '' ), true );
			return ifthenpay_lp_mock_response( 200, ifthenpay_lp_fixture( 'multibanco-reference-valid.json' ) );
		}

		return $preempt;
	}

	/**
	 * Builds a not-yet-converted order intent selecting Multibanco, the shape
	 * OsPaymentsHelper::should_processor_handle_payment_for_order_intent() and
	 * IfthenpayLpPaymentProcessor::process_deferred_payment_by_intent() both need to see —
	 * including a real booking in cart_items_data, which the validity clamp now reads via
	 * build_cart_object() (a real checkout always has this; only the fixture was previously
	 * cutting the corner).
	 *
	 * @param string $amount           The intent's charge_amount.
	 * @param string $appointment_date Y-m-d; default is safely far from any lead-time floor these
	 *                                 tests don't otherwise care about.
	 */
	private function create_multibanco_order_intent( string $amount = '25.00', string $appointment_date = '' ): OsOrderIntentModel {
		if ( '' === $appointment_date ) {
			$appointment_date = gmdate( 'Y-m-d', strtotime( '+10 days' ) );
		}

		$customer             = new OsCustomerModel();
		$customer->first_name = 'Test';
		$customer->last_name  = 'Customer';
		$customer->email      = 'ifthenpay-lp-deferred-' . wp_generate_password( 8, false ) . '@example.com';
		$customer->save();

		$order_intent                  = new OsOrderIntentModel();
		$order_intent->customer_id     = $customer->id;
		$order_intent->charge_amount   = $amount;
		$order_intent->status          = LATEPOINT_ORDER_INTENT_STATUS_NEW;
		$order_intent->payment_data    = wp_json_encode(
			array(
				'processor' => 'ifthenpay',
				'method'    => 'ifthenpay_multibanco',
				'time'      => LATEPOINT_PAYMENT_TIME_LATER,
				'portion'   => LATEPOINT_PAYMENT_PORTION_FULL,
				'token'     => '',
			)
		);
		$order_intent->cart_items_data = wp_json_encode(
			array(
				array(
					'variant'         => LATEPOINT_ITEM_VARIANT_BOOKING,
					'item_data'       => array(
						// null, not 0: a genuinely unsaved OsBookingModel's own ->id is null, and
						// LatePoint core's own OsModel::set_data() treats isset()+is_numeric() (true
						// for 0, false for null) as "load this from the DB" — passing 0 makes it
						// attempt load_by_id(0), a real LatePoint core wrinkle (a malformed
						// prepare() call logged, harmless but noisy) that a real not-yet-saved
						// booking never hits.
						'id'          => null,
						'customer_id' => $customer->id,
						'agent_id'    => 1,
						'service_id'  => 1,
						'location_id' => 1,
						'start_date'  => $appointment_date,
						'start_time'  => 600, // Minutes from midnight (OsBookingHelper's own default) — 10:00, not the string '10:00'.
						'end_date'    => $appointment_date,
						'end_time'    => 660, // 11:00.
					),
					'subtotal'        => $amount,
					'total'           => $amount,
					'coupon_discount' => '0',
					'coupon_code'     => '',
					'tax_total'       => '0',
				),
			)
		);
		$order_intent->save();

		return $order_intent;
	}

	/**
	 * The clamp overrides a high validity setting when the appointment is close — proves the
	 * actual effective value sent to the reference API, not just that checkout succeeds. An
	 * appointment tomorrow must clamp `expiryDays` to 0 (expires today), even with a validity
	 * setting of 10 — and critically, 0 is *below* IfthenpayLpMultibancoValidityValidation's own
	 * MIN_DAYS (1). That floor governs what a merchant may type into the setting; it must never
	 * leak into what the system computes as the effective, appointment-aware value — a value of 1
	 * here (the setting's own floor, not the appointment's) would mean the min/max validation range
	 * is silently overriding the clamp instead of the other way around.
	 */
	public function test_validity_is_clamped_below_the_settings_own_minimum_when_the_appointment_is_tomorrow(): void {
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_multibanco_validity_days', '10' );
		$tomorrow = gmdate( 'Y-m-d', strtotime( '+1 day' ) );

		$order_intent = $this->create_multibanco_order_intent( '25.00', $tomorrow );
		IfthenpayLpPaymentProcessor::process_payments_for_order_intent( array(), $order_intent );

		$this->assertNotNull( $this->last_multibanco_reference_request );
		$this->assertSame( 0, $this->last_multibanco_reference_request['expiryDays'] );
	}

	/**
	 * The reverse: an appointment far enough out is never clamped — the merchant's own setting is
	 * sent exactly as saved, not floored or ceilinged by the appointment at all.
	 */
	public function test_validity_is_not_clamped_when_the_appointment_is_far_out(): void {
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_multibanco_validity_days', '5' );
		$far_out = gmdate( 'Y-m-d', strtotime( '+30 days' ) );

		$order_intent = $this->create_multibanco_order_intent( '25.00', $far_out );
		IfthenpayLpPaymentProcessor::process_payments_for_order_intent( array(), $order_intent );

		$this->assertNotNull( $this->last_multibanco_reference_request );
		$this->assertSame( 5, $this->last_multibanco_reference_request['expiryDays'] );
	}

	/**
	 * A Multibanco checkout produces a pending, unpaid order intent and a stored reference —
	 * without blocking, and without adding an intent error, so
	 * OsOrderIntentModel::convert_to_order() goes on to commit the booking.
	 */
	public function test_deferred_checkout_produces_a_pending_reference_without_an_intent_error(): void {
		$order_intent = $this->create_multibanco_order_intent( '25.00' );

		$result = IfthenpayLpPaymentProcessor::process_payments_for_order_intent( array(), $order_intent );

		$this->assertSame( LATEPOINT_STATUS_ERROR, $result['status'] );
		$this->assertFalse( $order_intent->get_error() );

		$record = IfthenpayLpTransactionRepository::find_by_token( $order_intent->intent_key );
		$this->assertNotNull( $record );
		$this->assertSame( 'deferred', $record->kind ); // @phpstan-ignore-line property.notFound
		$this->assertSame( 'MB', $record->method ); // @phpstan-ignore-line property.notFound
		$this->assertSame( 'PENDING', $record->status ); // @phpstan-ignore-line property.notFound
		$this->assertSame( '11990', $record->entity ); // @phpstan-ignore-line property.notFound -- from multibanco-reference-valid.json
		$this->assertSame( '000191905', $record->reference ); // @phpstan-ignore-line property.notFound
		$this->assertNotNull( $record->expires_at ); // @phpstan-ignore-line property.notFound
		$this->assertSame( self::GATEWAY_KEY, $record->gateway_key ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * The stored request id is the reference API's own RequestId — settle_payment()'s idempotency
	 * key and the value the callback will carry back.
	 */
	public function test_stores_ifthenpays_own_request_id(): void {
		$order_intent = $this->create_multibanco_order_intent();

		IfthenpayLpPaymentProcessor::process_payments_for_order_intent( array(), $order_intent );

		$record = IfthenpayLpTransactionRepository::find_by_token( $order_intent->intent_key );
		$this->assertSame( 'B8kWFoPQ3b6lyjYeETa4', $record->request_id ); // @phpstan-ignore-line property.notFound -- from multibanco-reference-valid.json
	}

	/**
	 * Multibanco not checked in Payment Methods: the method is never offered at checkout at all —
	 * the filter is a no-op, not an error, matching every other unhandled method/processor combo.
	 */
	public function test_not_offered_when_multibanco_is_not_enabled_in_settings(): void {
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_payment_methods_configuration', array( 'MBWAY' ) );

		$order_intent = $this->create_multibanco_order_intent();
		$result       = IfthenpayLpPaymentProcessor::process_payments_for_order_intent( array( 'sentinel' => true ), $order_intent );

		$this->assertSame( array( 'sentinel' => true ), $result );
		$this->assertNull( IfthenpayLpTransactionRepository::find_by_token( $order_intent->intent_key ) );
	}

	/**
	 * A gateway with no MB account at all: same as above, not offered — proven with a gateway key
	 * that exists in the dataset but carries no Multibanco account (LEGACY-GATEWAY has one in the
	 * fixture; pointing at a gateway key the dataset doesn't recognise at all proves the same gate).
	 */
	public function test_not_offered_when_gateway_key_has_no_mb_account(): void {
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_gateway_key', 'GATEWAY-WITH-NO-ACCOUNTS' );

		$order_intent = $this->create_multibanco_order_intent();
		$result       = IfthenpayLpPaymentProcessor::process_payments_for_order_intent( array( 'sentinel' => true ), $order_intent );

		$this->assertSame( array( 'sentinel' => true ), $result );
		$this->assertNull( IfthenpayLpTransactionRepository::find_by_token( $order_intent->intent_key ) );
	}

	/**
	 * A rejected reference request (not merely unreachable) is a checkout error the customer can
	 * act on: pick another method. The intent gets an error and nothing is
	 * stored — no reference means nothing to settle later.
	 */
	public function test_reference_api_rejection_adds_an_intent_error_and_stores_nothing(): void {
		// Priority 20, after setUp()'s own mock (priority 10) — both match the same
		// 'multibanco/reference' URL and neither consults the incoming $preempt, so whichever runs
		// last wins; this one needs to be that one.
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, 'multibanco/reference' ) ) {
					return ifthenpay_lp_mock_response( 200, ifthenpay_lp_fixture( 'multibanco-reference-invalid-amount.json' ) );
				}
				return $preempt;
			},
			20,
			3
		);

		$order_intent = $this->create_multibanco_order_intent();
		$result       = IfthenpayLpPaymentProcessor::process_payments_for_order_intent( array(), $order_intent );

		$this->assertSame( LATEPOINT_STATUS_ERROR, $result['status'] );
		$this->assertNotFalse( $order_intent->get_error() );
		$this->assertNull( IfthenpayLpTransactionRepository::find_by_token( $order_intent->intent_key ) );
	}

	/**
	 * Selecting Multibanco for a direct invoice payment (TRANSACTION intent, not ORDER) is safely
	 * excluded — a no-op, the same as any other unhandled method/processor combination.
	 * OsPaymentsHelper::should_processor_handle_payment_for_transaction_intent() checks the method
	 * under LATEPOINT_PAYMENT_TIME_NOW specifically, and Multibanco is (correctly) registered under
	 * LATEPOINT_PAYMENT_TIME_LATER (T-07) — so this add-on's own explicit
	 * process_payment_for_transaction_intent() guard for 'ifthenpay_multibanco' (see its own
	 * docblock) can never actually be reached through the real filter chain today. It stays as a
	 * defensive, self-documenting safety net rather than dead code to delete: nothing guarantees
	 * every LatePoint version in this add-on's declared support range gates identically.
	 */
	public function test_multibanco_on_a_transaction_intent_is_not_offered(): void {
		$customer             = new OsCustomerModel();
		$customer->first_name = 'Test';
		$customer->last_name  = 'Customer';
		$customer->email      = 'ifthenpay-lp-deferred-txn-' . wp_generate_password( 8, false ) . '@example.com';
		$customer->save();

		$transaction_intent                = new OsTransactionIntentModel();
		$transaction_intent->order_id      = 1; // Presence-validated only; nothing here joins out to a real order.
		$transaction_intent->customer_id   = $customer->id;
		$transaction_intent->charge_amount = '25.00';
		$transaction_intent->payment_data  = wp_json_encode(
			array(
				'processor' => 'ifthenpay',
				'method'    => 'ifthenpay_multibanco',
				'time'      => LATEPOINT_PAYMENT_TIME_LATER,
				'portion'   => LATEPOINT_PAYMENT_PORTION_FULL,
			)
		);
		$transaction_intent->save();

		$result = IfthenpayLpPaymentProcessor::process_payment_for_transaction_intent( array( 'sentinel' => true ), $transaction_intent );

		$this->assertSame( array( 'sentinel' => true ), $result );
		$this->assertFalse( $transaction_intent->get_error() );
	}
}
