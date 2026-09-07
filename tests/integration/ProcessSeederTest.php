<?php
/**
 * Proves IfthenpayLpProcessSeeder — the on-activation seed of a `transaction_created` LatePoint
 * process, closing the gap where nothing notifies a customer once a deferred (Multibanco/Payshop)
 * reference is actually paid — plus the append_reference_to_email_content() extension that lets
 * that seeded email's own reference box resolve against a `transaction` data object.
 *
 * @package ifthenpay-payments-for-latepoint
 */

// phpcs:ignore Squiz.Commenting.FileComment.Missing -- docblock above is the file comment; the sniff misclassifies it when a require is the first statement.
require_once __DIR__ . '/../support/class-latepoint-order-fixture.php';

/**
 * Process seeding + transaction-email-content proof.
 */
class ProcessSeederTest extends WP_UnitTestCase {

	/**
	 * The main plugin file's own init() (its in-place-update catch-up) now also seeds this process
	 * on every WordPress bootstrap, not just on_activate() — including the one-time bootstrap this
	 * whole test suite itself runs before any test method's own transaction starts (see
	 * tests/bootstrap-integration.php), so a process seeded there already exists by the time any
	 * test here runs. Deleting it up front gives every test in this file its own clean slate,
	 * independent of that global bootstrap state; WP_UnitTestCase's own per-test rollback restores
	 * it afterward, so the next test's own setUp() finds (and deletes) it again.
	 */
	public function setUp(): void {
		parent::setUp();

		global $wpdb;
		// Every process for this event, not just this add-on's own row by name — several tests here
		// seed a *foreign*-named one on purpose to prove the conflict-detection path, so a clean
		// slate has to cover the whole event, not one specific name.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test-only cleanup, not a request-path query; LATEPOINT_TABLE_PROCESSES has no user-controlled part, the %s placeholder covers the only real value.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM `' . LATEPOINT_TABLE_PROCESSES . '` WHERE event_type = %s', 'transaction_created' ) );
	}

	/**
	 * A process under a name this add-on doesn't own — stands in for a merchant's own, hand-built
	 * "thanks for paying" workflow, however they happened to name it.
	 *
	 * @param string $name  Anything other than IfthenpayLpProcessSeeder's own process name.
	 * @param string $status LATEPOINT_STATUS_ACTIVE or LATEPOINT_STATUS_DISABLED.
	 */
	private function seed_foreign_process( string $name = 'My Own Thank You Email', string $status = LATEPOINT_STATUS_ACTIVE ): void {
		$process               = new OsProcessModel();
		$process->event_type   = 'transaction_created';
		$process->name         = $name;
		$process->status       = $status;
		$process->actions_json = wp_json_encode( array() );
		$process->save();
	}

	/**
	 * A real, saved OsTransactionModel — matches what IfthenpayLpSettlement::apply_state_change()
	 * itself creates on settlement, not a stub.
	 *
	 * @param object $fixture As returned by ifthenpay_lp_create_order_fixture().
	 */
	private function create_transaction( object $fixture ): OsTransactionModel {
		$transaction                  = new OsTransactionModel();
		$transaction->token           = 'TOK-PROC-SEED-' . $fixture->order->id;
		$transaction->order_id        = $fixture->order->id;
		$transaction->customer_id     = $fixture->customer->id;
		$transaction->processor       = 'ifthenpay';
		$transaction->amount          = $fixture->invoice->charge_amount;
		$transaction->payment_method  = 'MB';
		$transaction->payment_portion = LATEPOINT_PAYMENT_PORTION_FULL;
		$transaction->kind            = LATEPOINT_TRANSACTION_KIND_CAPTURE;
		$transaction->status          = LATEPOINT_TRANSACTION_STATUS_SUCCEEDED;
		$transaction->save();

		return $transaction;
	}

	/**
	 * The one row this seeder owns, found the same way IfthenpayLpProcessSeeder's own
	 * find_own_process() matches — by (event_type, name).
	 */
	private function find_seeded_process(): ?OsProcessModel {
		$process = ( new OsProcessModel() )->where(
			array(
				'event_type' => 'transaction_created',
				'name'       => 'Payment Received Notification',
			)
		)->set_limit( 1 )->get_results_as_models();

		return ( $process && ! $process->is_new_record() ) ? $process : null;
	}

	/**
	 * First call creates the process, active, since nothing else exists for this event yet; a
	 * second call is a no-op, not a duplicate — the same idempotency guarantee LatePoint's own
	 * seed_initial_data() relies on.
	 */
	public function test_seed_creates_the_process_once(): void {
		$this->assertNull( $this->find_seeded_process() );

		$this->assertSame( 'created', IfthenpayLpProcessSeeder::seed_transaction_created_process() );

		$process = $this->find_seeded_process();
		$this->assertNotNull( $process );
		$this->assertSame( 'transaction_created', $process->event_type );
		$this->assertSame( LATEPOINT_STATUS_ACTIVE, $process->status );
		$this->assertNotEmpty( $process->actions_json );

		$this->assertSame( 'already_exists', IfthenpayLpProcessSeeder::seed_transaction_created_process() );

		global $wpdb;
		// Test-only count, not a request-path query; LATEPOINT_TABLE_PROCESSES has no user-controlled
		// part, and the %s placeholders cover every real value.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM `' . LATEPOINT_TABLE_PROCESSES . '` WHERE event_type = %s AND name = %s',
				'transaction_created',
				'Payment Received Notification'
			)
		);
		// phpcs:enable
		$this->assertSame( 1, $count );
	}

	/**
	 * A merchant who already has their own process for this event, under a different name, never
	 * gets a second *active* one from us — the real risk this whole design exists to avoid: two
	 * live payment-confirmation emails firing off one settlement, unannounced. Ours still gets
	 * created, so it's there to review/compare in Automation → Workflows, but disabled; the
	 * merchant's own row is left completely untouched.
	 */
	public function test_seed_creates_a_disabled_process_when_a_foreign_one_already_exists(): void {
		$this->seed_foreign_process( 'My Own Thank You Email', LATEPOINT_STATUS_ACTIVE );

		$this->assertSame( 'created_disabled', IfthenpayLpProcessSeeder::seed_transaction_created_process() );

		$ours = $this->find_seeded_process();
		$this->assertNotNull( $ours );
		$this->assertSame( LATEPOINT_STATUS_DISABLED, $ours->status );

		$foreign = ( new OsProcessModel() )->where(
			array(
				'event_type' => 'transaction_created',
				'name'       => 'My Own Thank You Email',
			)
		)->set_limit( 1 )->get_results_as_models();
		$this->assertNotNull( $foreign );
		$this->assertFalse( $foreign->is_new_record() );
		$this->assertSame( LATEPOINT_STATUS_ACTIVE, $foreign->status );
	}

	/**
	 * A foreign process that's itself disabled (a merchant who built one, then turned it off) still
	 * counts — this isn't about whether the merchant's own automation is currently live, it's about
	 * respecting that they've already made a deliberate choice for this event at all.
	 */
	public function test_seed_creates_a_disabled_process_even_when_the_foreign_one_is_disabled(): void {
		$this->seed_foreign_process( 'My Own Thank You Email', LATEPOINT_STATUS_DISABLED );

		$this->assertSame( 'created_disabled', IfthenpayLpProcessSeeder::seed_transaction_created_process() );
		$this->assertSame( LATEPOINT_STATUS_DISABLED, $this->find_seeded_process()->status );
	}

	/**
	 * Once our own row exists — active or disabled, whichever path created it — a later call is
	 * always a no-op, never a second row, regardless of how many foreign processes are also present.
	 */
	public function test_seed_is_idempotent_after_creating_a_disabled_process(): void {
		$this->seed_foreign_process();
		IfthenpayLpProcessSeeder::seed_transaction_created_process();

		$this->assertSame( 'already_exists', IfthenpayLpProcessSeeder::seed_transaction_created_process() );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test-only count, not a request-path query; LATEPOINT_TABLE_PROCESSES has no user-controlled part, the %s placeholder covers the only real value.
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . LATEPOINT_TABLE_PROCESSES . '` WHERE event_type = %s', 'transaction_created' ) );
		// Our own row plus the one foreign one seeded above — never a second copy of ours.
		$this->assertSame( 2, $count );
	}

	/**
	 * The seeded action's own settings carry a customer-only recipient — no agent action, since
	 * generate_replacement_vars_from_transaction() never populates an `agent` merge-tag context (an
	 * order can span bookings with different agents) — and content that actually carries this
	 * plugin's own copy (transaction_created.html), not just *some* non-empty string.
	 * OsEmailHelper::get_email_layout() itself calls WP_Filesystem() internally and can fail
	 * depending on the request context it runs in (verified live: works from a real admin session
	 * or WP-CLI, silently returns '' from certain unauthenticated request contexts) — IfthenpayLpProcessSeeder::fallback_layout()
	 * covers that case, but either way the customer's own content must survive, not just an
	 * empty-but-truthy layout wrapper.
	 */
	public function test_seeded_process_has_one_customer_only_email_action(): void {
		IfthenpayLpProcessSeeder::seed_transaction_created_process();

		$process = $this->find_seeded_process();
		$actions = json_decode( (string) $process->actions_json, true );
		$items   = $actions[0]['items'];

		$this->assertCount( 1, $items );
		$this->assertSame( 'send_email', $items[0]['settings']['type'] );
		$this->assertStringContainsString( '{{customer_email}}', $items[0]['settings']['settings']['to_email'] );
		$this->assertStringNotContainsString( '{{agent_email}}', $items[0]['settings']['settings']['to_email'] );
		$this->assertStringContainsString( "We've received your payment", $items[0]['settings']['settings']['content'] );
		$this->assertStringContainsString( '{{order_confirmation_code}}', $items[0]['settings']['settings']['content'] );
	}

	/**
	 * The append_reference_to_email_content() filter — run against the seeded process' own email
	 * at send time — resolves a `transaction` data object to the order behind it, the same way it
	 * already resolves `order`/`booking` ones, so the reference box (here "Paid.", since the row
	 * is already settled) reaches this email too.
	 */
	public function test_email_filter_resolves_transaction_data_object_to_paid_reference(): void {
		$fixture     = ifthenpay_lp_create_order_fixture();
		$transaction = $this->create_transaction( $fixture );
		ifthenpay_lp_insert_pending_transaction_row( $fixture, 'REQ-PROC-SEED-' . $fixture->order->id, array( 'status' => 'PAID' ) );

		global $LATEPOINT_ADDON_PAYMENTS_IFTHENPAY;

		$action                        = new \LatePoint\Misc\ProcessAction();
		$action->type                  = 'send_email';
		$action->selected_data_objects = array(
			array(
				'model' => 'transaction',
				'id'    => $transaction->id,
			),
		);
		$action->prepared_data_for_run = array( 'content' => '<p>Thanks for your payment.</p>' );

		$result = $LATEPOINT_ADDON_PAYMENTS_IFTHENPAY->append_reference_to_email_content( $action );

		$this->assertStringContainsString( 'Thanks for your payment.', $result->prepared_data_for_run['content'] );
		$this->assertStringContainsString( 'Paid.', $result->prepared_data_for_run['content'] );
	}
}
