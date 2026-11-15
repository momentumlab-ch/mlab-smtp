<?php
/**
 * Fehler eines Versandwegs mit den Angaben für die Klassifizierung.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exception of a route with the facts the engine needs to classify the failure (HTTP status, Retry-After, SMTP phase).
 */
class Mlab_Smtp_Route_Error extends \PHPMailer\PHPMailer\Exception {

	/**
	 * Facts about the failure: http_status, retry_after, transport, error_code, smtp_phase, smtp_code, partial, no_fallback.
	 *
	 * @var array
	 */
	public $info = array();
}
