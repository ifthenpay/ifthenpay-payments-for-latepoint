<?php
/**
 * Base exception for IfthenpayLpApiClient.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base for every exception this client throws — catch this to handle any ifthenpay call failure
 * generically, or catch the specific subclass to branch on terminal-vs-transport.
 */
class IfthenpayLpApiException extends Exception {}
