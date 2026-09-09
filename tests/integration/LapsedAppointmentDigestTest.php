<?php
/**
 * Proves IfthenpayLpLapsedAppointmentDigest — the daily cron that emails each agent about their own
 * bookings whose appointment time has passed while the order is still not fully paid, without
 * changing the booking's own status.
 *
 * @package ifthenpay-payments-for-latepoint
 */

// phpcs:ignore Squiz.Commenting.FileComment.Missing -- docblock above is the file comment; the sniff misclassifies it when a require is the first statement.
require_once __DIR__ . '/../support/class-latepoint-order-fixture.php';

/**
 * Lapsed appointment digest proof.
 */
class LapsedAppointmentDigestTest extends WP_UnitTestCase {

	/**
	 * Every email wp_mail() was asked to send this test, captured via the same filter WordPress
	 * itself uses to short-circuit delivery — nothing is actually sent.
	 *
	 * @var array<int,array{to:string,subject:string,message:string}>
	 */
	private array $sent_emails = array();

	/**
	 * A real agent, shared by every booking seeded in one test (so "two bookings, same agent" is
	 * true by construction) — the shared order fixture uses a plausible-looking but non-existent
	 * agent_id (1), fine for the settlement tests it was built for, which never join out to the
	 * agents table, but this digest does.
	 *
	 * @var OsAgentModel
	 */
	private OsAgentModel $agent;

	/**
	 * Intercepts every outgoing email instead of letting wp_mail() attempt real delivery, and
	 * creates this test's own real agent.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->sent_emails = array();

		$this->agent             = new OsAgentModel();
		$this->agent->first_name = 'Test';
		$this->agent->last_name  = 'Agent';
		$this->agent->email      = 'ifthenpay-lp-digest-agent-' . wp_generate_password( 8, false ) . '@example.com';
		$this->agent->save();

		add_filter(
			'pre_wp_mail',
			function ( $short_circuit, $atts ) {
				$this->sent_emails[] = array(
					'to'      => is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to'],
					'subject' => $atts['subject'],
					'message' => $atts['message'],
				);
				return true; // Short-circuits wp_mail() — no real delivery attempted.
			},
			10,
			2
		);
	}

	/**
	 * A real order+booking fixture, with the booking's own start_datetime_utc set directly to a
	 * given point in time — the shared fixture only sets start_date/start_time (a real checkout's
	 * own booking-save path computes start_datetime_utc separately; this test needs that column
	 * populated directly rather than exercising that whole path again) — pointed at this test's own
	 * real agent, not the shared fixture's fake one, and with a real deferred-pending transaction
	 * row behind it: find_lapsed_booking_ids() is keyed off that row now, not the booking's own
	 * status (a real deferred checkout never sets LATEPOINT_BOOKING_STATUS_PAYMENT_PENDING — see
	 * IfthenpayLpLapsedAppointmentDigest's own docblock), so a fixture with no transaction row
	 * behind it would never be found, regardless of booking status.
	 *
	 * @phpstan-return object{customer: OsCustomerModel, order_intent: OsOrderIntentModel, order: OsOrderModel, order_item: OsOrderItemModel, booking: OsBookingModel, invoice: OsInvoiceModel}
	 *
	 * @param string $start_datetime_utc MySQL datetime.
	 * @param string $payment_status     The order's own payment_status.
	 */
	private function seed_booking_at( string $start_datetime_utc, string $payment_status = LATEPOINT_ORDER_PAYMENT_STATUS_NOT_PAID ): object {
		$fixture = ifthenpay_lp_create_order_fixture();
		$fixture->booking->update_attributes(
			array(
				'start_datetime_utc' => $start_datetime_utc,
				'agent_id'           => $this->agent->id,
			)
		);
		$fixture->order->update_attributes( array( 'payment_status' => $payment_status ) );
		ifthenpay_lp_insert_pending_transaction_row( $fixture, 'REQ-DIGEST-' . $fixture->order->id );

		return $fixture;
	}

	/**
	 * A lapsed, still-unpaid booking produces exactly one email to its own agent, with a subject
	 * and body that never blame the customer, and the booking's own status is untouched.
	 */
	public function test_lapsed_unpaid_booking_produces_one_neutral_email_to_its_agent(): void {
		$fixture = $this->seed_booking_at( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		IfthenpayLpLapsedAppointmentDigest::run();

		$this->assertCount( 1, $this->sent_emails );

		$agent = new OsAgentModel( (int) $fixture->booking->agent_id );
		$this->assertSame( $agent->email, $this->sent_emails[0]['to'] );

		$subject_and_body = $this->sent_emails[0]['subject'] . ' ' . $this->sent_emails[0]['message'];
		$this->assertStringNotContainsStringIgnoringCase( 'did not pay', $subject_and_body );
		$this->assertStringNotContainsStringIgnoringCase( "didn't pay", $subject_and_body );

		$booking = new OsBookingModel( $fixture->booking->id );
		$this->assertSame( LATEPOINT_BOOKING_STATUS_PAYMENT_PENDING, $booking->status );
	}

	/**
	 * The email line carries the customer's own contact, the amount, and the confirmation code —
	 * enough to act on without opening the backoffice.
	 */
	public function test_email_line_carries_enough_to_act_on(): void {
		$fixture = $this->seed_booking_at( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		IfthenpayLpLapsedAppointmentDigest::run();

		$body = $this->sent_emails[0]['message'];
		$this->assertStringContainsString( $fixture->customer->email, $body );
		$this->assertStringContainsString( $fixture->order->confirmation_code, $body );
	}

	/**
	 * A booking whose appointment is still in the future is not lapsed — no email.
	 */
	public function test_future_booking_is_not_included(): void {
		$this->seed_booking_at( gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );

		IfthenpayLpLapsedAppointmentDigest::run();

		$this->assertCount( 0, $this->sent_emails );
	}

	/**
	 * A lapsed booking whose order is already fully paid is not lapsed in any sense this feature
	 * cares about — no email.
	 */
	public function test_fully_paid_booking_is_not_included(): void {
		$this->seed_booking_at(
			gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			LATEPOINT_ORDER_PAYMENT_STATUS_FULLY_PAID
		);

		IfthenpayLpLapsedAppointmentDigest::run();

		$this->assertCount( 0, $this->sent_emails );
	}

	/**
	 * A booking already cancelled (e.g. by the expiry sweep) is not lapsed in this feature's own
	 * sense — the sweep already handled it, and it no longer holds the calendar.
	 */
	public function test_cancelled_booking_is_not_included(): void {
		$fixture = $this->seed_booking_at( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		OsBookingHelper::change_booking_status( $fixture->booking->id, LATEPOINT_BOOKING_STATUS_CANCELLED );

		IfthenpayLpLapsedAppointmentDigest::run();

		$this->assertCount( 0, $this->sent_emails );
	}

	/**
	 * Two lapsed bookings for the same agent produce one email with two lines, not two emails —
	 * a digest, not a per-booking notification.
	 */
	public function test_two_lapsed_bookings_for_the_same_agent_produce_one_email_with_two_lines(): void {
		$first  = $this->seed_booking_at( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		$second = $this->seed_booking_at( gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) );
		// Both fixtures create their own agent (id 1, per the shared fixture) — same agent by
		// construction, proving the grouping rather than needing to force it.
		$this->assertSame( (int) $first->booking->agent_id, (int) $second->booking->agent_id );

		IfthenpayLpLapsedAppointmentDigest::run();

		$this->assertCount( 1, $this->sent_emails );
		$this->assertStringContainsString( $first->customer->email, $this->sent_emails[0]['message'] );
		$this->assertStringContainsString( $second->customer->email, $this->sent_emails[0]['message'] );
	}
}
