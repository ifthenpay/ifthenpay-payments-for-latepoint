<?php
/**
 * Credential-rejection exception for IfthenpayLpApiClient.
 *
 * @package ifthenpay-payments-for-latepoint
 */

// phpcs:disable Generic.Commenting.DocComment.ShortNotCapital -- "ifthenpay" is a proper noun, always lowercase.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ifthenpay rejected the credentials (HTTP 401/403). Terminal — do not retry, and this is the
 * one failure mode that says something concrete about the key itself.
 */
class IfthenpayLpCredentialException extends IfthenpayLpApiException {}
