<?php
/**
 * Proves IfthenpayLpPaymentProcessor::backfill_realtime_transaction_notes() — the
 * `latepoint_transaction_created` hook that gives a realtime (Pay By Link) transaction the same
 * kind of notes IfthenpayLpSettlement::apply_state_change() already gives a deferred one, since
 * LatePoint core's own transaction creation for that path never sets notes at all.
 *
 * @package ifthenpay-payments-for-latepoint
 */

// phpcs:ignore Squiz.Commenting.FileComment.Missing -- docblock above is the file comment; the sniff misclassifies it when a require is the first statement.
require_once __DIR__ . '/../support/class-latepoint-order-fixture.php';

/**
 * Realtime transaction notes backfill proof.
 */
class RealtimeTransactionNotesTest extends WP_UnitTestCase {

	/**
	 * Builds a real, saved OsTransactionModel matching what LatePoint core's own
	 * process_payment_for_order_intent() consumer creates for a realtime payment.
	 *
	 * @param object $fixture   As returned by ifthenpay_lp_create_order_fixture().
	 * @param string $token     The transaction's own token.
	 * @param string $processor 'ifthenpay' by default.
	 */
	private function create_transaction( object $fixture, string $token, string $processor = 'ifthenpay' ): OsTransactionModel {
		$transaction                  = new OsTransactionModel();
		$transaction->token           = $token;
		$transaction->order_id        = $fixture->order->id;
		$transaction->customer_id     = $fixture->customer->id;
		$transaction->processor       = $processor;
		$transaction->amount          = '25.00';
		$transaction->payment_method  = 'MBWAY';
		$transaction->payment_portion = LATEPOINT_PAYMENT_PORTION_FULL;
		$transaction->kind            = LATEPOINT_TRANSACTION_KIND_CAPTURE;
		$transaction->status          = LATEPOINT_TRANSACTION_STATUS_SUCCEEDED;
		$transaction->save();

		return $transaction;
	}

	/**
	 * A realtime, PAID row with a recorded txid gets its transaction's notes backfilled.
	 */
	public function test_backfills_notes_for_a_realtime_paid_transaction(): void {
		$fixture = ifthenpay_lp_create_order_fixture();
		IfthenpayLpTransactionRepository::insert(
			array(
				'token'       => 'tok-realtime-notes',
				'intent_id'   => $fixture->order_intent->id,
				'kind'        => 'realtime',
				'method'      => 'MBWAY',
				'status'      => 'PAID',
				'method_data' => wp_json_encode( array( 'transaction_id' => 'TXID-NOTES-001' ) ),
			)
		);
		$transaction = $this->create_transaction( $fixture, 'tok-realtime-notes' );

		IfthenpayLpPaymentProcessor::backfill_realtime_transaction_notes( $transaction );

		$reloaded = new OsTransactionModel( $transaction->id );
		$this->assertSame( "ifthenpay transaction ID: TXID-NOTES-001\nSettled via: polling", $reloaded->notes );
	}

	/**
	 * A transaction with no recorded txid still gets a note, just without that line.
	 */
	public function test_backfills_notes_without_a_txid_line_when_none_was_recorded(): void {
		$fixture = ifthenpay_lp_create_order_fixture();
		IfthenpayLpTransactionRepository::insert(
			array(
				'token'     => 'tok-realtime-no-txid',
				'intent_id' => $fixture->order_intent->id,
				'kind'      => 'realtime',
				'method'    => 'MBWAY',
				'status'    => 'PAID',
			)
		);
		$transaction = $this->create_transaction( $fixture, 'tok-realtime-no-txid' );

		IfthenpayLpPaymentProcessor::backfill_realtime_transaction_notes( $transaction );

		$reloaded = new OsTransactionModel( $transaction->id );
		$this->assertSame( 'Settled via: polling', $reloaded->notes );
	}

	/**
	 * A transaction from a different processor is never touched — this hook fires for every
	 * transaction LatePoint creates, not only ifthenpay's own.
	 */
	public function test_does_not_touch_a_transaction_from_another_processor(): void {
		$fixture = ifthenpay_lp_create_order_fixture();
		IfthenpayLpTransactionRepository::insert(
			array(
				'token'     => 'tok-other-processor',
				'intent_id' => $fixture->order_intent->id,
				'kind'      => 'realtime',
				'method'    => 'MBWAY',
				'status'    => 'PAID',
			)
		);
		$transaction = $this->create_transaction( $fixture, 'tok-other-processor', 'stripe' );

		IfthenpayLpPaymentProcessor::backfill_realtime_transaction_notes( $transaction );

		$reloaded = new OsTransactionModel( $transaction->id );
		$this->assertSame( '', (string) $reloaded->notes );
	}

	/**
	 * A transaction that already has notes (the deferred/callback/manual path, via
	 * IfthenpayLpSettlement::apply_state_change(), sets them before its own save()) is never
	 * overwritten.
	 */
	public function test_does_not_overwrite_existing_notes(): void {
		$fixture = ifthenpay_lp_create_order_fixture();
		IfthenpayLpTransactionRepository::insert(
			array(
				'token'     => 'tok-already-noted',
				'intent_id' => $fixture->order_intent->id,
				'kind'      => 'realtime',
				'method'    => 'MBWAY',
				'status'    => 'PAID',
			)
		);
		$transaction = $this->create_transaction( $fixture, 'tok-already-noted' );
		$transaction->update_attributes( array( 'notes' => 'Settled via: callback' ) );

		IfthenpayLpPaymentProcessor::backfill_realtime_transaction_notes( $transaction );

		$reloaded = new OsTransactionModel( $transaction->id );
		$this->assertSame( 'Settled via: callback', $reloaded->notes );
	}

	/**
	 * A deferred row is left alone — it already gets its own notes via apply_state_change(), and
	 * this backfill is scoped to realtime rows only.
	 */
	public function test_does_nothing_for_a_deferred_row(): void {
		$fixture = ifthenpay_lp_create_order_fixture();
		IfthenpayLpTransactionRepository::insert(
			array(
				'token'     => 'tok-deferred-row',
				'intent_id' => $fixture->order_intent->id,
				'kind'      => 'deferred',
				'method'    => 'MB',
				'status'    => 'PAID',
			)
		);
		$transaction = $this->create_transaction( $fixture, 'tok-deferred-row' );

		IfthenpayLpPaymentProcessor::backfill_realtime_transaction_notes( $transaction );

		$reloaded = new OsTransactionModel( $transaction->id );
		$this->assertSame( '', (string) $reloaded->notes );
	}
}
