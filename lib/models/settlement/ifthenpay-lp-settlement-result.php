<?php
/**
 * The outcome of IfthenpayLpSettlement::settle_payment(). Deliberately knows nothing about HTTP;
 * each caller (the REST callback, the polling endpoint, the manual re-check action) maps a status
 * to whatever response shape it needs.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Four outcomes, matching the contract's own minimum: settled, already-settled,
 * rejected-with-reason, failed. Rejected and failed both carry a $reason, but mean different
 * things to a caller: rejected is a considered "no" (bad amount, unknown reference, cancelled
 * order) that should never be retried as-is; failed means our own side wasn't able to complete the
 * attempt (lock contention, an order not committed yet, a save error) and the same request should
 * be retried later.
 */
class IfthenpayLpSettlementResult {

	public const SETTLED         = 'settled';
	public const ALREADY_SETTLED = 'already_settled';
	public const REJECTED        = 'rejected';
	public const FAILED          = 'failed';

	/**
	 * One of the class constants above.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Machine-readable reason code — empty for SETTLED/ALREADY_SETTLED, always set for
	 * REJECTED/FAILED. Never a message meant for display; callers map it themselves.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Builds a result; use the named factories below instead of this directly.
	 *
	 * @param string $status One of the class constants above.
	 * @param string $reason Machine-readable reason code; '' when not applicable.
	 */
	private function __construct( string $status, string $reason = '' ) {
		$this->status = $status;
		$this->reason = $reason;
	}

	/**
	 * The payment settled successfully just now.
	 */
	public static function settled(): self {
		return new self( self::SETTLED );
	}

	/**
	 * The payment was already settled by an earlier call — a no-op, not an error.
	 */
	public static function already_settled(): self {
		return new self( self::ALREADY_SETTLED );
	}

	/**
	 * See the class docblock for rejected vs. failed.
	 *
	 * @param string $reason Machine-readable reason code, e.g. 'amount_mismatch'.
	 */
	public static function rejected( string $reason ): self {
		return new self( self::REJECTED, $reason );
	}

	/**
	 * See the class docblock for rejected vs. failed.
	 *
	 * @param string $reason Machine-readable reason code, e.g. 'order_not_ready'.
	 */
	public static function failed( string $reason ): self {
		return new self( self::FAILED, $reason );
	}

	/**
	 * One of the class constants above.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * The machine-readable reason code — '' for SETTLED/ALREADY_SETTLED.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * True for SETTLED or ALREADY_SETTLED — the payment is confirmed either way, which is what
	 * most callers actually branch on (e.g. "should this acknowledge success").
	 */
	public function is_settled(): bool {
		return self::SETTLED === $this->status || self::ALREADY_SETTLED === $this->status;
	}
}
