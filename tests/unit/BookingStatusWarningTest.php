<?php
/**
 * Proves IfthenpayLpAdminFormRenderer's booking-status warning: LatePoint only blocks a time slot
 * for a booking whose status is in `timeslot_blocking_statuses` (`approved` only, by default); a
 * deferred Multibanco/Payshop checkout's booking instead gets whatever `default_booking_status`
 * says. When those two disagree, a deferred booking sits unpaid and unblocked for however long its
 * reference stays outstanding — a real double-booking risk this add-on doesn't own the setting for,
 * so it only ever warns, pointing at LatePoint's own Settings → General screen.
 *
 * @package ifthenpay-payments-for-latepoint
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/views/ifthenpay-lp-admin-form-renderer.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/ifthenpay-lp-pay-by-link-method-eligibility.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/ifthenpay-lp-payment-processor.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/ifthenpay-lp-payment-method-availability.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/validation/ifthenpay-lp-whole-days-setting-validation.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/validation/ifthenpay-lp-multibanco-validity-validation.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/validation/ifthenpay-lp-multibanco-lead-time-validation.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/validation/ifthenpay-lp-payshop-validity-validation.php';
require_once dirname( __DIR__, 2 ) . '/lib/models/validation/ifthenpay-lp-payshop-lead-time-validation.php';
require_once __DIR__ . '/../support/class-os-settings-helper-stub.php';
require_once __DIR__ . '/../support/class-os-form-helper-stub.php';
require_once __DIR__ . '/../support/class-os-booking-helper-stub.php';
require_once __DIR__ . '/../support/class-os-router-helper-stub.php';

if ( ! defined( 'LATEPOINT_BOOKING_STATUS_APPROVED' ) ) {
	define( 'LATEPOINT_BOOKING_STATUS_APPROVED', 'approved' );
}

/**
 * Booking-status warning proof.
 */
final class BookingStatusWarningTest extends TestCase {

	/**
	 * A minimal deferred catalog (MB only) — enough for render_pay_later_configuration() to not
	 * early-return, which is the only precondition for the warning to ever be considered.
	 */
	private function render(): string {
		ob_start();
		IfthenpayLpAdminFormRenderer::render_payments_configuration(
			'GATEWAY-1',
			array( 'GATEWAY-1' => array( 'MB' => 'HLP-000001' ) ),
			array(
				'MB' => array(
					'position' => 1,
					'image'    => '',
					'tooltip'  => '',
					'label'    => 'multibanco',
				),
			)
		);
		return (string) ob_get_clean();
	}

	/**
	 * Boots Brain Monkey and stubs the WP functions the renderer touches.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		OsSettingsHelper::$values                    = array();
		OsBookingHelper::$timeslot_blocking_statuses = array( 'approved' );

		Functions\stubs(
			array(
				'esc_html'   => static fn( $text ) => $text,
				'esc_html__' => static fn( $text ) => $text,
				'esc_attr'   => static fn( $text ) => $text,
				'esc_url'    => static fn( $text ) => $text,
				'__'         => static fn( $text ) => $text,
			)
		);
	}

	/**
	 * Tears down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A default booking status not in the blocking-statuses list: the warning renders, with a link
	 * to LatePoint's own Settings → General screen (not something this add-on tries to change
	 * itself).
	 */
	public function test_warning_renders_when_default_status_does_not_block_the_slot(): void {
		OsSettingsHelper::$values['default_booking_status'] = 'pending';
		OsBookingHelper::$timeslot_blocking_statuses        = array( 'approved' );

		$html = $this->render();

		$this->assertStringContainsString( 'ifthenpay-booking-status-warning', $html );
		$this->assertStringContainsString( 'settings__general', $html );
	}

	/**
	 * The default booking status already blocks the slot (LatePoint's own out-of-the-box
	 * configuration): no warning.
	 */
	public function test_no_warning_when_default_status_already_blocks_the_slot(): void {
		OsSettingsHelper::$values['default_booking_status'] = 'approved';
		OsBookingHelper::$timeslot_blocking_statuses        = array( 'approved' );

		$html = $this->render();

		$this->assertStringNotContainsString( 'ifthenpay-booking-status-warning', $html );
	}

	/**
	 * No `default_booking_status` saved at all falls back to LatePoint's own real default
	 * (`approved`), which also blocks by default — no warning on an untouched install.
	 */
	public function test_no_warning_on_an_untouched_install(): void {
		$html = $this->render();

		$this->assertStringNotContainsString( 'ifthenpay-booking-status-warning', $html );
	}

	/**
	 * A merchant who already added 'pending' to their own blocking-statuses list (LatePoint's own
	 * multi-select allows more than one) is already covered — no warning, even though the default
	 * isn't 'approved'.
	 */
	public function test_no_warning_when_pending_was_added_to_blocking_statuses(): void {
		OsSettingsHelper::$values['default_booking_status'] = 'pending';
		OsBookingHelper::$timeslot_blocking_statuses        = array( 'approved', 'pending' );

		$html = $this->render();

		$this->assertStringNotContainsString( 'ifthenpay-booking-status-warning', $html );
	}
}
