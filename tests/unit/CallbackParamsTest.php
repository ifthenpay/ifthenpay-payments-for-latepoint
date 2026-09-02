<?php
/**
 * Proves IfthenpayLpCallbackParams: required-parameter validation, anti-phishing key comparison,
 * and amount comparison — the pure logic behind the inbound callback, exercised here against the
 * saved fixtures under tests/fixtures/callbacks/ so a change to those fixtures is caught here too.
 *
 * @package ifthenpay-payments-for-latepoint
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/helpers/ifthenpay-lp-callback-params.php';
require_once __DIR__ . '/../support/ifthenpay-callback-fixtures.php';

/**
 * Callback parameter parsing/validation proof.
 */
final class CallbackParamsTest extends TestCase {

	/**
	 * A well-shaped, valid-looking callback parses into every field, `apk` still encoded.
	 */
	public function test_valid_fixture_parses_every_field(): void {
		$params = IfthenpayLpCallbackParams::from_array( ifthenpay_lp_callback_fixture_params( 'valid-multibanco.txt' ) );

		$this->assertNotNull( $params );
		$this->assertSame( 'lp-order-tok-abc123', $params->reference );
		$this->assertSame( '25.00', $params->amount );
		$this->assertSame( 'MB', $params->method );
		$this->assertSame( 'REQ-VALID-0001', $params->request_id );
	}

	/**
	 * A request shaped for the old, per-method Payshop registration (`anti_phishing_key`,
	 * `order_id`) has none of our required keys and is rejected outright — no per-method branching
	 * needed, per contracts/callback.md.
	 */
	public function test_payshop_shaped_request_is_rejected_for_missing_required_params(): void {
		$params = IfthenpayLpCallbackParams::from_array( ifthenpay_lp_callback_fixture_params( 'payshop-shaped.txt' ) );

		$this->assertNull( $params );
	}

	/**
	 * Missing any one of the three required parameters is rejected, not just all of them.
	 */
	public function test_missing_apk_is_rejected(): void {
		$params = IfthenpayLpCallbackParams::from_array(
			array(
				'amount'    => '10.00',
				'reference' => 'lp-order-tok-xyz',
			)
		);

		$this->assertNull( $params );
	}

	/**
	 * The correct gateway key, base64-decoded from `apk`, matches in constant time.
	 */
	public function test_correct_gateway_key_matches(): void {
		$params = IfthenpayLpCallbackParams::from_array( ifthenpay_lp_callback_fixture_params( 'valid-multibanco.txt' ) );

		$this->assertTrue( $params->matches_gateway_key( ifthenpay_lp_callback_fixture_gateway_key() ) );
	}

	/**
	 * A wrong `apk` — decodes to a different key — does not match.
	 */
	public function test_wrong_gateway_key_does_not_match(): void {
		$params = IfthenpayLpCallbackParams::from_array( ifthenpay_lp_callback_fixture_params( 'bad-key.txt' ) );

		$this->assertFalse( $params->matches_gateway_key( ifthenpay_lp_callback_fixture_gateway_key() ) );
	}

	/**
	 * An empty stored gateway key never matches, regardless of what `apk` decodes to — a record
	 * with no gateway key on it must never be treated as pre-authenticated.
	 */
	public function test_empty_stored_gateway_key_never_matches(): void {
		$params = IfthenpayLpCallbackParams::from_array( ifthenpay_lp_callback_fixture_params( 'valid-multibanco.txt' ) );

		$this->assertFalse( $params->matches_gateway_key( '' ) );
	}

	/**
	 * `apk` that isn't valid base64 at all is rejected, not fatally erroring.
	 */
	public function test_undecodable_apk_does_not_match(): void {
		$params = IfthenpayLpCallbackParams::from_array(
			array(
				'amount'    => '10.00',
				'reference' => 'lp-order-tok-xyz',
				'apk'       => '***not-base64***',
			)
		);

		$this->assertFalse( $params->matches_gateway_key( ifthenpay_lp_callback_fixture_gateway_key() ) );
	}

	/**
	 * Amount is compared as formatted strings — an exact match passes.
	 */
	public function test_matching_amount_passes(): void {
		$params = IfthenpayLpCallbackParams::from_array( ifthenpay_lp_callback_fixture_params( 'valid-multibanco.txt' ) );

		$this->assertTrue( $params->amount_matches( '25.00' ) );
	}

	/**
	 * The wrong-amount fixture's notified amount does not match the order total it claims to pay.
	 */
	public function test_wrong_amount_fixture_does_not_match_order_total(): void {
		$params = IfthenpayLpCallbackParams::from_array( ifthenpay_lp_callback_fixture_params( 'wrong-amount.txt' ) );

		$this->assertFalse( $params->amount_matches( '25.00' ) );
	}

	/**
	 * `request_id` is treated as an opaque string — a legacy `"0"` value parses and round-trips
	 * exactly, never coerced or treated as falsy/missing.
	 */
	public function test_legacy_zero_request_id_is_preserved_as_a_string(): void {
		$params = IfthenpayLpCallbackParams::from_array( ifthenpay_lp_callback_fixture_params( 'requestid-legacy-zero.txt' ) );

		$this->assertNotNull( $params );
		$this->assertSame( '0', $params->request_id );
	}

	/**
	 * The duplicate fixture is byte-identical to the valid one — it represents a retried delivery
	 * of the same notification, not a different shape.
	 */
	public function test_duplicate_fixture_matches_the_valid_one(): void {
		$this->assertSame(
			ifthenpay_lp_callback_fixture( 'valid-multibanco.txt' ),
			ifthenpay_lp_callback_fixture( 'duplicate.txt' )
		);
	}

	/**
	 * The unknown-reference fixture parses fine — rejection happens later, at the repository
	 * lookup, not at parameter-parsing time.
	 */
	public function test_unknown_reference_fixture_parses_but_is_a_different_token(): void {
		$params = IfthenpayLpCallbackParams::from_array( ifthenpay_lp_callback_fixture_params( 'unknown-reference.txt' ) );

		$this->assertNotNull( $params );
		$this->assertSame( 'lp-order-tok-does-not-exist', $params->reference );
	}
}
