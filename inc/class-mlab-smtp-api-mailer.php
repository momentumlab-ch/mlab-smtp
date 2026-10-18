<?php
/**
 * PHPMailer, der die fertige Nachricht an eine Web-API übergibt statt an einen Mailserver.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the message like PHPMailer always does and hands the MIME text to the provider API.
 */
class Mlab_Smtp_Api_Mailer extends \PHPMailer\PHPMailer\PHPMailer {

	/**
	 * Sends the prepared message through the selected provider.
	 *
	 * @throws \PHPMailer\PHPMailer\Exception When the provider rejects the message.
	 */
	public function postSend() {
		$result = mlab_smtp_dispatch( $this->getSentMIMEMessage(), $this->From ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
		if ( is_wp_error( $result ) ) {
			throw new \PHPMailer\PHPMailer\Exception( esc_html( $result->get_error_message() ) );
		}
		return true;
	}
}
