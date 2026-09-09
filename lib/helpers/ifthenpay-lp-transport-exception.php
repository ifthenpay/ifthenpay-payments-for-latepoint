<?php
/**
 * Transport-failure exception for IfthenpayLpApiClient.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything else: no route to ifthenpay, a timeout, a 5xx, or a body that could not be parsed
 * when JSON was expected. Says nothing about the credentials — callers fail open on the settings
 * page and fail closed at checkout, never the reverse.
 */
class IfthenpayLpTransportException extends IfthenpayLpApiException {}
