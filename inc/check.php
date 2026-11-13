<?php
/**
 * Verbindung prüfen ohne Versand, Testmail, Fehlererklärung.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks a route without sending a message: SMTP connects and logs in, Microsoft 365 and Google fetch a fresh token.
 *
 * @param string $route primary or fallback.
 * @return true|WP_Error
 */
function mlab_smtp_check_connection( string $route = 'primary' ) {
	$provider = 'fallback' === $route ? 'smtp' : (string) mlab_smtp_get( 'provider' );
	if ( 'fallback' === $route && '' === (string) mlab_smtp_get( 'fb_smtp_host' ) ) {
		return new WP_Error( 'mlab_smtp_check', __( 'Beim Ersatzweg ist kein Server eingetragen. Bitte Server, Port und Verschlüsselung ergänzen.', 'mlab-smtp' ) );
	}
	if ( 'primary' === $route && ! mlab_smtp_has_credentials() ) {
		return new WP_Error( 'mlab_smtp_check', __( 'Die Verbindung ist noch nicht vollständig eingetragen. Bitte alle Pflichtfelder ausfüllen und speichern.', 'mlab-smtp' ) );
	}
	if ( 'smtp' === $provider ) {
		return mlab_smtp_check_smtp( 'fallback' === $route ? 'fb_' : '' );
	}
	$token = 'm365' === $provider ? mlab_smtp_m365_token( true ) : mlab_smtp_google_token( true );
	return is_wp_error( $token ) ? $token : true;
}

/**
 * Connects to an SMTP server and logs in, then closes the connection.
 *
 * @param string $prefix Empty for the main connection, fb_ for the fallback.
 * @return true|WP_Error
 */
function mlab_smtp_check_smtp( string $prefix ) {
	require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
	require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
	$mailer = new \PHPMailer\PHPMailer\PHPMailer( true );
	mlab_smtp_apply_smtp( $mailer, $prefix );
	try {
		$connected = $mailer->smtpConnect();
		$mailer->smtpClose();
	} catch ( \PHPMailer\PHPMailer\Exception $e ) {
		return new WP_Error( 'mlab_smtp_check', $e->getMessage() );
	}
	if ( ! $connected ) {
		return new WP_Error( 'mlab_smtp_check', __( 'Die Verbindung zum Mailserver ist fehlgeschlagen.', 'mlab-smtp' ) );
	}
	return true;
}

/**
 * Sends one test mail and collects the error and the last lines of the SMTP conversation.
 * There is exactly one attempt: a failure is reported right away instead of being retried.
 * The plugin takes over even when the setup is not finished yet (wizard), but never without credentials.
 *
 * @param string $to Recipient.
 * @return array{sent:bool, error:string, hint:string, debug:string, via:string}
 */
function mlab_smtp_run_test( string $to ): array {
	if ( ! mlab_smtp_has_credentials() ) {
		return mlab_smtp_test_result( false, __( 'Das Plugin ist noch nicht eingerichtet, es wurde keine Testmail versendet. Bitte zuerst die Verbindung eintragen und speichern.', 'mlab-smtp' ), '', '' );
	}
	$errors  = array();
	$lines   = array();
	$capture = static function ( WP_Error $error ) use ( &$errors ): void {
		$errors[] = $error->get_error_message();
	};
	$collect = static function ( string $line ) use ( &$lines ): void {
		$lines[] = $line;
	};
	add_action( 'wp_mail_failed', $capture );
	add_action( 'mlab_smtp_debug_line', $collect );
	add_filter( 'mlab_smtp_debug', '__return_true' );
	mlab_smtp_flag( 'force', true );

	/* translators: %s: site name */
	$subject = sprintf( __( 'Testmail von %s', 'mlab-smtp' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	$sent    = wp_mail( $to, $subject, __( 'Diese Nachricht bestätigt, dass der E-Mail-Versand funktioniert.', 'mlab-smtp' ) );
	$route   = mlab_smtp_route_result();

	mlab_smtp_flag( 'force', false );
	remove_filter( 'mlab_smtp_debug', '__return_true' );
	remove_action( 'mlab_smtp_debug_line', $collect );
	remove_action( 'wp_mail_failed', $capture );

	$error = $sent ? $route['primary_error'] : ( $errors ? implode( ' ', $errors ) : __( 'Unbekannter Fehler.', 'mlab-smtp' ) );
	return mlab_smtp_test_result( $sent, $error, implode( "\n", array_slice( $lines, -15 ) ), $route['fallback'] ? 'fallback' : $route['via'] );
}

/**
 * Builds the result array of a test mail.
 *
 * @param bool   $sent  True when sent.
 * @param string $error Error text; with a successful send the error of the main route when the fallback delivered.
 * @param string $debug SMTP conversation.
 * @param string $via   smtp, m365, google or fallback.
 * @return array{sent:bool, error:string, hint:string, debug:string, via:string}
 */
function mlab_smtp_test_result( bool $sent, string $error, string $debug, string $via ): array {
	return array(
		'sent'  => $sent,
		'error' => $error,
		'hint'  => '' === $error ? '' : mlab_smtp_explain_error( $error ),
		'debug' => $debug,
		'via'   => $via,
	);
}

/**
 * Known error patterns with advice. Entries with a provider only match that provider.
 *
 * @return array<int, array{pattern:string, hint:string, provider?:string}>
 */
function mlab_smtp_error_hints(): array {
	return array(
		array(
			'pattern' => '/invalid_grant/i',
			'hint'    => __( 'Google hat den Zugriff widerrufen oder ist abgelaufen. Das passiert, wenn die Google-App noch im Testmodus ist (Ablauf nach 7 Tagen). Stellen Sie die App in der Google Cloud Console auf «In Produktion» und verbinden Sie Google danach neu.', 'mlab-smtp' ),
		),
		array(
			'pattern' => '/invalid_client|AADSTS7000215|AADSTS7000222/i',
			'hint'    => __( 'Das Client-Secret ist falsch oder abgelaufen. Erstellen Sie beim Anbieter ein neues Secret und tragen Sie es unter «Einstellungen» ein.', 'mlab-smtp' ),
		),
		array(
			'pattern'  => '/\b(401|403)\b/',
			'provider' => 'm365',
			'hint'     => __( 'Microsoft 365 verweigert den Zugriff. Prüfen Sie, ob die Berechtigung «Mail.Send» (Anwendungsberechtigung) hinzugefügt und die Administratorzustimmung erteilt wurde und ob die Zugriffsrichtlinie das Postfach zulässt.', 'mlab-smtp' ),
		),
		array(
			'pattern'  => '/\b(401|403)\b/',
			'provider' => 'google',
			'hint'     => __( 'Google verweigert den Zugriff. Prüfen Sie, ob die Gmail API aktiviert ist, und verbinden Sie Google bei Bedarf neu.', 'mlab-smtp' ),
		),
		array(
			'pattern'  => '/\b404\b/',
			'provider' => 'm365',
			'hint'     => __( 'Das Postfach der Absender-Adresse existiert in Microsoft 365 nicht. Tragen Sie unter «Einstellungen» die Adresse eines vorhandenen Postfachs ein.', 'mlab-smtp' ),
		),
		array(
			'pattern' => '/\b413\b|zu gross|too large|size limit|message size/i',
			'hint'    => __( 'Die Mail ist zu gross für diesen Versandweg. Verkleinern Sie die Anhänge oder aktivieren Sie den Ersatzweg über einen eigenen Mailserver.', 'mlab-smtp' ),
		),
		array(
			'pattern' => '/authenticate|anmelden|authentifiz|535|username|password/i',
			'hint'    => __( 'Benutzername oder Passwort stimmen nicht. Bitte beide neu eingeben und speichern.', 'mlab-smtp' ),
		),
		array(
			'pattern' => '/connect|timed? ?out|verbindung|zeitüberschreitung/i',
			'hint'    => __( 'Der Mailserver ist nicht erreichbar. Prüfen Sie Server, Port und Verschlüsselung (587 = STARTTLS, 465 = SSL/TLS). Manche Hostings sperren ausgehende SMTP-Verbindungen, dann hilft nur Microsoft 365 oder Google.', 'mlab-smtp' ),
		),
		array(
			'pattern' => '/sender|from address|mail from|absender/i',
			'hint'    => __( 'Der Server lehnt die Absender-Adresse ab. Verwenden Sie eine Adresse, die zum Mailkonto gehört, oder erlauben Sie dem Konto, mit dieser Adresse zu senden.', 'mlab-smtp' ),
		),
	);
}

/**
 * Plain-language advice for an error message.
 *
 * @param string $error Error message of the mailer or provider.
 * @return string Advice, empty when the error is not known.
 */
function mlab_smtp_explain_error( string $error ): string {
	$provider = (string) mlab_smtp_get( 'provider' );
	foreach ( mlab_smtp_error_hints() as $entry ) {
		if ( isset( $entry['provider'] ) && $entry['provider'] !== $provider ) {
			continue;
		}
		if ( preg_match( $entry['pattern'], $error ) ) {
			return $entry['hint'];
		}
	}
	return '';
}
