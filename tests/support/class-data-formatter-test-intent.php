<?php
/**
 * A minimal OsOrderIntentModel/OsTransactionIntentModel stand-in for unit tests, which never boot
 * LatePoint — only the surface IfthenpayLpDataFormatter::build_pay_by_link_payload() itself touches.
 *
 * @package ifthenpay-payments-for-latepoint
 */

/**
 * Intent stand-in.
 */
class IfthenpayLpDataFormatterTestIntent {

	/**
	 * Intent id.
	 *
	 * @var int
	 */
	public int $id;

	/**
	 * What get_page_url_with_intent() returns.
	 *
	 * @var string
	 */
	private string $page_url_with_intent;

	/**
	 * Seeds the id and the URL get_page_url_with_intent() returns.
	 *
	 * @param int    $id                   Intent id.
	 * @param string $page_url_with_intent What get_page_url_with_intent() should return.
	 */
	public function __construct( int $id, string $page_url_with_intent ) {
		$this->id                   = $id;
		$this->page_url_with_intent = $page_url_with_intent;
	}

	/**
	 * Returns the seeded page URL.
	 *
	 * @return string
	 */
	public function get_page_url_with_intent(): string {
		return $this->page_url_with_intent;
	}
}
