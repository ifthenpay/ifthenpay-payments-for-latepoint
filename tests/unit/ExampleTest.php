<?php
/**
 * Proves the unit harness (PHPUnit + Brain Monkey, no WordPress booted) runs
 * against real plugin code.
 *
 * @package ifthenpay-payments-for-latepoint
 */

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/helpers/ifthenpay-data-formatter.php';

/**
 * Unit test harness proof.
 */
final class ExampleTest extends TestCase {

	/**
	 * Boots Brain Monkey before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tears down Brain Monkey after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Asserts the raw gateway list gets indexed by Alias.
	 */
	public function test_format_gateway_keys_indexes_by_alias(): void {
		$raw = array(
			array(
				'Alias'      => 'Main',
				'GatewayKey' => 'ABC123',
			),
			array(
				'Alias'      => 'Secondary',
				'GatewayKey' => 'DEF456',
			),
		);

		$this->assertSame(
			array(
				'Main'      => 'ABC123',
				'Secondary' => 'DEF456',
			),
			// @phpstan-ignore-next-line class.notFound (loaded via require_once above; nothing autoloads in this plugin, so PHPStan can't see it when tests/ is analysed on its own)
			IfthenpayDataFormatter::format_gateway_keys( $raw )
		);
	}
}
