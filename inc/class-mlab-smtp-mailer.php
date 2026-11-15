<?php
/**
 * PHPMailer für alle Versandwege inklusive Ersatzweg.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-mlab-smtp-route-error.php';

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property names are the PHPMailer API.
/**
 * Builds the message like PHPMailer always does. The delivery engine (inc/engine.php) decides which route sends it:
 * the primary route (SMTP or web API) first, then the fallback. The mailer only knows how to send through one route.
 */
class Mlab_Smtp_Mailer extends \PHPMailer\PHPMailer\PHPMailer {

	/**
	 * Id of the message in the log (uuid), created with the mail.
	 *
	 * @var string
	 */
	public $mlab_uuid = '';

	/**
	 * Message-ID generated for the current mail, to tell it from one a plugin set itself.
	 *
	 * @var string
	 */
	public $mlab_msgid = '';

	/**
	 * True for a mailer the worker rebuilt from a snapshot: phpmailer_init did not run, so SMTP is applied here.
	 *
	 * @var bool
	 */
	public $mlab_worker = false;

	/**
	 * Sends the prepared message through the routes of the engine.
	 *
	 * @return bool
	 * @throws \PHPMailer\PHPMailer\Exception When no route delivered the message.
	 */
	public function postSend() {
		return mlab_smtp_engine_deliver( $this );
	}

	/**
	 * Sends through one route.
	 *
	 * @param string $route primary or fallback.
	 * @throws Mlab_Smtp_Route_Error On any failure.
	 */
	public function send_route( string $route ): void {
		if ( 'fallback' === $route ) {
			$this->deliver_fallback();
			return;
		}
		$this->send_primary( (string) mlab_smtp_get( 'provider' ) );
	}

	/**
	 * Throws a route error.
	 *
	 * @param string $message Plain text for logs.
	 * @param array  $info    Facts for the classification.
	 * @throws Mlab_Smtp_Route_Error Always.
	 */
	private function fail( string $message, array $info = array() ): void {
		$error       = new Mlab_Smtp_Route_Error( $message );
		$error->info = $info;
		throw $error;
	}

	/**
	 * Throws the error of a provider call (WP_Error with HTTP facts as data).
	 *
	 * @param WP_Error $error Error.
	 * @throws Mlab_Smtp_Route_Error Always.
	 */
	private function fail_wp_error( WP_Error $error ): void {
		$data = (array) $error->get_error_data();
		$name = (string) ( $data['name'] ?? '' );
		$this->fail(
			$error->get_error_message(),
			array(
				'transport'     => ! empty( $data['transport'] ),
				'http_status'   => (int) ( $data['status'] ?? 0 ),
				'retry_after'   => (int) ( $data['retry_after'] ?? 0 ),
				'error_code'    => '' !== $name ? $name : (string) $error->get_error_code(),
				'local_failure' => 0 === (int) ( $data['status'] ?? 0 ) && empty( $data['transport'] ),
			)
		);
	}

	/**
	 * Facts about an SMTP failure from the exception and PHPMailer's SMTP object.
	 *
	 * @param \PHPMailer\PHPMailer\Exception $e Exception of PHPMailer.
	 */
	private function smtp_info( \PHPMailer\PHPMailer\Exception $e ): array {
		$error   = (array) $this->getSMTPInstance()->getError();
		$message = $e->getMessage();
		$info    = array(
			'smtp_code'  => (int) ( $error['smtp_code'] ?? 0 ),
			'smtp_phase' => 'command',
			'partial'    => str_starts_with( $message, $this->lang( 'recipients_failed' ) ),
		);
		if ( str_starts_with( $message, $this->lang( 'data_not_accepted' ) ) ) {
			$info['smtp_phase'] = str_starts_with( (string) ( $error['error'] ?? '' ), 'DATA END' ) ? 'data_end' : 'data';
		} elseif ( str_starts_with( $message, $this->lang( 'connect_host' ) ) || str_starts_with( $message, $this->lang( 'authenticate' ) ) ) {
			$info['smtp_phase'] = 'connect';
		}
		// An SMTP error after the DATA command may already have delivered the message (wholly or to some recipients): no fallback.
		$info['no_fallback'] = $info['partial'] || in_array( $info['smtp_phase'], array( 'data', 'data_end' ), true );
		return $info;
	}

	/**
	 * Sends through the primary route.
	 *
	 * @param string $provider smtp, m365, google or resend.
	 * @throws Mlab_Smtp_Route_Error On any failure.
	 */
	private function send_primary( string $provider ): void {
		if ( ! mlab_smtp_has_credentials() ) {
			$this->fail( mlab_smtp_credentials_error(), array( 'local_failure' => true ) );
		}
		if ( ! mlab_smtp_provider_def( $provider )['api'] ) {
			$this->send_smtp_primary();
			return;
		}
		if ( $this->mlab_worker ) {
			$this->Mailer = 'sendmail'; // Keeps the Bcc header in the message, which the APIs need.
		}
		// Every API route needs a sender address, then hands the prepared message to its own sender.
		$result = mlab_smtp_sender_error() ?? call_user_func( mlab_smtp_provider_def( $provider )['send'], $this );
		if ( is_wp_error( $result ) ) {
			$this->fail_wp_error( $result );
		}
	}

	/**
	 * Sends through the SMTP server of the primary route.
	 *
	 * @throws Mlab_Smtp_Route_Error On any failure.
	 */
	private function send_smtp_primary(): void {
		if ( $this->mlab_worker ) {
			mlab_smtp_apply_smtp( $this, '' );
			$this->preSend();
		}
		try {
			mlab_smtp_engine_io_start();
			if ( ! parent::postSend() ) {
				$this->fail( $this->ErrorInfo, $this->smtp_info( new \PHPMailer\PHPMailer\Exception( $this->ErrorInfo ) ) );
			}
		} catch ( Mlab_Smtp_Route_Error $e ) {
			throw $e;
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			$this->fail( $e->getMessage(), $this->smtp_info( $e ) );
		}
	}

	/**
	 * Hands the message to the chosen fallback provider.
	 *
	 * @throws Mlab_Smtp_Route_Error When the fallback is not set up or does not deliver.
	 */
	private function deliver_fallback(): void {
		if ( ! mlab_smtp_fallback_ready() ) {
			$this->fail( __( 'Der Ersatzweg ist nicht eingerichtet.', 'mlab-smtp' ), array( 'local_failure' => true ) );
		}
		$send = mlab_smtp_provider_def( (string) mlab_smtp_get( 'fb_provider' ) )['fb_send'];
		if ( $send ) {
			$result = call_user_func( $send, $this );
			if ( is_wp_error( $result ) ) {
				$this->fail_wp_error( $result );
			}
			return;
		}
		$this->smtpClose();
		mlab_smtp_apply_smtp( $this, 'fb_' );
		try {
			// Rebuild header and body: an API route keeps the Bcc header in the message, SMTP must not.
			if ( ! $this->preSend() ) {
				$this->fail( $this->ErrorInfo, array( 'local_failure' => true ) );
			}
			mlab_smtp_engine_io_start();
			if ( ! $this->smtpSend( $this->MIMEHeader, $this->MIMEBody ) ) {
				$this->fail( $this->ErrorInfo, $this->smtp_info( new \PHPMailer\PHPMailer\Exception( $this->ErrorInfo ) ) );
			}
		} catch ( Mlab_Smtp_Route_Error $e ) {
			throw $e;
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			$this->fail( $e->getMessage(), $this->smtp_info( $e ) );
		}
	}
}
// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
