<?php
/**
 * Seeds a `transaction_created` LatePoint process on activation — LatePoint's own
 * OsDatabaseHelper::seed_initial_data() seeds exactly one default process, for `booking_created`,
 * which fires at checkout before any deferred (Multibanco/Payshop) payment exists. This add-on's
 * own settlement code already fires `do_action('latepoint_transaction_created', $transaction)` on
 * actual payment (IfthenpayLpSettlement::settle_locked(), after the repository row is stamped
 * settled — see that class's own docblock), and LatePoint's own OsProcessJobsHelper wires that
 * event to any configured process — but ships no default one, so
 * nothing notifies the customer that a reference was actually paid unless the merchant hand-builds
 * a workflow. This closes that gap the same way LatePoint closes it for its own default: seed a
 * real, merchant-editable OsProcessModel row, not an ad-hoc email fired from our own code.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One seeding entry point, called from on_activate() and init(). `transaction_created` is a
 * LatePoint *core* event, not one this add-on invented — a merchant already using realtime Pay By
 * Link may well have already built their own "thanks for paying" workflow for it, under whatever
 * name they chose. Matching only on this seeder's own (event_type, name) pair, the way LatePoint's
 * own seed_initial_data() matches its own default, would miss that entirely and silently double up
 * the customer's payment-confirmation emails. So this checks for *any* existing process on the
 * event first, regardless of name, and never activates a second one on top of it — see
 * seed_transaction_created_process()'s own docblock for the three outcomes that follow from that.
 */
class IfthenpayLpProcessSeeder {

	/**
	 * The process name this seeder owns — the identity find_own_process() matches on alongside
	 * event_type, so this string must stay stable across releases (renaming it would make a future
	 * call here treat its own prior row as a foreign one instead of recognising it).
	 */
	private const PROCESS_NAME = 'Payment Received Notification';

	/**
	 * Seeds the `transaction_created` process, at most once, respecting whatever the merchant
	 * already has configured for this event:
	 *
	 * - Nothing exists for this event yet → create ours, active. Returns 'created'.
	 * - This add-on's own row already exists (any status — a merchant may have disabled it, that's
	 *   their call to keep) → no-op. Returns 'already_exists'.
	 * - A *different* process already exists for this event (the merchant's own) → still create
	 *   ours, so it's there to review/compare in Automation → Workflows, but DISABLED — never two
	 *   live payment-confirmation emails firing off one settlement. Returns 'created_disabled'.
	 *
	 * Customer-only content: OsReplacerHelper::generate_replacement_vars_from_transaction()
	 * populates `transaction`, `order`, and `customer` merge-tag contexts, but never `agent` — an
	 * order can span bookings with different agents, so there is no single natural agent recipient
	 * the way `booking_created` has one. Action-shape otherwise mirrors
	 * OsDatabaseHelper::seed_initial_data()'s own construction (latepoint/lib/helpers/database_helper.php)
	 * exactly, so this stays a process LatePoint's own Processes admin screen can show, edit, and
	 * disable like any other.
	 *
	 * @return string One of 'created', 'created_disabled', 'already_exists'.
	 */
	public static function seed_transaction_created_process(): string {
		if ( self::find_own_process() ) {
			return 'already_exists';
		}

		$has_foreign_process = self::foreign_process_exists();

		$process             = new OsProcessModel();
		$process->event_type = 'transaction_created';
		$process->name       = self::PROCESS_NAME;
		$process->status     = $has_foreign_process ? LATEPOINT_STATUS_DISABLED : LATEPOINT_STATUS_ACTIVE;

		$content = self::email_content();
		$layout  = OsEmailHelper::get_email_layout( $content );

		$action                         = array();
		$action['type']                 = 'send_email';
		$action['settings']['to_email'] = '{{customer_full_name}} <{{customer_email}}>';
		$action['settings']['subject']  = __( 'Payment Received', 'ifthenpay-payments-for-latepoint' );
		// get_email_layout() itself calls WP_Filesystem() internally (LatePoint core,
		// email_helper.php) and returns '' outright if that fails — verified live: it succeeds when
		// this seeder runs from on_activate() (a real wp-admin session) or WP-CLI, but fails when it
		// runs from init() during an unauthenticated REST API request (the ifthenpay callback can be
		// the very first request to trigger seeding on a site where this add-on was already active —
		// see the main plugin file's own init() docblock). Falling back to the raw content, still
		// fully populated, beats silently seeding a process whose email would carry no order details
		// at all, forever, until someone happens to notice and manually fix the row.
		$action['settings']['content'] = '' !== $layout ? $layout : self::fallback_layout( $content );

		$actions = array(
			\LatePoint\Misc\ProcessAction::generate_id() => $action,
		);

		$process_actions                   = OsProcessesHelper::iterate_trigger_conditions( array(), $actions );
		$process_actions[0]['time_offset'] = array();
		$process->actions_json             = wp_json_encode( $process_actions );
		$process->save();

		return $has_foreign_process ? 'created_disabled' : 'created';
	}

	/**
	 * This add-on's own previously-seeded row, in whatever status it's in now — found the same way
	 * OsProcessesHelper::check_if_process_exists() itself matches, by (event_type, name).
	 */
	private static function find_own_process(): ?OsProcessModel {
		$process = ( new OsProcessModel() )->where(
			array(
				'event_type' => 'transaction_created',
				'name'       => self::PROCESS_NAME,
			)
		)->set_limit( 1 )->get_results_as_models();

		return ( $process && ! $process->is_new_record() ) ? $process : null;
	}

	/**
	 * Whether the merchant already has their own process for this event, under any other name —
	 * checked before find_own_process() has ruled out "this is just our own row" (see the caller),
	 * so this only ever runs when it isn't.
	 */
	private static function foreign_process_exists(): bool {
		global $wpdb;
		// One-time seeding check, not a request-path query; LATEPOINT_TABLE_PROCESSES has no
		// user-controlled part, and the %s placeholders cover every real value.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM `' . LATEPOINT_TABLE_PROCESSES . '` WHERE event_type = %s AND name != %s',
				'transaction_created',
				self::PROCESS_NAME
			)
		);
		// phpcs:enable

		return $count > 0;
	}

	/**
	 * The email body, read from its own view file rather than inlined here — same split LatePoint
	 * itself uses (mailers/customer/*.html) between the process' own subject/to_email settings and
	 * its content. Only order/customer/transaction merge tags are used (see the class docblock on
	 * why no `agent`/booking-level tag — `{{service_name}}`, `{{start_date}}`, `{{agent_full_name}}`,
	 * etc. — would resolve here): modelled on LatePoint's own order_created.html, not
	 * booking_created.html, since this event is order-level, not booking-level.
	 *
	 * Plain file_get_contents(), not WP_Filesystem — this file is bundled with the plugin, never
	 * remote or user-writable, so there is nothing WP_Filesystem's credential/transport abstraction
	 * buys here. An earlier version of this method used WP_Filesystem() to match LatePoint's own
	 * seed_initial_data() convention, but that call is only proven safe in the admin-triggered
	 * context LatePoint itself calls it from; this seeder also runs from init() at priority 0 (see
	 * the main plugin file), and WP_Filesystem() silently returned an unusable state there in
	 * practice — verified live: a process already seeded that way had an empty `content` string,
	 * so the "Payment Received" email carried no order details at all, just this add-on's own
	 * appended reference box.
	 */
	private static function email_content(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file this plugin ships, not a remote URL; wp_remote_get() doesn't apply.
		return (string) file_get_contents( __DIR__ . '/../views/mailers/customer/transaction_created.html' );
	}

	/**
	 * Stands in for OsEmailHelper::get_email_layout() when that call itself fails (see the note
	 * above) — the same outer-gutter-plus-white-card structure
	 * IfthenpayLpReferenceDisplay::render_email_html() already reproduces "by value" for the exact
	 * same reason (copied there from mailers/layouts/default.html, not read from it): $content
	 * still reaches the customer in full, just without the merchant's own configured
	 * `email_layout_template` (logo, business info) wrapped around it.
	 *
	 * @param string $content As returned by email_content().
	 */
	private static function fallback_layout( string $content ): string {
		return '<div style="padding: 20px; background-color: #f0f0f0; font-family: -apple-system, system-ui, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif;">'
			. '<div style="background-color: #fff; padding: 30px; margin: 0px auto; max-width: 450px; box-shadow: 0px 2px 6px -1px rgba(0,0,0,0.2); border-radius: 6px;">'
			. $content
			. '</div></div>';
	}
}
