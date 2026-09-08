<?php
/**
 * A standalone WP admin page (Settings → ifthenpay for LatePoint) for the actions that only ever
 * shipped as WP-CLI commands (IfthenpayLpCliCommands) — re-registering the callback URL, and
 * manually re-checking or cancelling a stuck deferred payment. None of these are reachable by a
 * merchant without shell access to their own server, which the overwhelming majority of LatePoint
 * merchants simply do not have. This page is the same actions, reachable from wp-admin instead —
 * deliberately not touching any of LatePoint's own screens (order/invoice views), so it lives under
 * WordPress's own Settings menu rather than inside LatePoint's admin UI. The name spells out the
 * LatePoint connection explicitly, and IfthenpayLpAdminFormRenderer::render_tools_page_link() links
 * here from inside LatePoint's own Settings → Payments tab, since a generic "Settings" submenu
 * item has no visual tie to LatePoint on its own otherwise.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every method here is static — this page carries no per-request state of its own beyond what a
 * single render() call needs, the same convention IfthenpayLpManualRecheck/IfthenpayLpCallbackRegistration
 * already use.
 */
class IfthenpayLpToolsPageController {

	private const NONCE_ACTION = 'ifthenpay_lp_tools_action';

	/**
	 * Registers the page under WordPress's own Settings menu.
	 */
	public static function add_page(): void {
		add_options_page(
			__( 'ifthenpay for LatePoint', 'ifthenpay-payments-for-latepoint' ),
			__( 'ifthenpay for LatePoint', 'ifthenpay-payments-for-latepoint' ),
			'manage_options',
			'ifthenpay-lp-tools',
			array( self::class, 'render' )
		);
	}

	/**
	 * `add_options_page()`'s own `manage_options` capability only keeps the menu item out of an
	 * obviously-unauthorized user's sidebar; the real gate — the same one every other admin-facing
	 * action in this add-on already uses — is checked again here, since a WP admin page has no
	 * `action_access`/`OsController` routing of its own to enforce it automatically.
	 */
	public static function render(): void {
		if ( ! OsRolesHelper::can_user( 'settings__edit' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ifthenpay-payments-for-latepoint' ) );
		}

		$notice = self::handle_post();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'ifthenpay for LatePoint', 'ifthenpay-payments-for-latepoint' ) . '</h1>';
		echo '<p>' . esc_html__( 'Advanced tools for the ifthenpay payment integration with LatePoint — re-register the callback URL, and recheck or cancel a stuck deferred payment.', 'ifthenpay-payments-for-latepoint' ) . '</p>';

		if ( null !== $notice ) {
			printf(
				'<div class="notice notice-%1$s"><p>%2$s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] )
			);
		}

		self::render_callback_section();
		self::render_pending_payments_section();
		self::render_unclaimed_realtime_section();

		echo '</div>';
	}

	/**
	 * Handles either of this page's two POST actions, if one was submitted — both nonce-protected
	 * (self::NONCE_ACTION) and re-checked against the same capability render() itself requires,
	 * since a stale menu link or a re-submitted form must never bypass either check.
	 *
	 * @return array{type:string,message:string}|null A notice to display, or null if no action was posted.
	 */
	private static function handle_post(): ?array {
		if ( ! isset( $_POST['ifthenpay_lp_tools_action'] ) ) {
			return null;
		}

		if ( ! OsRolesHelper::can_user( 'settings__edit' )
			|| ! check_admin_referer( self::NONCE_ACTION, 'ifthenpay_lp_tools_nonce' )
		) {
			return array(
				'type'    => 'error',
				'message' => __( 'This action could not be verified. Please try again.', 'ifthenpay-payments-for-latepoint' ),
			);
		}

		$action = sanitize_text_field( wp_unslash( $_POST['ifthenpay_lp_tools_action'] ) );

		if ( 'reregister_callback' === $action ) {
			return self::handle_reregister_callback();
		}

		if ( 'recheck_payment' === $action ) {
			return self::handle_recheck_payment( sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ) );
		}

		if ( 'cancel_payment' === $action ) {
			return self::handle_cancel_payment( sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ) );
		}

		if ( 'mark_resolved' === $action ) {
			return self::handle_mark_resolved( sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ) );
		}

		if ( 'unresolve_payment' === $action ) {
			return self::handle_unresolve_payment( sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ) );
		}

		return null;
	}

	/**
	 * The re-registration outcome, as a displayable notice.
	 *
	 * @return array{type:string,message:string}
	 */
	private static function handle_reregister_callback(): array {
		$gateway_key = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key', '' );

		if ( '' === $gateway_key ) {
			return array(
				'type'    => 'error',
				'message' => __( 'No Gateway Key is saved yet — configure the ifthenpay payment processor first.', 'ifthenpay-payments-for-latepoint' ),
			);
		}

		if ( IfthenpayLpCallbackRegistration::register( $gateway_key ) ) {
			return array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: %s: gateway key */
					__( 'Callback URL registered for gateway key %s.', 'ifthenpay-payments-for-latepoint' ),
					$gateway_key
				),
			);
		}

		$status = IfthenpayLpCallbackRegistration::get_status( $gateway_key );

		return array(
			'type'    => 'error',
			'message' => sprintf(
				/* translators: 1: gateway key, 2: failure reason */
				__( 'Registration failed for gateway key %1$s: %2$s', 'ifthenpay-payments-for-latepoint' ),
				$gateway_key,
				$status['message'] ?? ''
			),
		);
	}

	/**
	 * Same outcome mapping the WP-CLI command and the (unreachable-from-the-UI) settings-controller
	 * action already use — IfthenpayLpManualRecheck::default_message_for() is the single source of
	 * truth for the merchant-facing wording either way.
	 *
	 * @param string $token The repository row's own token, as submitted by the row's own form.
	 * @return array{type:string,message:string}
	 */
	private static function handle_recheck_payment( string $token ): array {
		$outcome = IfthenpayLpManualRecheck::run( $token );

		return array(
			'type'    => IfthenpayLpManualRecheck::SETTLED === $outcome['outcome'] ? 'success' : 'error',
			'message' => IfthenpayLpManualRecheck::default_message_for( $outcome['outcome'] ),
		);
	}

	/**
	 * Gives up on a stuck deferred payment without waiting for the hourly expiry sweep to reach
	 * it — same outcome, on demand: every booking on the order cancelled (releasing the slot), the
	 * row itself marked CANCELLED, so it drops out of this page's own PENDING-only listing.
	 *
	 * @param string $token The repository row's own token, as submitted by the row's own form.
	 * @return array{type:string,message:string}
	 */
	private static function handle_cancel_payment( string $token ): array {
		if ( IfthenpayLpExpirySweep::cancel_now( $token ) ) {
			return array(
				'type'    => 'success',
				'message' => __( 'Payment cancelled and the booking released.', 'ifthenpay-payments-for-latepoint' ),
			);
		}

		return array(
			'type'    => 'error',
			'message' => __( 'Could not cancel this payment — it may have just settled, or already been handled.', 'ifthenpay-payments-for-latepoint' ),
		);
	}

	/**
	 * Dismisses a row from the Unclaimed Realtime Payments listing once a merchant has resolved it
	 * with their customer — no confirmation dialog, unlike Cancel: nothing here is destroyed, the
	 * underlying paid row and its history stay exactly as they are.
	 *
	 * @param string $token The repository row's own token, as submitted by the row's own form.
	 * @return array{type:string,message:string}
	 */
	private static function handle_mark_resolved( string $token ): array {
		if ( IfthenpayLpTransactionRepository::mark_resolved( $token ) ) {
			return array(
				'type'    => 'success',
				'message' => __( 'Marked resolved.', 'ifthenpay-payments-for-latepoint' ),
			);
		}

		return array(
			'type'    => 'error',
			'message' => __( 'Could not mark this payment resolved. Please try again.', 'ifthenpay-payments-for-latepoint' ),
		);
	}

	/**
	 * The undo for a row marked resolved by mistake — puts it back in the default Unclaimed
	 * listing. Same no-confirmation-dialog reasoning as handle_mark_resolved(): nothing here is
	 * destroyed either.
	 *
	 * @param string $token The repository row's own token, as submitted by the row's own form.
	 * @return array{type:string,message:string}
	 */
	private static function handle_unresolve_payment( string $token ): array {
		if ( IfthenpayLpTransactionRepository::mark_unresolved( $token ) ) {
			return array(
				'type'    => 'success',
				'message' => __( 'Marked unresolved — back on the Unclaimed list.', 'ifthenpay-payments-for-latepoint' ),
			);
		}

		return array(
			'type'    => 'error',
			'message' => __( 'Could not mark this payment unresolved. Please try again.', 'ifthenpay-payments-for-latepoint' ),
		);
	}

	/**
	 * The callback-URL status, boxed together with the button that re-registers it — the button
	 * sits in the box's own header row, top-right, rather than stacked below the URL text, so it
	 * reads as one self-contained panel instead of trailing off the bottom of a plain paragraph.
	 */
	private static function render_callback_section(): void {
		$gateway_key = (string) OsSettingsHelper::get_settings_value( 'ifthenpay_gateway_key', '' );

		echo '<div class="card" style="max-width:none;">';
		echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Callback URL', 'ifthenpay-payments-for-latepoint' ) . '</h2>';

		if ( '' !== $gateway_key ) {
			echo '<form method="post" style="flex-shrink:0;">';
			wp_nonce_field( self::NONCE_ACTION, 'ifthenpay_lp_tools_nonce' );
			echo '<input type="hidden" name="ifthenpay_lp_tools_action" value="reregister_callback" />';
			submit_button( __( 'Re-register Callback URL', 'ifthenpay-payments-for-latepoint' ), 'secondary', 'submit', false );
			echo '</form>';
		}

		echo '</div>';

		if ( '' === $gateway_key ) {
			echo '<p>' . esc_html__( 'No Gateway Key is saved yet — configure the ifthenpay payment processor first.', 'ifthenpay-payments-for-latepoint' ) . '</p>';
			echo '</div>';
			return;
		}

		$status = IfthenpayLpCallbackRegistration::get_status( $gateway_key );

		// A status stored before this add-on tracked callback_url at all (an older version's
		// registration attempt) has no such key — falls back to the current computed URL rather
		// than showing blank; it's the best available answer to "what would this site submit".
		$callback_url = $status['callback_url'] ?? IfthenpayLpCallbackRegistration::build_callback_url();

		if ( null === $status ) {
			echo '<p>' . esc_html__( 'Not registered yet. This is the URL a registration would submit:', 'ifthenpay-payments-for-latepoint' ) . '</p>';
		} elseif ( $status['success'] ) {
			echo '<p>' . esc_html__( 'Registered. The URL currently on file with ifthenpay for this gateway key:', 'ifthenpay-payments-for-latepoint' ) . '</p>';
		} else {
			$message = sprintf(
				/* translators: %s: failure reason */
				__( 'Not registered: %s', 'ifthenpay-payments-for-latepoint' ),
				$status['message']
			);
			echo '<p>' . esc_html( $message ) . '</p>';
			echo '<p>' . esc_html__( 'The URL the last attempt tried to submit, for comparison:', 'ifthenpay-payments-for-latepoint' ) . '</p>';
		}

		echo '<p style="display:flex;align-items:center;gap:8px;">';
		echo '<code id="ifthenpay-lp-callback-url">' . esc_html( $callback_url ) . '</code>';
		echo '<button type="button" class="button button-small" id="ifthenpay-lp-copy-callback-url">' . esc_html__( 'Copy', 'ifthenpay-payments-for-latepoint' ) . '</button>';
		echo '</p>';
		self::render_copy_button_script();
		echo '</div>';
	}

	/**
	 * A small, page-local script for the one Copy button above — not worth its own enqueued asset
	 * file for a single click handler on a page only ever loaded from wp-admin.
	 */
	private static function render_copy_button_script(): void {
		?>
		<script>
		( function () {
			var button = document.getElementById( 'ifthenpay-lp-copy-callback-url' );
			var code   = document.getElementById( 'ifthenpay-lp-callback-url' );
			if ( ! button || ! code || ! navigator.clipboard ) {
				return;
			}
			var defaultLabel = button.textContent;
			button.addEventListener( 'click', function () {
				navigator.clipboard.writeText( code.textContent ).then( function () {
					button.textContent = '<?php echo esc_js( __( 'Copied!', 'ifthenpay-payments-for-latepoint' ) ); ?>';
					setTimeout( function () {
						button.textContent = defaultLabel;
					}, 2000 );
				} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * The table of outstanding deferred payments, each row with its own Recheck button.
	 */
	private static function render_pending_payments_section(): void {
		$records = IfthenpayLpTransactionRepository::find_pending_deferred();

		echo '<h2>' . esc_html__( 'Outstanding Deferred Payments', 'ifthenpay-payments-for-latepoint' ) . '</h2>';

		if ( array() === $records ) {
			echo '<p>' . esc_html__( 'Nothing outstanding.', 'ifthenpay-payments-for-latepoint' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach (
			array(
				__( 'Token', 'ifthenpay-payments-for-latepoint' ),
				__( 'Request ID', 'ifthenpay-payments-for-latepoint' ),
				__( 'Customer', 'ifthenpay-payments-for-latepoint' ),
				__( 'Contact', 'ifthenpay-payments-for-latepoint' ),
				__( 'Method', 'ifthenpay-payments-for-latepoint' ),
				__( 'Entity', 'ifthenpay-payments-for-latepoint' ),
				__( 'Reference', 'ifthenpay-payments-for-latepoint' ),
				__( 'Amount', 'ifthenpay-payments-for-latepoint' ),
				__( 'Expires', 'ifthenpay-payments-for-latepoint' ),
				__( 'Action', 'ifthenpay-payments-for-latepoint' ),
			) as $heading
		) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $records as $record ) {
			$customer = self::customer_for_intent( (int) $record->intent_id );

			echo '<tr>';
			// The one identifier every intervenient shares: it's the row ifthenpay's callback
			// itself resolves against (as [ORDER_ID]), what LatePoint shows the customer as their
			// own "Confirmation Code", and the same value the Recheck button below submits — the
			// correlation handle to hand a support ticket, not an internal implementation detail.
			echo '<td><code>' . esc_html( (string) $record->token ) . '</code></td>';
			// The identifier Recheck actually sends to ifthenpay (IfthenpayLpTransactionStatus::check())
			// — never the token above, see IfthenpayLpManualRecheck's own docblock on why the two
			// are not interchangeable.
			echo '<td><code>' . esc_html( ! empty( $record->request_id ) ? (string) $record->request_id : '—' ) . '</code></td>';
			echo '<td>' . esc_html( $customer ? $customer->full_name : '—' ) . '</td>';
			echo '<td>' . esc_html( self::contact_label( $customer ) ) . '</td>';
			echo '<td>' . esc_html( (string) $record->method ) . '</td>';
			echo '<td>' . esc_html( ! empty( $record->entity ) ? (string) $record->entity : '—' ) . '</td>';
			echo '<td>' . esc_html( ! empty( $record->reference ) ? (string) $record->reference : '—' ) . '</td>';
			echo '<td>' . esc_html( null !== $record->amount ? OsMoneyHelper::format_price( $record->amount, true, false ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $record->expires_at ?? '—' ) . '</td>';
			echo '<td>';
			echo '<form method="post" style="display:inline">';
			wp_nonce_field( self::NONCE_ACTION, 'ifthenpay_lp_tools_nonce' );
			echo '<input type="hidden" name="ifthenpay_lp_tools_action" value="recheck_payment" />';
			echo '<input type="hidden" name="token" value="' . esc_attr( (string) $record->token ) . '" />';
			submit_button( __( 'Recheck', 'ifthenpay-payments-for-latepoint' ), 'secondary small', 'submit', false );
			echo '</form> ';
			echo '<form method="post" style="display:inline" onsubmit="return confirm(\'' . esc_js( __( 'Cancel this payment and release the booking\'s slot? This cannot be undone.', 'ifthenpay-payments-for-latepoint' ) ) . '\');">';
			wp_nonce_field( self::NONCE_ACTION, 'ifthenpay_lp_tools_nonce' );
			echo '<input type="hidden" name="ifthenpay_lp_tools_action" value="cancel_payment" />';
			echo '<input type="hidden" name="token" value="' . esc_attr( (string) $record->token ) . '" />';
			submit_button( __( 'Cancel', 'ifthenpay-payments-for-latepoint' ), 'delete small', 'submit', false );
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Resolves a repository row's own intent_id to its customer, or null if it can't be resolved —
	 * one lookup shared by both the Customer and Contact columns, rather than each re-resolving it.
	 * Same order_intent → customer path IfthenpayLpLapsedAppointmentDigest::line_for_booking()
	 * resolves from the booking side; this page starts from the transaction row instead, since
	 * there may be no booking to start from that isn't already known.
	 *
	 * @param int $intent_id The repository row's own intent_id.
	 */
	private static function customer_for_intent( int $intent_id ): ?OsCustomerModel {
		$order_intent = new OsOrderIntentModel( $intent_id );
		if ( $order_intent->is_new_record() || empty( $order_intent->customer_id ) ) {
			return null;
		}

		$customer = new OsCustomerModel( (int) $order_intent->customer_id );

		return $customer->is_new_record() ? null : $customer;
	}

	/**
	 * Both email and phone, when the customer has each on file — unlike
	 * IfthenpayLpLapsedAppointmentDigest::line_for_booking()'s own $contact (email, falling back to
	 * phone only when there's no email), this table has a column of its own to spare, so there's no
	 * reason to hide whichever one the digest's single-line format would have dropped.
	 *
	 * @param OsCustomerModel|null $customer As returned by customer_for_intent().
	 */
	private static function contact_label( ?OsCustomerModel $customer ): string {
		if ( ! $customer ) {
			return '—';
		}

		$parts = array_filter( array( $customer->email, $customer->phone ) );

		return array() !== $parts ? implode( ' / ', $parts ) : '—';
	}

	/**
	 * A realtime payment that settled PAID but was never claimed by a real LatePoint booking —
	 * customer's browser died before convert_to_order() ran, or (worst case) a retry paid and
	 * converted separately, leaving this row a genuine second charge. No Recheck/Cancel here: the
	 * payment is already confirmed, and cancelling would be wrong — the only real action is a
	 * merchant resolving it with their customer directly, then dismissing the row.
	 *
	 * Also renders the Resolved view (?ifthenpay_lp_view=resolved) behind the same tabs — a plain
	 * GET switch, not a mutation, so it needs no nonce: reviewing past resolutions, and undoing one
	 * marked resolved by mistake (mark_unresolved()), is why find_resolved_realtime() exists at all.
	 */
	private static function render_unclaimed_realtime_section(): void {
		$viewing_resolved = isset( $_GET['ifthenpay_lp_view'] ) && 'resolved' === sanitize_text_field( wp_unslash( $_GET['ifthenpay_lp_view'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view switch, not a mutation; every actual mutation on this page is its own nonce-checked POST.

		echo '<h2>' . esc_html__( 'Unclaimed Realtime Payments', 'ifthenpay-payments-for-latepoint' ) . '</h2>';
		self::render_realtime_view_tabs( $viewing_resolved );

		$records = $viewing_resolved
			? IfthenpayLpTransactionRepository::find_resolved_realtime()
			: IfthenpayLpTransactionRepository::find_unclaimed_realtime();

		if ( array() === $records ) {
			echo '<p>' . esc_html(
				$viewing_resolved
					? __( 'No resolved payments yet.', 'ifthenpay-payments-for-latepoint' )
					: __( 'Nothing unclaimed.', 'ifthenpay-payments-for-latepoint' )
			) . '</p>';
			return;
		}

		$headings = array(
			__( 'Token', 'ifthenpay-payments-for-latepoint' ),
			__( 'Customer', 'ifthenpay-payments-for-latepoint' ),
			__( 'Contact', 'ifthenpay-payments-for-latepoint' ),
			__( 'Booking (reconstructed at checkout time)', 'ifthenpay-payments-for-latepoint' ),
			__( 'Method', 'ifthenpay-payments-for-latepoint' ),
			__( 'Amount', 'ifthenpay-payments-for-latepoint' ),
			__( 'Settled', 'ifthenpay-payments-for-latepoint' ),
		);
		if ( $viewing_resolved ) {
			$headings[] = __( 'Resolved', 'ifthenpay-payments-for-latepoint' );
		}
		$headings[] = __( 'Action', 'ifthenpay-payments-for-latepoint' );

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $headings as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $records as $record ) {
			$snapshot                        = IfthenpayLpTransactionRepository::decode_checkout_snapshot( $record );
			list( $customer_name, $contact ) = self::unclaimed_customer_and_contact( $snapshot );

			echo '<tr>';
			echo '<td><code>' . esc_html( (string) $record->token ) . '</code></td>';
			echo '<td>' . esc_html( $customer_name ) . '</td>';
			echo '<td>' . esc_html( $contact ) . '</td>';
			echo '<td>' . esc_html( ! empty( $snapshot['booking_summary'] ) ? $snapshot['booking_summary'] : '—' ) . '</td>';
			echo '<td>' . esc_html( (string) $record->method ) . '</td>';
			echo '<td>' . esc_html( null !== $record->amount ? OsMoneyHelper::format_price( $record->amount, true, false ) : '—' ) . '</td>';
			echo '<td>' . esc_html( self::human_age( $record->settled_at ) ) . '</td>';
			if ( $viewing_resolved ) {
				echo '<td>' . esc_html( self::human_age( $record->resolved_at ) ) . '</td>';
			}
			echo '<td>';
			echo '<form method="post" style="display:inline">';
			wp_nonce_field( self::NONCE_ACTION, 'ifthenpay_lp_tools_nonce' );
			echo '<input type="hidden" name="ifthenpay_lp_tools_action" value="' . esc_attr( $viewing_resolved ? 'unresolve_payment' : 'mark_resolved' ) . '" />';
			echo '<input type="hidden" name="token" value="' . esc_attr( (string) $record->token ) . '" />';
			submit_button(
				$viewing_resolved ? __( 'Unresolve', 'ifthenpay-payments-for-latepoint' ) : __( 'Mark Resolved', 'ifthenpay-payments-for-latepoint' ),
				'secondary small',
				'submit',
				false
			);
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The Unclaimed/Resolved tab switch — a plain link toggling ?ifthenpay_lp_view, WP admin's own
	 * `.subsubsub` convention (Posts list "All | Mine | Published | ..."), so it reads as native
	 * chrome rather than a bespoke control.
	 *
	 * @param bool $viewing_resolved Which tab is currently active.
	 */
	private static function render_realtime_view_tabs( bool $viewing_resolved ): void {
		$unclaimed_url = remove_query_arg( 'ifthenpay_lp_view' );
		$resolved_url  = add_query_arg( 'ifthenpay_lp_view', 'resolved' );

		echo '<ul class="subsubsub">';
		echo '<li><a href="' . esc_url( $unclaimed_url ) . '"' . ( $viewing_resolved ? '' : ' class="current"' ) . '>' . esc_html__( 'Unclaimed', 'ifthenpay-payments-for-latepoint' ) . '</a> |</li>';
		echo ' <li><a href="' . esc_url( $resolved_url ) . '"' . ( $viewing_resolved ? ' class="current"' : '' ) . '>' . esc_html__( 'Resolved', 'ifthenpay-payments-for-latepoint' ) . '</a></li>';
		echo '</ul><br class="clear" />';
	}

	/**
	 * Shared human_time_diff() formatting for both the Settled and Resolved columns.
	 *
	 * @param string|null $datetime A MySQL datetime, or null/empty if not yet set.
	 */
	private static function human_age( ?string $datetime ): string {
		if ( empty( $datetime ) ) {
			return '—';
		}

		/* translators: %s: human-readable time difference, e.g. "2 hours" */
		return sprintf( __( '%s ago', 'ifthenpay-payments-for-latepoint' ), human_time_diff( strtotime( $datetime ) ) );
	}

	/**
	 * Reads the row's own write-time snapshot — never the live order_intent, which LatePoint may
	 * have reused (and silently overwritten) for a later, unrelated checkout attempt since this
	 * payment settled. See OsPaymentsIfthenpayCheckoutController::build_unclaimed_snapshot()'s own
	 * docblock for why that row can't be trusted after the fact. Takes the already-decoded
	 * checkout_snapshot rather than the row itself — the caller also needs `booking_summary` out of
	 * the same array, so it decodes once and passes it here instead of this method decoding its own copy.
	 *
	 * @param array<string,mixed> $data As returned by IfthenpayLpTransactionRepository::decode_checkout_snapshot().
	 * @return array{0:string,1:string} [customer name, contact]
	 */
	private static function unclaimed_customer_and_contact( array $data ): array {
		if ( ! empty( $data['customer_id'] ) ) {
			$customer = new OsCustomerModel( (int) $data['customer_id'] );
			if ( ! $customer->is_new_record() ) {
				return array( $customer->full_name, self::contact_label( $customer ) );
			}
		}

		if ( ! empty( $data['customer_name'] ) ) {
			$contact_parts = array_filter( array( $data['customer_email'] ?? '', $data['customer_phone'] ?? '' ) );

			return array( $data['customer_name'], array() !== $contact_parts ? implode( ' / ', $contact_parts ) : '—' );
		}

		return array( '—', '—' );
	}
}
