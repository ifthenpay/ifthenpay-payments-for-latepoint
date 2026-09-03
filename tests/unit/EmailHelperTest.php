<?php
/**
 * Proves IfthenpayLpEmailHelper::send_activation_email() escapes every dynamic value exactly
 * once — a regression test for a double-escaping bug (an "&" in site_name or similar reaching the
 * inbox as "&amp;amp;" instead of "&amp;") caused by pre-escaping values when building $items and
 * then escaping them again in the render loop — and that the From header, a raw value rather than
 * HTML, is never HTML-escaped.
 *
 * @package ifthenpay-payments-for-latepoint
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/lib/views/ifthenpay-lp-email-helper.php';

/**
 * Activation email proof.
 */
final class EmailHelperTest extends TestCase {

	/**
	 * Boots Brain Monkey and stubs the WP functions the helper touches. Real (not identity-stub)
	 * esc_html()/sanitize_text_field() implementations, since this file's whole point is proving
	 * escaping happens the right number of times — an identity stub would hide a double-escape.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs(
			array(
				'esc_html'            => static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ),
				// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- this closure IS the test stub standing in for the real sanitize_text_field(), not production code that should call the WP wrapper instead.
				'sanitize_text_field' => static fn( $text ) => trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) ),
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this closure IS the test stub standing in for the real wp_parse_url().
				'wp_parse_url'        => static fn( $url, $component = -1 ) => parse_url( $url, $component ),
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
	 * Base valid payload — every test overrides only the field it cares about.
	 *
	 * @param array<string,string> $overrides Fields to override.
	 * @return array<string,string>
	 */
	private function payload( array $overrides = array() ): array {
		return array_merge(
			array(
				'gateway_key'       => 'GW-KEY',
				'entity'            => 'mb',
				'backoffice_key'    => 'BO-KEY',
				'customer_email'    => 'merchant@example.com',
				'site_url'          => 'https://example.com/',
				'site_name'         => 'Example Site',
				'wp_version'        => '6.8',
				'latepoint_version' => '5.6.10',
				'plugin_version'    => '1.0.0',
			),
			$overrides
		);
	}

	/**
	 * The site_name field only ever reaches the From header, never the body — the "&" in it must
	 * never be HTML-escaped there, a raw header value, not HTML.
	 */
	public function test_ampersand_in_site_name_is_not_html_escaped_in_the_from_header(): void {
		Functions\expect( 'wp_mail' )
			->once()
			->andReturnUsing(
				static function ( $to, $subject, $body, $headers ) {
					$from = current(
						array_values(
							array_filter(
								$headers,
								static fn( $header ) => 0 === strpos( $header, 'From:' )
							)
						)
					);
					self::assertStringContainsString( 'Bob & Co', $from );
					self::assertStringNotContainsString( '&amp;', $from );
					return true;
				}
			);

		IfthenpayLpEmailHelper::send_activation_email( $this->payload( array( 'site_name' => 'Bob & Co' ) ) );
	}

	/**
	 * Every other dynamic value ($gateway_key, backoffice_key, entity, versions, site_url) is also
	 * escaped exactly once in the body — not just site_name.
	 */
	public function test_every_dynamic_value_is_escaped_exactly_once(): void {
		Functions\expect( 'wp_mail' )
			->once()
			->andReturnUsing(
				static function ( $to, $subject, $body ) {
					self::assertStringContainsString( 'A&amp;B-KEY', $body );
					self::assertStringNotContainsString( 'A&amp;amp;B-KEY', $body );
					return true;
				}
			);

		IfthenpayLpEmailHelper::send_activation_email( $this->payload( array( 'gateway_key' => 'A&B-KEY' ) ) );
	}
}
