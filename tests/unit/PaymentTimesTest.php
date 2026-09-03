<?php
/**
 * Proves IfthenpayLpPaymentTimes::add_methods() — the fix for the bug where every method was
 * hardcoded into LATEPOINT_PAYMENT_TIME_NOW regardless of its own `time_type` (research.md,
 * plan.md §1). The realtime gateway and a deferred method must land in different buckets, or
 * checkout blocks Multibanco waiting for a payment that will not arrive for days.
 *
 * @package ifthenpay-payments-for-latepoint
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/models/ifthenpay-lp-payment-times.php';

if ( ! defined( 'LATEPOINT_PAYMENT_TIME_NOW' ) ) {
	define( 'LATEPOINT_PAYMENT_TIME_NOW', 'now' );
}
if ( ! defined( 'LATEPOINT_PAYMENT_TIME_LATER' ) ) {
	define( 'LATEPOINT_PAYMENT_TIME_LATER', 'later' );
}

/**
 * Payment-time bucketing proof.
 */
final class PaymentTimesTest extends TestCase {

	/**
	 * The two real methods this add-on registers: realtime Pay By Link stays 'now', deferred
	 * Multibanco lands under 'later' — the exact regression tasks.md T-07 calls for.
	 */
	public function test_realtime_and_deferred_methods_land_in_different_buckets(): void {
		$payment_methods = array(
			'ifthenpay_gateway'    => array(
				'code'      => 'ifthenpay_checkout',
				'time_type' => 'now',
			),
			'ifthenpay_multibanco' => array(
				'code'      => 'ifthenpay_multibanco',
				'time_type' => 'later',
			),
		);

		$result = IfthenpayLpPaymentTimes::add_methods( array(), $payment_methods, 'ifthenpay' );

		$this->assertArrayHasKey( 'ifthenpay_gateway', $result[ LATEPOINT_PAYMENT_TIME_NOW ] );
		$this->assertArrayNotHasKey( 'ifthenpay_gateway', $result[ LATEPOINT_PAYMENT_TIME_LATER ] );

		$this->assertArrayHasKey( 'ifthenpay_multibanco', $result[ LATEPOINT_PAYMENT_TIME_LATER ] );
		$this->assertArrayNotHasKey( 'ifthenpay_multibanco', $result[ LATEPOINT_PAYMENT_TIME_NOW ] );
	}

	/**
	 * A method with no `time_type` at all defaults to 'now', matching LatePoint's own default —
	 * omission must never silently become deferred.
	 */
	public function test_missing_time_type_defaults_to_now(): void {
		$result = IfthenpayLpPaymentTimes::add_methods(
			array(),
			array( 'some_method' => array( 'code' => 'some_method' ) ),
			'ifthenpay'
		);

		$this->assertArrayHasKey( 'some_method', $result[ LATEPOINT_PAYMENT_TIME_NOW ] );
	}

	/**
	 * Existing entries in $payment_times, from other processors, are preserved untouched — this
	 * is a filter callback, not a replacement.
	 */
	public function test_existing_payment_times_are_preserved(): void {
		$existing = array(
			LATEPOINT_PAYMENT_TIME_NOW => array(
				'stripe_card' => array( 'stripe' => array( 'code' => 'stripe_card' ) ),
			),
		);

		$result = IfthenpayLpPaymentTimes::add_methods(
			$existing,
			array(
				'ifthenpay_gateway' => array(
					'code'      => 'ifthenpay_checkout',
					'time_type' => 'now',
				),
			),
			'ifthenpay'
		);

		$this->assertArrayHasKey( 'stripe_card', $result[ LATEPOINT_PAYMENT_TIME_NOW ] );
		$this->assertArrayHasKey( 'ifthenpay_gateway', $result[ LATEPOINT_PAYMENT_TIME_NOW ] );
	}

	/**
	 * The processor code is the second-level key under each method — a second processor
	 * registering the same method code must not clobber this one's entry.
	 */
	public function test_method_is_keyed_under_its_processor_code(): void {
		$result = IfthenpayLpPaymentTimes::add_methods(
			array(),
			array(
				'ifthenpay_gateway' => array(
					'code'      => 'ifthenpay_checkout',
					'time_type' => 'now',
				),
			),
			'ifthenpay'
		);

		$this->assertArrayHasKey( 'ifthenpay', $result[ LATEPOINT_PAYMENT_TIME_NOW ]['ifthenpay_gateway'] );
	}
}
