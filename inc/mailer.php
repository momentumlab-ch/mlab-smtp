<?php
/**
 * Hängt sich in wp_mail(): SMTP-Zugangsdaten setzen oder die Nachricht an Microsoft 365 bzw. Google übergeben.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'pre_wp_mail', 'mlab_smtp_prepare_mailer' );
add_action( 'phpmailer_init', 'mlab_smtp_configure_mailer', 20 );
add_filter( 'wp_mail_from', 'mlab_smtp_filter_from_email', 99 );
add_filter( 'wp_mail_from_name', 'mlab_smtp_filter_from_name', 99 );

/**
 * True when the selected provider sends through a web API instead of SMTP.
 */
function mlab_smtp_is_api_provider(): bool {
	return in_array( mlab_smtp_get( 'provider' ), array( 'm365', 'google' ), true );
}

/**
 * True when the configured sender replaces whatever WordPress or other plugins set.
 * Microsoft 365 and Google only send from the connected mailbox, so they always force it.
 */
function mlab_smtp_forces_sender(): bool {
	return mlab_smtp_is_api_provider() || (bool) mlab_smtp_get( 'force_from' );
}

/**
 * Makes sure wp_mail() works with the right PHPMailer class before it builds the message.
 *
 * @param mixed $short_circuit Value of the pre_wp_mail filter, passed through unchanged.
 * @return mixed
 */
function mlab_smtp_prepare_mailer( $short_circuit ) {
	global $phpmailer;

	if ( mlab_smtp_is_api_provider() ) {
		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		require_once MLAB_SMTP_DIR . 'inc/class-mlab-smtp-api-mailer.php';
		if ( ! $phpmailer instanceof Mlab_Smtp_Api_Mailer ) {
			$phpmailer = new Mlab_Smtp_Api_Mailer( true ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- wp_mail() reuses this global.
		}
	} elseif ( $phpmailer instanceof Mlab_Smtp_Api_Mailer ) {
		$phpmailer = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Let wp_mail() create its own PHPMailer again.
	}
	return $short_circuit;
}

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property names are the PHPMailer API.
/**
 * Applies the SMTP settings to PHPMailer, or marks the message for the API transport.
 *
 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer object of wp_mail().
 */
function mlab_smtp_configure_mailer( $phpmailer ): void {
	if ( mlab_smtp_is_api_provider() ) {
		if ( $phpmailer instanceof Mlab_Smtp_Api_Mailer ) {
			// Anything but "mail" keeps the Bcc header in the message, which the APIs need.
			$phpmailer->Mailer = 'sendmail';
		}
		return;
	}
	if ( '' === mlab_smtp_get( 'smtp_host' ) ) {
		return;
	}

	$secure = (string) mlab_smtp_get( 'smtp_secure' );

	$phpmailer->isSMTP();
	$phpmailer->Host        = (string) mlab_smtp_get( 'smtp_host' );
	$phpmailer->Port        = (int) mlab_smtp_get( 'smtp_port' );
	$phpmailer->SMTPSecure  = 'none' === $secure ? '' : $secure;
	$phpmailer->SMTPAutoTLS = 'tls' === $secure;
	$phpmailer->Timeout     = MLAB_SMTP_TIMEOUT;
	// PHPMailer waits up to 300 seconds for each server reply by default. A silent server (wrong port,
	// wrong encryption, delayed answer after a wrong password) then blocks the page until the 504.
	$phpmailer->getSMTPInstance()->Timelimit = MLAB_SMTP_TIMEOUT;
	$phpmailer->SMTPAuth                     = (bool) mlab_smtp_get( 'smtp_auth' );
	if ( $phpmailer->SMTPAuth ) {
		$phpmailer->Username = (string) mlab_smtp_get( 'smtp_user' );
		$phpmailer->Password = (string) mlab_smtp_get( 'smtp_pass' );
	}
	if ( apply_filters( 'mlab_smtp_debug', false ) ) {
		$phpmailer->SMTPDebug   = 2;
		$phpmailer->Debugoutput = 'mlab_smtp_debug_output';
	}
}
// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

/**
 * Passes one line of the SMTP conversation on, with login data masked.
 *
 * @param string $line One debug line from PHPMailer.
 */
function mlab_smtp_debug_output( $line ): void {
	static $secret_expected = false;

	$line = trim( (string) $line );
	if ( str_starts_with( $line, 'CLIENT -> SERVER: ' ) ) {
		// After a 334 prompt the client sends user name or password; AUTH PLAIN carries them inline.
		if ( $secret_expected ) {
			$line = 'CLIENT -> SERVER: ********';
		}
		$line = (string) preg_replace( '/^(CLIENT -> SERVER: AUTH \S+) \S+$/', '$1 ********', $line );
	}
	$secret_expected = str_starts_with( $line, 'SERVER -> CLIENT: 334' );
	do_action( 'mlab_smtp_debug_line', $line );
}

/**
 * Sender address.
 *
 * @param string $email Address set by WordPress or another plugin.
 */
function mlab_smtp_filter_from_email( $email ) {
	$configured = (string) mlab_smtp_get( 'from_email' );
	return '' !== $configured && mlab_smtp_forces_sender() ? $configured : $email;
}

/**
 * Sender name.
 *
 * @param string $name Name set by WordPress or another plugin.
 */
function mlab_smtp_filter_from_name( $name ) {
	$configured = (string) mlab_smtp_get( 'from_name' );
	return '' !== $configured && mlab_smtp_forces_sender() ? $configured : $name;
}

/**
 * Hands the finished message to the selected API provider.
 *
 * @param string $mime Complete MIME message.
 * @param string $from Sender address of the message.
 * @return true|WP_Error
 */
function mlab_smtp_dispatch( string $mime, string $from ) {
	if ( 'm365' === mlab_smtp_get( 'provider' ) ) {
		return mlab_smtp_send_m365( $mime, $from );
	}
	return mlab_smtp_send_google( $mime );
}
