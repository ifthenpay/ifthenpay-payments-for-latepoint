<?php
/**
 * Proves IfthenpayLpReferenceDisplay — the shared lookup+render behind every customer-facing
 * surface for a deferred payment's own reference — plus the email-content injection hook that
 * reuses it.
 *
 * @package ifthenpay-payments-for-latepoint
 */

// phpcs:ignore Squiz.Commenting.FileComment.Missing -- docblock above is the file comment; the sniff misclassifies it when a require is the first statement.
require_once __DIR__ . '/../support/class-latepoint-order-fixture.php';

/**
 * Reference display proof.
 */
class ReferenceDisplayTest extends WP_UnitTestCase {

	/**
	 * A real, real-looking Multibanco row for a converted order.
	 *
	 * @phpstan-param object{customer: OsCustomerModel, order_intent: OsOrderIntentModel, order: OsOrderModel, order_item: OsOrderItemModel, booking: OsBookingModel, invoice: OsInvoiceModel} $fixture
	 *
	 * @param object $fixture As returned by ifthenpay_lp_create_order_fixture().
	 * @param string $status  Repository row status; defaults to still-PENDING.
	 */
	private function seed_deferred_row( object $fixture, string $status = 'PENDING' ): void {
		IfthenpayLpTransactionRepository::insert(
			array(
				'token'       => 'tok-display-' . $fixture->order->id,
				'request_id'  => 'REQ-DISPLAY-' . $fixture->order->id,
				'intent_id'   => $fixture->order_intent->id,
				'kind'        => 'deferred',
				'method'      => 'MB',
				'status'      => $status,
				'amount'      => $fixture->invoice->charge_amount,
				'gateway_key' => 'TEST-GW-KEY-0001',
				'entity'      => '11990',
				'reference'   => '123456789',
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
	}

	/**
	 * A real, real-looking Payshop row for a converted order — no entity, unlike Multibanco's.
	 *
	 * @phpstan-param object{customer: OsCustomerModel, order_intent: OsOrderIntentModel, order: OsOrderModel, order_item: OsOrderItemModel, booking: OsBookingModel, invoice: OsInvoiceModel} $fixture
	 *
	 * @param object $fixture As returned by ifthenpay_lp_create_order_fixture().
	 * @param string $status  Repository row status; defaults to still-PENDING.
	 */
	private function seed_deferred_payshop_row( object $fixture, string $status = 'PENDING' ): void {
		IfthenpayLpTransactionRepository::insert(
			array(
				'token'       => 'tok-display-' . $fixture->order->id,
				'request_id'  => 'REQ-DISPLAY-' . $fixture->order->id,
				'intent_id'   => $fixture->order_intent->id,
				'kind'        => 'deferred',
				'method'      => 'PAYSHOP',
				'status'      => $status,
				'amount'      => $fixture->invoice->charge_amount,
				'gateway_key' => 'TEST-GW-KEY-0001',
				'entity'      => null,
				'reference'   => '987654321',
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
	}

	/**
	 * Finds the deferred record for a real order.
	 */
	public function test_for_order_finds_the_deferred_record(): void {
		$fixture = ifthenpay_lp_create_order_fixture();
		$this->seed_deferred_row( $fixture );

		$record = IfthenpayLpReferenceDisplay::for_order( $fixture->order->id );

		$this->assertNotNull( $record );
		$this->assertSame( '11990', $record->entity ); // @phpstan-ignore-line property.notFound
		$this->assertSame( '123456789', $record->reference ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * An order with no deferred payment at all (a realtime one, or none) returns null — nothing to
	 * show, no error.
	 */
	public function test_for_order_returns_null_without_a_deferred_record(): void {
		$fixture = ifthenpay_lp_create_order_fixture();

		$this->assertNull( IfthenpayLpReferenceDisplay::for_order( $fixture->order->id ) );
	}

	/**
	 * Resolves to the same record via the booking's own order item → order chain.
	 */
	public function test_for_booking_resolves_via_the_order(): void {
		$fixture = ifthenpay_lp_create_order_fixture();
		$this->seed_deferred_row( $fixture );

		$record = IfthenpayLpReferenceDisplay::for_booking( $fixture->booking->id );

		$this->assertNotNull( $record );
		$this->assertSame( '123456789', $record->reference ); // @phpstan-ignore-line property.notFound
	}

	/**
	 * The rendered box shows entity, reference (grouped in 3-character chunks for readability),
	 * amount, and the token while still pending.
	 */
	public function test_render_html_shows_details_while_pending(): void {
		$fixture = ifthenpay_lp_create_order_fixture( array( 'amount' => '25.00' ) );
		$this->seed_deferred_row( $fixture );

		$record = IfthenpayLpReferenceDisplay::for_order( $fixture->order->id );
		$html   = IfthenpayLpReferenceDisplay::render_html( $record );

		$this->assertStringContainsString( '11990', $html );
		$this->assertStringContainsString( '123 456 789', $html );
		$this->assertStringContainsString( 'tok-display-' . $fixture->order->id, $html );
	}

	/**
	 * A Multibanco record's own copy — its instructions mention the Multibanco ATM/Entity, proving
	 * this isn't a single template shared with Payshop; the method itself is identified by the
	 * header badge's own logo (method_icon()), not by text.
	 */
	public function test_render_html_uses_multibancos_own_copy(): void {
		$fixture = ifthenpay_lp_create_order_fixture();
		$this->seed_deferred_row( $fixture );

		$record = IfthenpayLpReferenceDisplay::for_order( $fixture->order->id );
		$html   = IfthenpayLpReferenceDisplay::render_html( $record );

		$this->assertStringContainsString( 'alt="Multibanco"', $html );
		$this->assertStringContainsString( 'Multibanco ATM', $html );
		$this->assertStringContainsString( 'ifthenpay-reference-box-row-entity', $html );
	}

	/**
	 * A Payshop record's own copy: no Entity row at all (Payshop references stand alone), and
	 * instructions naming a Payshop agent or CTT rather than an ATM.
	 */
	public function test_render_html_shows_payshops_own_copy_without_an_entity_row(): void {
		$fixture = ifthenpay_lp_create_order_fixture( array( 'amount' => '25.00' ) );
		$this->seed_deferred_payshop_row( $fixture );

		$record = IfthenpayLpReferenceDisplay::for_order( $fixture->order->id );
		$html   = IfthenpayLpReferenceDisplay::render_html( $record );

		$this->assertStringContainsString( 'alt="Payshop"', $html );
		$this->assertStringContainsString( 'Payshop agent or CTT', $html );
		$this->assertStringContainsString( '987 654 321', $html );
		$this->assertStringContainsString( 'tok-display-' . $fixture->order->id, $html );
		$this->assertStringNotContainsString( 'ifthenpay-reference-box-row-entity', $html );
		$this->assertStringNotContainsString( 'Multibanco', $html );
	}

	/**
	 * Once paid, the box no longer exposes the reference/entity as something to act on — a
	 * customer who already paid doesn't need to be told the entity/reference again. The token
	 * stays: unlike entity/reference, it's still useful after payment (support, reconciliation).
	 * In their place, a Status/Amount row pair (paid_rows()) — the same label/value grammar the
	 * pending state's own Entity/Reference/Amount rows already use, not a separate sentence-plus-
	 * icon component. The "Powered by ifthenpay" footer stays too — an earlier version only
	 * rendered the footer in the pending branch, silently dropping the branding the moment a
	 * reference was paid; the now-irrelevant "Pay by" deadline is the only piece that's actually
	 * pending-only.
	 */
	public function test_render_html_hides_details_once_paid(): void {
		$fixture = ifthenpay_lp_create_order_fixture( array( 'amount' => '25.00' ) );
		$this->seed_deferred_row( $fixture, 'PAID' );

		$record = IfthenpayLpReferenceDisplay::for_order( $fixture->order->id );
		$html   = IfthenpayLpReferenceDisplay::render_html( $record );

		$this->assertStringNotContainsString( '123456789', $html );
		$this->assertStringContainsString( 'tok-display-' . $fixture->order->id, $html );
		$this->assertStringContainsString( 'ifthenpay-reference-box-row-status', $html );
		$this->assertStringContainsString( 'Paid', $html );
		$this->assertStringContainsString( OsMoneyHelper::format_price( '25.00', true, false ), $html );
		$this->assertStringContainsString( 'Powered by', $html );
		$this->assertStringNotContainsString( 'Pay by:', $html );
	}

	/**
	 * The email-safe render shows the same details as the browser one while still pending — as
	 * inline-styled tables matching render_html()'s own card, not this plugin's CSS classes (which
	 * no inbox would ever load), and with the reference grouped the same way render_html() groups
	 * it.
	 */
	public function test_render_email_html_shows_details_while_pending(): void {
		$fixture = ifthenpay_lp_create_order_fixture( array( 'amount' => '25.00' ) );
		$this->seed_deferred_row( $fixture );

		$record = IfthenpayLpReferenceDisplay::for_order( $fixture->order->id );
		$html   = IfthenpayLpReferenceDisplay::render_email_html( $record );

		$this->assertStringContainsString( '11990', $html );
		$this->assertStringContainsString( '123 456 789', $html );
		$this->assertStringContainsString( 'tok-display-' . $fixture->order->id, $html );
		$this->assertStringNotContainsString( 'ifthenpay-reference-box', $html );
	}

	/**
	 * The email-safe render also shows Payshop's own copy — no Entity row, and instructions
	 * naming a Payshop agent or CTT.
	 */
	public function test_render_email_html_shows_payshops_own_copy_without_an_entity_row(): void {
		$fixture = ifthenpay_lp_create_order_fixture( array( 'amount' => '25.00' ) );
		$this->seed_deferred_payshop_row( $fixture );

		$record = IfthenpayLpReferenceDisplay::for_order( $fixture->order->id );
		$html   = IfthenpayLpReferenceDisplay::render_email_html( $record );

		$this->assertStringContainsString( 'alt="Payshop"', $html );
		$this->assertStringContainsString( 'Payshop agent or CTT', $html );
		$this->assertStringContainsString( '987 654 321', $html );
		$this->assertStringContainsString( 'tok-display-' . $fixture->order->id, $html );
		$this->assertStringNotContainsString( 'Entity', $html );
		$this->assertStringNotContainsString( 'Multibanco', $html );
	}

	/**
	 * Once paid, the email-safe render also stops exposing the reference/entity, but keeps the
	 * token, shows the same Status/Amount row pair render_html() shows (see its own equivalent
	 * test), and keeps the "Powered by ifthenpay" footer.
	 */
	public function test_render_email_html_hides_details_once_paid(): void {
		$fixture = ifthenpay_lp_create_order_fixture( array( 'amount' => '25.00' ) );
		$this->seed_deferred_row( $fixture, 'PAID' );

		$record = IfthenpayLpReferenceDisplay::for_order( $fixture->order->id );
		$html   = IfthenpayLpReferenceDisplay::render_email_html( $record );

		$this->assertStringNotContainsString( '123456789', $html );
		$this->assertStringContainsString( 'tok-display-' . $fixture->order->id, $html );
		$this->assertStringContainsString( 'Paid', $html );
		$this->assertStringContainsString( OsMoneyHelper::format_price( '25.00', true, false ), $html );
		$this->assertStringContainsString( 'Powered by', $html );
		$this->assertStringNotContainsString( 'Pay by:', $html );
	}

	/**
	 * The confirmation-email filter appends the reference box to an order-related email's own
	 * already-prepared content, without touching anything else about the action.
	 *
	 * Deliberately does NOT set `$action->event` — a real ProcessAction built by LatePoint's own
	 * OsProcessJobsHelper::create_jobs_for_process() / OsProcessJobModel::get_actions() never sets
	 * it (verified live: only type/id/status/settings/prepared_data_for_run are ever assigned), so
	 * a test that sets it exercises a shape that cannot occur in production. An earlier version of
	 * both this test and the production code gated on `$action->event->type`, which passed here
	 * (because the test supplied one) while being a silent no-op against every real email, since
	 * `?? ''` against the real, always-uninitialized property evaluates to '' rather than throwing.
	 */
	public function test_email_filter_appends_reference_for_order_related_email(): void {
		$fixture = ifthenpay_lp_create_order_fixture();
		$this->seed_deferred_row( $fixture );

		global $LATEPOINT_ADDON_PAYMENTS_IFTHENPAY;

		$action                        = new \LatePoint\Misc\ProcessAction();
		$action->type                  = 'send_email';
		$action->selected_data_objects = array(
			array(
				'model' => 'order',
				'id'    => $fixture->order->id,
			),
		);
		$action->prepared_data_for_run = array( 'content' => '<p>Thanks for your booking.</p>' );

		$result = $LATEPOINT_ADDON_PAYMENTS_IFTHENPAY->append_reference_to_email_content( $action );

		$this->assertStringContainsString( 'Thanks for your booking.', $result->prepared_data_for_run['content'] );
		$this->assertStringContainsString( '123 456 789', $result->prepared_data_for_run['content'] );
		// Email clients never load this plugin's stylesheet — CSS-class markup would render as
		// bare, unstyled text (the bug this whole render_email_html() path exists to fix).
		$this->assertStringNotContainsString( 'ifthenpay-reference-box', $result->prepared_data_for_run['content'] );
	}

	/**
	 * An unrelated action type (e.g. send_sms) is left completely untouched.
	 */
	public function test_email_filter_ignores_non_email_actions(): void {
		global $LATEPOINT_ADDON_PAYMENTS_IFTHENPAY;

		$action                        = new \LatePoint\Misc\ProcessAction();
		$action->type                  = 'send_sms';
		$action->prepared_data_for_run = array( 'content' => 'Thanks!' );

		$result = $LATEPOINT_ADDON_PAYMENTS_IFTHENPAY->append_reference_to_email_content( $action );

		$this->assertSame( 'Thanks!', $result->prepared_data_for_run['content'] );
	}

	/**
	 * An email unrelated to any order/booking (e.g. a "customer_created" welcome email) is left
	 * untouched — selected_data_objects alone is enough to scope this correctly, with no need for
	 * (the never-actually-set) $action->event.
	 */
	public function test_email_filter_ignores_emails_with_no_order_or_booking_data_object(): void {
		global $LATEPOINT_ADDON_PAYMENTS_IFTHENPAY;

		$action                        = new \LatePoint\Misc\ProcessAction();
		$action->type                  = 'send_email';
		$action->selected_data_objects = array(
			array(
				'model' => 'customer',
				'id'    => 1,
			),
		);
		$action->prepared_data_for_run = array( 'content' => 'Welcome!' );

		$result = $LATEPOINT_ADDON_PAYMENTS_IFTHENPAY->append_reference_to_email_content( $action );

		$this->assertSame( 'Welcome!', $result->prepared_data_for_run['content'] );
	}
}
