<?php
/**
 * PHPMailer für alle Versandwege inklusive Ersatzweg.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property names are the PHPMailer API.
/**
 * Builds the message like PHPMailer always does. The primary route (SMTP or web API) sends it; if that fails
 * and the fallback is active, the same message goes out through the fallback SMTP server. wp_mail() therefore
 * returns true whenever one of the two delivered.
 */
class Mlab_Smtp_Mailer extends \PHPMailer\PHPMailer\PHPMailer {

	/**
	 * Sends the prepared message.
	 *
	 * @return bool
	 * @throws \PHPMailer\PHPMailer\Exception When no route delivered the message.
	 */
	public function postSend() {
		$provider = (string) mlab_smtp_get( 'provider' );
		$fallback = mlab_smtp_fallback_ready();
		if ( mlab_smtp_flag( 'fallback_only' ) ) {
			return $this->send_fallback( '' );
		}
		try {
			$this->send_primary( $provider );
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			if ( ! $fallback ) {
				mlab_smtp_route_result(
					array(
						'via'           => $provider,
						'fallback'      => false,
						'primary_error' => $e->getMessage(),
					)
				);
				throw $e;
			}
			return $this->send_fallback( $e->getMessage() );
		}
		mlab_smtp_route_result(
			array(
				'via'           => $provider,
				'fallback'      => false,
				'primary_error' => '',
			)
		);
		return true;
	}

	/**
	 * Sends through the primary route.
	 *
	 * @param string $provider smtp, m365 or google.
	 * @throws \PHPMailer\PHPMailer\Exception On any failure.
	 */
	private function send_primary( string $provider ): void {
		if ( 'smtp' === $provider ) {
			if ( ! parent::postSend() ) {
				throw new \PHPMailer\PHPMailer\Exception( $this->ErrorInfo ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for logs; escaped on output.
			}
			return;
		}
		$result = mlab_smtp_dispatch( $this->getSentMIMEMessage(), $this->From );
		if ( is_wp_error( $result ) ) {
			throw new \PHPMailer\PHPMailer\Exception( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for logs; escaped on output.
		}
	}

	/**
	 * Sends the same message through the fallback SMTP server.
	 *
	 * @param string $primary_error Error of the primary route, empty when it was skipped.
	 * @return bool
	 * @throws \PHPMailer\PHPMailer\Exception When the fallback fails too; the text names both errors.
	 */
	private function send_fallback( string $primary_error ): bool {
		$provider = (string) mlab_smtp_get( 'provider' );
		try {
			if ( ! mlab_smtp_fallback_ready() ) {
				throw new \PHPMailer\PHPMailer\Exception( __( 'Der Ersatzweg ist nicht eingerichtet.', 'mlab-smtp' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for logs; escaped on output.
			}
			$this->smtpClose();
			mlab_smtp_apply_smtp( $this, 'fb_' );
			// Rebuild header and body: an API route keeps the Bcc header in the message, SMTP must not.
			if ( ! $this->preSend() || ! $this->smtpSend( $this->MIMEHeader, $this->MIMEBody ) ) {
				throw new \PHPMailer\PHPMailer\Exception( $this->ErrorInfo ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for logs; escaped on output.
			}
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			mlab_smtp_route_result(
				array(
					'via'           => $provider,
					'fallback'      => false,
					'primary_error' => $primary_error,
				)
			);
			$message = '' === $primary_error
				/* translators: %s: error of the fallback route */
				? sprintf( __( 'Ersatzweg: %s', 'mlab-smtp' ), $e->getMessage() )
				/* translators: 1: error of the main route, 2: error of the fallback route */
				: sprintf( __( 'Hauptweg: %1$s | Ersatzweg: %2$s', 'mlab-smtp' ), $primary_error, $e->getMessage() );
			throw new \PHPMailer\PHPMailer\Exception( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for logs; escaped on output.
		}
		mlab_smtp_route_result(
			array(
				'via'           => 'smtp',
				'fallback'      => '' !== $primary_error,
				'primary_error' => $primary_error,
			)
		);
		return true;
	}
}
// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
