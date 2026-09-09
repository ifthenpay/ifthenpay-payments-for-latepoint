<?php
/**
 * A OsPaymentsIfthenpayCheckoutController subclass that captures send_json()'s payload instead of
 * terminating the process — OsController::send_json() delegates to core wp_send_json(), which
 * hard-`die`s outside a real AJAX request (wp_doing_ajax() is false under plain WP_UnitTestCase),
 * killing the whole PHPUnit run. Lets a test exercise a public action method's real body in-process.
 *
 * @package ifthenpay-payments-for-latepoint
 */

/**
 * Captures instead of terminating.
 */
class IfthenpayLpTestableCheckoutController extends OsPaymentsIfthenpayCheckoutController {

	/**
	 * Whatever the real method last passed to send_json().
	 *
	 * @var array<string,mixed>|null
	 */
	public $captured = null;

	/**
	 * Captures instead of calling wp_send_json(), which would terminate the test process.
	 *
	 * @param array<string,mixed> $data        As OsController::send_json().
	 * @param int|null            $status_code As OsController::send_json(); unused here.
	 */
	public function send_json( $data, $status_code = null ) {
		$this->captured = $data;
	}
}
