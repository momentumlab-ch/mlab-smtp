<?php
/**
 * Hängt sich in wp_mail(): installiert den eigenen PHPMailer, setzt Absender und Antwortadresse.
 * Das Plugin greift nur ein, wenn es eingerichtet ist.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'pre_wp_mail', 'mlab_smtp_prepare_mailer' );
add_action( 'phpmailer_init', 'mlab_smtp_configure_mailer', 20 );
add_filter( 'wp_mail_from', 'mlab_smtp_filter_from_email', 99 );
add_filter( 'wp_mail_from_name', 'mlab_smtp_filter_from_name', 99 );

/**
 * Request-wide switches (static storage, not persisted).
 * force: intercept even though the setup is not finished (test mail in the wizard).
 * fallback_only: skip the primary route (notifications about a failing primary route).
 *
 * @param string    $name Flag name: bypass, force or fallback_only.
 * @param bool|null $set  New value, null to only read.
 * @return bool Current value.
 */
function mlab_smtp_flag( string $name, ?bool $set = null ): bool {
	static $flags = array();
	if ( null !== $set ) {
		$flags[ $name ] = $set;
	}
	return ! empty( $flags[ $name ] );
}

/**
 * Bypass flag: while set, the plugin does not touch wp_mail() at all (PHP mail() or whatever WordPress uses).
 * Used for notifications when no working route is left.
 *
 * @param bool|null $set New value, null to only read.
 * @return bool Current value.
 */
function mlab_smtp_bypass( ?bool $set = null ): bool {
	return mlab_smtp_flag( 'bypass', $set );
}

/**
 * True when the plugin takes over the current wp_mail() call.
 */
function mlab_smtp_intercepting(): bool {
	return ! mlab_smtp_bypass() && ( mlab_smtp_flag( 'force' ) || mlab_smtp_is_configured() );
}

/**
 * True when the result of the current mail belongs in the log and the state.
 * Test mails of the wizard (force flag) while the plugin is not configured, or for a draft provider that
 * is not the live one, are neither recorded nor notified; they only return their result.
 */
function mlab_smtp_tracking(): bool {
	if ( ! mlab_smtp_intercepting() || mlab_smtp_flag( 'fallback_only' ) ) {
		return false;
	}
	$draft = '' !== mlab_smtp_provider_override() && mlab_smtp_provider_override() !== (string) ( mlab_smtp_get_all()['provider'] ?? '' );
	return ! ( mlab_smtp_flag( 'force' ) && ( ! mlab_smtp_is_configured() || $draft ) );
}

/**
 * True when the fallback is switched on and its server is filled in.
 */
function mlab_smtp_fallback_ready(): bool {
	return (bool) mlab_smtp_get( 'fallback_enabled' ) && '' !== (string) mlab_smtp_get( 'fb_smtp_host' );
}

/**
 * Route of the last mail of this request.
 *
 * @param array|null $set New value (missing keys get the defaults, an empty array resets), null to only read.
 * @return array{via:string, fallback:bool, primary_error:string}
 */
function mlab_smtp_route_result( ?array $set = null ): array {
	static $result = null;
	$defaults      = array(
		'via'           => '',
		'fallback'      => false,
		'primary_error' => '',
	);
	if ( null !== $set || null === $result ) {
		$result = wp_parse_args( (array) $set, $defaults );
	}
	return $result;
}

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
 * Installs our PHPMailer into wp_mail() when the plugin is configured, and restores the WordPress default otherwise.
 *
 * @param mixed $short_circuit Value of the pre_wp_mail filter, passed through unchanged.
 * @return mixed
 */
function mlab_smtp_prepare_mailer( $short_circuit ) {
	global $phpmailer;

	mlab_smtp_route_result( array() );
	if ( mlab_smtp_intercepting() ) {
		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		require_once MLAB_SMTP_DIR . 'inc/class-mlab-smtp-mailer.php';
		if ( ! $phpmailer instanceof Mlab_Smtp_Mailer ) {
			$phpmailer = new Mlab_Smtp_Mailer( true ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- wp_mail() reuses this global.
		}
	} elseif ( class_exists( 'Mlab_Smtp_Mailer', false ) && $phpmailer instanceof Mlab_Smtp_Mailer ) {
		$phpmailer = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Let wp_mail() create its own PHPMailer again.
	}
	return $short_circuit;
}

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property names are the PHPMailer API.
/**
 * Prepares the message: SMTP settings or the API marker, and the reply-to address.
 *
 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer object of wp_mail().
 */
function mlab_smtp_configure_mailer( $phpmailer ): void {
	if ( ! $phpmailer instanceof Mlab_Smtp_Mailer ) {
		return;
	}
	if ( mlab_smtp_is_api_provider() ) {
		// Anything but "mail" keeps the Bcc header in the message, which the APIs need.
		$phpmailer->Mailer = 'sendmail';
	} else {
		mlab_smtp_apply_smtp( $phpmailer, '' );
	}
	$reply_to = (string) mlab_smtp_get( 'reply_to' );
	if ( is_email( $reply_to ) && ! $phpmailer->getReplyToAddresses() ) {
		$phpmailer->addReplyTo( $reply_to );
	}
}

/**
 * Applies SMTP settings to a PHPMailer object.
 *
 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Mailer object.
 * @param string                         $prefix Empty for the main connection, fb_ for the fallback.
 */
function mlab_smtp_apply_smtp( $mailer, string $prefix ): void {
	$secure = (string) mlab_smtp_get( $prefix . 'smtp_secure' );

	$mailer->isSMTP();
	$mailer->Host        = (string) mlab_smtp_get( $prefix . 'smtp_host' );
	$mailer->Port        = (int) mlab_smtp_get( $prefix . 'smtp_port' );
	$mailer->SMTPSecure  = 'none' === $secure ? '' : $secure;
	$mailer->SMTPAutoTLS = 'tls' === $secure;
	$mailer->Timeout     = MLAB_SMTP_TIMEOUT;
	// PHPMailer waits up to 300 seconds for each server reply by default. A silent server (wrong port,
	// wrong encryption, delayed answer after a wrong password) then blocks the page until the 504.
	$mailer->getSMTPInstance()->Timelimit = MLAB_SMTP_TIMEOUT;
	$mailer->SMTPAuth                     = (bool) mlab_smtp_get( $prefix . 'smtp_auth' );
	$mailer->Username                     = $mailer->SMTPAuth ? (string) mlab_smtp_get( $prefix . 'smtp_user' ) : '';
	$mailer->Password                     = $mailer->SMTPAuth ? (string) mlab_smtp_get( $prefix . 'smtp_pass' ) : '';
	if ( apply_filters( 'mlab_smtp_debug', false ) ) {
		$mailer->SMTPDebug   = 2;
		$mailer->Debugoutput = 'mlab_smtp_debug_output';
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
	return mlab_smtp_intercepting() && '' !== $configured && mlab_smtp_forces_sender() ? $configured : $email;
}

/**
 * Sender name. Without a configured name the site name replaces WordPress' default "WordPress".
 *
 * @param string $name Name set by WordPress or another plugin.
 */
function mlab_smtp_filter_from_name( $name ) {
	if ( ! mlab_smtp_intercepting() || ! mlab_smtp_forces_sender() ) {
		return $name;
	}
	$configured = (string) mlab_smtp_get( 'from_name' );
	if ( '' === $configured && 'WordPress' === $name ) {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}
	return '' !== $configured ? $configured : $name;
}

/**
 * Hands the finished message to the selected API provider. Fails before sending when the sender is missing,
 * because Microsoft 365 and Google only send from the connected mailbox.
 *
 * @param string $mime Complete MIME message.
 * @param string $from Sender address of the message.
 * @return true|WP_Error
 */
function mlab_smtp_dispatch( string $mime, string $from ) {
	if ( ! is_email( (string) mlab_smtp_get( 'from_email' ) ) ) {
		return new WP_Error( 'mlab_smtp_no_sender', __( 'Es ist keine Absender-Adresse hinterlegt. Bitte unter «Einstellungen › Absender» die Adresse des Postfachs eintragen, aus dem gesendet werden soll.', 'mlab-smtp' ) );
	}
	if ( 'm365' === mlab_smtp_get( 'provider' ) ) {
		return mlab_smtp_send_m365( $mime, $from );
	}
	return mlab_smtp_send_google( $mime );
}
