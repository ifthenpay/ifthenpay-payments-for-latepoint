<?php
/**
 * Proves IfthenpayLpTransactionRepository::find_unclaimed_realtime()/mark_resolved() — the
 * ifthenpay Tools page's own "Unclaimed Realtime Payments" listing, for a realtime payment that
 * settled PAID but was never claimed by a real LatePoint transaction (the customer's browser died
 * before convert_to_order() ran, or a retry paid and converted separately, leaving this one a
 * genuine second charge). One test per guarantee the detection query and the resolve action make.
 *
 * @package ifthenpay-payments-for-latepoint
 */

// phpcs:ignore Squiz.Commenting.FileComment.Missing -- docblock above is the file comment; the sniff misclassifies it when a require is the first statement.
require_once __DIR__ . '/../support/class-latepoint-order-fixture.php';

/**
 * Unclaimed realtime payments proof.
 */
class UnclaimedRealtimeTest extends WP_UnitTestCase {

	/**
	 * A PAID realtime row, settled well past the grace period and never claimed — the baseline
	 * shape every other test case in this file deviates from exactly one way at a time.
	 *
	 * @param array<string,mixed> $overrides Any repository column to override.
	 */
	private function seed_unclaimed_row( array $overrides = array() ): string {
		$token = 'ifp-lp-unclaimed-' . wp_generate_password( 12, false );

		IfthenpayLpTransactionRepository::insert(
			array_merge(
				array(
					'token'      => $token,
					'intent_id'  => 999999,
					'kind'       => 'realtime',
					'method'     => IfthenpayLpTransactionRepository::METHOD_PAYBYLINK,
					'status'     => 'PAID',
					'settled_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				),
				$overrides
			)
		);

		return $token;
	}

	/**
	 * The baseline case: past the grace period, never claimed, never resolved — included.
	 */
	public function test_includes_a_genuinely_unclaimed_row(): void {
		$token = $this->seed_unclaimed_row();

		$tokens = wp_list_pluck( IfthenpayLpTransactionRepository::find_unclaimed_realtime(), 'token' );

		$this->assertContains( $token, $tokens );
	}

	/**
	 * A row settled moments ago is still genuinely in flight — the customer's own browser may still
	 * be mid-submit. Must not be flagged before the grace period has actually passed.
	 */
	public function test_excludes_a_row_still_within_the_grace_period(): void {
		$token = $this->seed_unclaimed_row( array( 'settled_at' => current_time( 'mysql', true ) ) );

		$tokens = wp_list_pluck( IfthenpayLpTransactionRepository::find_unclaimed_realtime(), 'token' );

		$this->assertNotContains( $token, $tokens );
	}

	/**
	 * A row a real LatePoint transaction exists for (a matching token in LatePoint core's own
	 * transactions table) was genuinely claimed by a real booking — excluded regardless of what the
	 * shared order_intent's own current state happens to say, which is exactly the point: detection
	 * is by token, never by the order_intent.
	 */
	public function test_excludes_a_row_claimed_by_a_real_latepoint_transaction(): void {
		$token = $this->seed_unclaimed_row();
		ifthenpay_lp_insert_latepoint_transaction( $token );

		$tokens = wp_list_pluck( IfthenpayLpTransactionRepository::find_unclaimed_realtime(), 'token' );

		$this->assertNotContains( $token, $tokens );
	}

	/**
	 * A row already marked resolved by a merchant stays out of the listing even though nothing
	 * about the payment itself changed.
	 */
	public function test_excludes_an_already_resolved_row(): void {
		$token = $this->seed_unclaimed_row();
		IfthenpayLpTransactionRepository::mark_resolved( $token );

		$tokens = wp_list_pluck( IfthenpayLpTransactionRepository::find_unclaimed_realtime(), 'token' );

		$this->assertNotContains( $token, $tokens );
	}

	/**
	 * A deferred row, however PAID/old/unclaimed it might otherwise look, is never this listing's
	 * concern — that's IfthenpayLpTransactionRepository::find_pending_deferred()'s own table.
	 */
	public function test_excludes_a_deferred_row(): void {
		$token = $this->seed_unclaimed_row( array( 'kind' => 'deferred' ) );

		$tokens = wp_list_pluck( IfthenpayLpTransactionRepository::find_unclaimed_realtime(), 'token' );

		$this->assertNotContains( $token, $tokens );
	}

	/**
	 * A still-PENDING realtime row (checkout abandoned before ever paying — nothing was captured)
	 * is not "unclaimed money"; nothing to flag.
	 */
	public function test_excludes_a_row_that_never_settled(): void {
		$token = $this->seed_unclaimed_row(
			array(
				'status'     => 'PENDING',
				'settled_at' => null,
			)
		);

		$tokens = wp_list_pluck( IfthenpayLpTransactionRepository::find_unclaimed_realtime(), 'token' );

		$this->assertNotContains( $token, $tokens );
	}

	/**
	 * The mark_resolved() call drops the row out of the listing, stamps resolved_at, and — since it goes
	 * through the repository's own generic update_columns() — correctly invalidates whatever
	 * find_by_token() may have already cached for this row, proving that shared cache-invalidation
	 * path still works for this new column without any change of its own.
	 */
	public function test_mark_resolved_removes_the_row_and_invalidates_the_cache(): void {
		$token = $this->seed_unclaimed_row();

		// Warms find_by_token()'s own wp_cache entry with the pre-resolve state.
		$before = IfthenpayLpTransactionRepository::find_by_token( $token );
		$this->assertNull( $before->resolved_at ); // @phpstan-ignore-line property.notFound

		$this->assertTrue( IfthenpayLpTransactionRepository::mark_resolved( $token ) );

		$tokens = wp_list_pluck( IfthenpayLpTransactionRepository::find_unclaimed_realtime(), 'token' );
		$this->assertNotContains( $token, $tokens );

		$after = IfthenpayLpTransactionRepository::find_by_token( $token );
		$this->assertNotNull( $after->resolved_at ); // @phpstan-ignore-line property.notFound
	}
}
