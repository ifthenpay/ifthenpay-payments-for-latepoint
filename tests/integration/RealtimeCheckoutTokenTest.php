<?php
/**
 * Proves OsPaymentsIfthenpayCheckoutController::send_ifthenpay_options() mints its own,
 * attempt-scoped token — never LatePoint's own order_intent->intent_key — for exactly the reason
 * that distinction matters: LatePoint reuses the identical order_intent row (same id, same
 * intent_key) across every checkout attempt on the same cart cookie until it actually converts
 * (OsOrderIntentHelper::create_or_update_order_intent()'s own reuse path, verified against core).
 * A customer who pays a realtime method, loses their connection before the booking form submits,
 * and retries — reusing that same intent_key — used to mint a second live Pay By Link under the
 * exact same local token as the first, silently failing to record it at all
 * (IfthenpayLpTransactionRepository::insert()'s own UNIQUE(token) constraint, whose return value
 * this controller never checked) — a real second charge at ifthenpay with zero local trace.
 *
 * @package ifthenpay-payments-for-latepoint
 */

// phpcs:ignore Squiz.Commenting.FileComment.Missing -- docblock above is the file comment; the sniff misclassifies it when a require is the first statement.
require_once __DIR__ . '/../support/class-ifthenpay-lp-testable-checkout-controller.php';
require_once __DIR__ . '/../support/class-latepoint-order-fixture.php';
require_once __DIR__ . '/../support/ifthenpay-http-fixtures.php';

/**
 * Realtime checkout retry proof.
 */
class RealtimeCheckoutTokenTest extends WP_UnitTestCase {

	private const BACKOFFICE_KEY = '1234-5678-9012-3456';
	private const GATEWAY_KEY    = 'MODERN-GATEWAY'; // Matches tests/fixtures/ifthenpay/gateway-dataset.json, which has an MBWAY account.

	/**
	 * Mocks the ifthenpay HTTP layer, then seeds the settings a working MBWAY checkout needs.
	 */
	protected function setUp(): void {
		parent::setUp();
		ifthenpay_lp_reset_method_catalog_cache();

		add_filter( 'pre_http_request', array( $this, 'mock_ifthenpay_http' ), 10, 3 );

		OsSettingsHelper::save_setting_by_name( 'enable_payment_processor_ifthenpay', LATEPOINT_VALUE_ON );
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_backoffice_key', self::BACKOFFICE_KEY );
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_gateway_key', self::GATEWAY_KEY );
		OsSettingsHelper::save_setting_by_name( 'ifthenpay_payment_methods_configuration', array( 'MBWAY' ) );
	}

	/**
	 * Answers every ifthenpay HTTP call this test needs, branching on URL — same shape as
	 * DeferredCheckoutTest's own mock, plus `gateway/pinpay` for Pay By Link creation.
	 *
	 * @param mixed               $preempt As passed by the pre_http_request filter; unused.
	 * @param array<string,mixed> $args    As passed by the pre_http_request filter; unused.
	 * @param string              $url     The request URL — determines which fixture to answer with.
	 * @return array{response: array{code:int,message:string}, body:string, headers: array<empty>}|mixed
	 */
	public function mock_ifthenpay_http( $preempt, $args, $url ) {
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
		if ( false !== strpos( $url, 'gateway/pinpay' ) ) {
			return ifthenpay_lp_mock_response( 200, ifthenpay_lp_fixture( 'pay-by-link-valid.json' ) );
		}

		return $preempt;
	}

	/**
	 * Invokes the private send_ifthenpay_options() directly via Reflection — same pattern
	 * RealtimePollingTest.php uses for this controller, so no real HTTP/AJAX round trip is needed
	 * and $this->params (which OsController::__construct() would read from $_POST/$_GET) is never
	 * touched at all.
	 *
	 * @param OsOrderIntentModel $intent The order intent to send Pay By Link options for.
	 * @param string             $amount How much to charge, formatted.
	 * @return array<string,mixed> The captured send_json() payload.
	 */
	private function send_ifthenpay_options( OsOrderIntentModel $intent, string $amount ): array {
		$controller = ( new ReflectionClass( IfthenpayLpTestableCheckoutController::class ) )->newInstanceWithoutConstructor();
		$method     = new ReflectionMethod( IfthenpayLpTestableCheckoutController::class, 'send_ifthenpay_options' );
		$method->setAccessible( true );
		$method->invoke( $controller, $intent, $amount );

		return $controller->captured;
	}

	/**
	 * A real, saved order intent — customer_id is the only presence-validated field
	 * (OsOrderIntentModel::properties_to_validate()), so this is the minimum that actually saves
	 * (and therefore actually generates a real intent_key, in before_create()).
	 */
	private function create_order_intent(): OsOrderIntentModel {
		$customer             = new OsCustomerModel();
		$customer->first_name = 'Test';
		$customer->last_name  = 'Customer';
		$customer->email      = 'ifthenpay-lp-realtime-retry-' . wp_generate_password( 8, false ) . '@example.com';
		$customer->save();

		$intent              = new OsOrderIntentModel();
		$intent->customer_id = $customer->id;
		$intent->save();

		return $intent;
	}

	/**
	 * Two calls for the same order_intent — the exact shape of a customer retrying after their
	 * first payment's browser session was lost before the booking form submitted, on a cart cookie
	 * LatePoint hasn't cleared, so create_or_update_order_intent() would reuse this identical row
	 * (same id, same intent_key) for the retry. Both calls must succeed, each producing its own,
	 * independently findable row — proving the second no longer silently fails to record.
	 */
	public function test_two_attempts_on_the_same_order_intent_produce_two_distinct_rows(): void {
		$intent = $this->create_order_intent();
		$this->assertNotEmpty( $intent->intent_key );

		$first  = $this->send_ifthenpay_options( $intent, '25.00' );
		$second = $this->send_ifthenpay_options( $intent, '25.00' );

		$this->assertSame( LATEPOINT_STATUS_SUCCESS, $first['status'] );
		$this->assertSame( LATEPOINT_STATUS_SUCCESS, $second['status'] );

		$this->assertNotSame( $first['token'], $second['token'] );
		$this->assertNotSame( $intent->intent_key, $first['token'] );
		$this->assertNotSame( $intent->intent_key, $second['token'] );

		$first_record  = IfthenpayLpTransactionRepository::find_by_token( $first['token'] );
		$second_record = IfthenpayLpTransactionRepository::find_by_token( $second['token'] );

		$this->assertNotNull( $first_record );
		$this->assertNotNull( $second_record );
		$this->assertSame( (string) $intent->id, (string) $first_record->intent_id ); // @phpstan-ignore-line property.notFound
		$this->assertSame( (string) $intent->id, (string) $second_record->intent_id ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * The first attempt's own row is untouched by the second — proving the fix isn't merely "the
	 * second call no longer errors", but that it genuinely never collides with (or overwrites) the
	 * first: if the first payment already settled PAID, it must still read PAID after a retry.
	 */
	public function test_the_first_attempts_settlement_survives_a_retry(): void {
		$intent = $this->create_order_intent();

		$first = $this->send_ifthenpay_options( $intent, '25.00' );
		IfthenpayLpTransactionRepository::update_status( $first['token'], 'PAID' );

		$this->send_ifthenpay_options( $intent, '25.00' );

		$first_record = IfthenpayLpTransactionRepository::find_by_token( $first['token'] );
		$this->assertSame( 'PAID', $first_record->status ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * A guest order-booking checkout — customer_id empty on the intent — snapshots the raw contact
	 * fields already sitting on OsStepsHelper::$customer_object (the same state
	 * get_order_ifthenpay_options() leaves it in via set_required_objects(), before this add-on's
	 * own controller ever runs), plus a booking summary naming the real service in the cart. Never
	 * re-derived later from the order_intent — see build_unclaimed_snapshot()'s own docblock.
	 */
	public function test_guest_order_checkout_snapshots_raw_contact_and_booking_info(): void {
		$service           = new OsServiceModel();
		$service->name     = 'Deep Tissue Massage';
		$service->duration = 60;
		$service->save();

		OsStepsHelper::$customer_object             = new OsCustomerModel();
		OsStepsHelper::$customer_object->first_name = 'Jane';
		OsStepsHelper::$customer_object->last_name  = 'Doe';
		OsStepsHelper::$customer_object->email      = 'jane.doe@example.test';
		OsStepsHelper::$customer_object->phone      = '+351911111111';

		// order_intents.customer_id is NOT NULL at the DB level, so a genuinely customer_id-less row
		// can never actually be saved — real code never hits that either: an unsaved-with-empty-
		// customer_id OsOrderIntentModel (id=0) is exactly what a guest checkout holds in memory
		// before it's ever persisted. Save with a throwaway customer_id first only to obtain a real
		// id (our own intent_id column is itself NOT NULL), then blank the in-memory property — the
		// same "empty at the moment send_ifthenpay_options() reads it" state either way, since
		// build_unclaimed_snapshot() only ever reads the live property, never re-queries the row.
		$placeholder_customer             = new OsCustomerModel();
		$placeholder_customer->first_name = 'Placeholder';
		$placeholder_customer->last_name  = 'Customer';
		$placeholder_customer->email      = 'ifthenpay-lp-placeholder-' . wp_generate_password( 8, false ) . '@example.com';
		$placeholder_customer->save();

		$intent                  = new OsOrderIntentModel();
		$intent->customer_id     = $placeholder_customer->id;
		$intent->cart_items_data = wp_json_encode(
			array(
				array(
					'variant'   => LATEPOINT_ITEM_VARIANT_BOOKING,
					'item_data' => array(
						'service_id' => $service->id,
						'start_date' => '2026-10-01',
						'start_time' => 600, // 10:00.
					),
				),
			)
		);
		$intent->save();
		$intent->customer_id = null;

		$result = $this->send_ifthenpay_options( $intent, '25.00' );
		$record = IfthenpayLpTransactionRepository::find_by_token( $result['token'] );
		$data   = IfthenpayLpTransactionRepository::decode_method_data( $record );

		$this->assertArrayNotHasKey( 'customer_id', $data );
		$this->assertSame( 'Jane Doe', $data['customer_name'] );
		$this->assertSame( 'jane.doe@example.test', $data['customer_email'] );
		$this->assertSame( '+351911111111', $data['customer_phone'] );
		$this->assertStringContainsString( 'Deep Tissue Massage', $data['booking_summary'] );
		$this->assertStringContainsString( '2026-10-01', $data['booking_summary'] );
	}

	/**
	 * An already-logged-in customer (or a guest whose customer_id already resolved to a real,
	 * saved row) snapshots only customer_id — no need for the raw-field fallback, and no reason to
	 * duplicate data a real OsCustomerModel row already holds.
	 */
	public function test_logged_in_customer_snapshots_only_customer_id(): void {
		$intent = $this->create_order_intent();

		$result = $this->send_ifthenpay_options( $intent, '25.00' );
		$record = IfthenpayLpTransactionRepository::find_by_token( $result['token'] );
		$data   = IfthenpayLpTransactionRepository::decode_method_data( $record );

		$this->assertSame( $intent->customer_id, $data['customer_id'] );
		$this->assertArrayNotHasKey( 'customer_name', $data );
	}

	/**
	 * The invoice/transaction path (get_transaction_ifthenpay_options(), a direct payment against an
	 * existing invoice, not a fresh booking) always has a real customer_id — and has no booking to
	 * summarize at all, since nothing new is being booked.
	 */
	public function test_transaction_intent_path_snapshots_customer_id_only_no_booking_summary(): void {
		$fixture = ifthenpay_lp_create_order_fixture();

		$intent                = new OsTransactionIntentModel();
		$intent->order_id      = $fixture->order->id;
		$intent->customer_id   = $fixture->customer->id;
		$intent->charge_amount = '25.00';
		$intent->save();

		$controller = ( new ReflectionClass( IfthenpayLpTestableCheckoutController::class ) )->newInstanceWithoutConstructor();
		$method     = new ReflectionMethod( IfthenpayLpTestableCheckoutController::class, 'send_ifthenpay_options' );
		$method->setAccessible( true );
		$method->invoke( $controller, $intent, '25.00' );

		$record = IfthenpayLpTransactionRepository::find_by_token( $controller->captured['token'] );
		$data   = IfthenpayLpTransactionRepository::decode_method_data( $record );

		$this->assertSame( $fixture->customer->id, $data['customer_id'] );
		$this->assertArrayNotHasKey( 'booking_summary', $data );
	}
}
