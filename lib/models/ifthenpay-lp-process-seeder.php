<?php
/**
 * Seeds a `transaction_created` LatePoint process on activation — LatePoint's own
 * OsDatabaseHelper::seed_initial_data() seeds exactly one default process, for `booking_created`,
 * which fires at checkout before any deferred (Multibanco/Payshop) payment exists. This add-on's
 * own settlement code already fires `do_action('latepoint_transaction_created', $transaction)` on
 * actual payment (IfthenpayLpSettlement::apply_state_change()), and LatePoint's own
 * OsProcessJobsHelper wires that event to any configured process — but ships no default one, so
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
 * One seeding entry point, called from on_activate(). Idempotent the same way LatePoint's own
 * seed_initial_data() is: OsProcessesHelper::check_if_process_exists() matches on (event_type,
 * name), so re-activation never creates a duplicate.
 */
class IfthenpayLpProcessSeeder {

	/**
	 * The process name this seeder owns — also the identity check_if_process_exists() matches on
	 * alongside event_type, so this string must stay stable across releases (renaming it would make
	 * a future call here re-seed a second row instead of recognising the first).
	 */
	private const PROCESS_NAME = 'Payment Received Notification';

	/**
	 * Creates the `transaction_created` process if this add-on hasn't already, once. Customer-only:
	 * OsReplacerHelper::generate_replacement_vars_from_transaction() populates `transaction`,
	 * `order`, and `customer` merge-tag contexts, but never `agent` — an order can span bookings
	 * with different agents, so there is no single natural agent recipient the way `booking_created`
	 * has one. Mirrors OsDatabaseHelper::seed_initial_data()'s own construction
	 * (latepoint/lib/helpers/database_helper.php) exactly, action-shape included, so this stays a
	 * process LatePoint's own Processes admin screen can show, edit, and disable like any other.
	 *
	 * @return bool True if a process was created just now; false if one already existed.
	 */
	public static function seed_transaction_created_process(): bool {
		$process             = new OsProcessModel();
		$process->event_type = 'transaction_created';
		$process->name       = self::PROCESS_NAME;

		if ( OsProcessesHelper::check_if_process_exists( $process ) ) {
			return false;
		}

		$action                         = array();
		$action['type']                 = 'send_email';
		$action['settings']['to_email'] = '{{customer_full_name}} <{{customer_email}}>';
		$action['settings']['subject']  = __( 'Payment Received', 'ifthenpay-payments-for-latepoint' );
		$action['settings']['content']  = OsEmailHelper::get_email_layout( self::email_content() );

		$actions = array(
			\LatePoint\Misc\ProcessAction::generate_id() => $action,
		);

		$process_actions                   = OsProcessesHelper::iterate_trigger_conditions( array(), $actions );
		$process_actions[0]['time_offset'] = array();
		$process->actions_json             = wp_json_encode( $process_actions );
		$process->save();

		return true;
	}

	/**
	 * The email body, read from its own view file rather than inlined here — same split LatePoint
	 * itself uses (mailers/customer/*.html) between the process' own subject/to_email settings and
	 * its content. Only order/customer/transaction merge tags are used (see the class docblock on
	 * why no `agent`/booking-level tag — `{{service_name}}`, `{{start_date}}`, `{{agent_full_name}}`,
	 * etc. — would resolve here): modelled on LatePoint's own order_created.html, not
	 * booking_created.html, since this event is order-level, not booking-level.
	 *
	 * WP_Filesystem, not file_get_contents(), matching how LatePoint's own seed_initial_data()
	 * reads its own booking_created.html — this file is bundled with the plugin, never remote, but
	 * staying consistent with the one other place in either codebase that reads a mailer view file.
	 */
	private static function email_content(): string {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;
		if ( ! WP_Filesystem() ) {
			return '';
		}

		return (string) $wp_filesystem->get_contents( __DIR__ . '/../views/mailers/customer/transaction_created.html' );
	}
}
