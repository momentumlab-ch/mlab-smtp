<?php
/**
 * Verbindung prüfen ohne Versand, Testmail, Fehlererklärung.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks a route without sending a message: SMTP connects and logs in, Microsoft 365 and Google fetch a fresh token,
 * Resend asks for the domain list.
 *
 * @param string $route primary or fallback.
 * @return true|WP_Error
 */
function mlab_smtp_check_connection( string $route = 'primary' ) {
	$provider = (string) mlab_smtp_get( 'fallback' === $route ? 'fb_provider' : 'provider' );
	if ( 'fallback' === $route && ! mlab_smtp_fallback_filled() ) {
		return new WP_Error( 'mlab_smtp_check', __( 'Der Ersatzweg ist noch nicht vollständig eingetragen. Bitte Server (Mailserver) oder API-Key (Resend) ergänzen.', 'mlab-smtp' ) );
	}
	if ( 'primary' === $route && ! mlab_smtp_has_credentials() ) {
		return new WP_Error( 'mlab_smtp_check', __( 'Die Verbindung ist noch nicht vollständig eingetragen. Bitte alle Pflichtfelder ausfüllen und speichern.', 'mlab-smtp' ) );
	}
	return call_user_func( mlab_smtp_provider_def( $provider )[ 'fallback' === $route ? 'fb_check' : 'check' ] );
}

/**
 * Registry entry «fb_check» of the own mail server: connects and logs in with the fallback data.
 *
 * @return true|WP_Error
 */
function mlab_smtp_check_fb_smtp() {
	return mlab_smtp_check_smtp( 'fb_' );
}

/**
 * Registry entry «fb_check» of Resend: asks Resend with the key of the fallback.
 *
 * @return true|WP_Error
 */
function mlab_smtp_check_fb_resend() {
	return mlab_smtp_with_resend_key( 'fb_resend_api_key', 'mlab_smtp_resend_check' );
}

/**
 * Registry entry «check» of the own mail server: connects and logs in.
 *
 * @return true|WP_Error
 */
function mlab_smtp_check_smtp_main() {
	return mlab_smtp_check_smtp( '' );
}

/**
 * Registry entry «check» of Microsoft 365: fetches a fresh token.
 *
 * @return true|WP_Error
 */
function mlab_smtp_check_m365() {
	$token = mlab_smtp_m365_token( true );
	return is_wp_error( $token ) ? $token : true;
}

/**
 * Registry entry «check» of Google: fetches a fresh token.
 *
 * @return true|WP_Error
 */
function mlab_smtp_check_google() {
	$token = mlab_smtp_google_token( true );
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
 * @param string $via   smtp, m365, google, resend or fallback.
 * @return array{sent:bool, error:string, hint:string, debug:string, via:string}
 */
function mlab_smtp_test_result( bool $sent, string $error, string $debug, string $via ): array {
	$error = mlab_smtp_redact( $error );
	return array(
		'sent'  => $sent,
		'error' => $error,
		'hint'  => '' === $error ? '' : mlab_smtp_explain_error( $error ),
		'debug' => mlab_smtp_redact( $debug ),
		'via'   => $via,
	);
}

/**
 * Known error patterns with advice. Entries with a provider only match that provider.
 *
 * @return array<int, array{pattern:string, hint:string, provider?:string}>
 */
function mlab_smtp_error_hints(): array {
	return array_merge( mlab_smtp_resend_error_hints(), mlab_smtp_common_error_hints() );
}

/**
 * Error patterns of Resend. They come first: the generic patterns would misread them (for example a timeout
 * of the API as a problem with a mail server).
 *
 * @return array<int, array{pattern:string, hint:string, provider:string}>
 */
function mlab_smtp_resend_error_hints(): array {
	return array(
		array(
			'pattern'  => '/API key is invalid|missing_api_key/i',
			'provider' => 'resend',
			'hint'     => __( 'Resend kennt den API-Key nicht. Er wurde gelöscht oder falsch kopiert. Erstellen Sie bei Resend unter «API Keys» einen neuen Key und tragen Sie ihn im Assistenten ein.', 'mlab-smtp' ),
		),
		array(
			'pattern'  => '/API key is not active|suspended_api_key|API key is suspended/i',
			'provider' => 'resend',
			'hint'     => __( 'Der API-Key ist bei Resend nicht aktiv oder gesperrt. Erstellen Sie einen neuen Key oder wenden Sie sich an den Resend-Support.', 'mlab-smtp' ),
		),
		array(
			'pattern'  => '/only send testing emails|testing emails to your own/i',
			'provider' => 'resend',
			'hint'     => __( 'Mit der Testadresse von Resend (resend.dev) ist nur der Versand an die eigene Adresse Ihres Resend-Kontos erlaubt. Verifizieren Sie eine eigene Domain bei Resend und verwenden Sie eine Absender-Adresse dieser Domain.', 'mlab-smtp' ),
		),
		array(
			'pattern'  => '/domain is not verified|is not verified/i',
			'provider' => 'resend',
			'hint'     => __( 'Die Domain der Absender-Adresse ist bei Resend nicht verifiziert. Tragen Sie die DNS-Einträge bei Ihrem Domain-Anbieter ein (Einrichtungs-Assistent, Schritt «Absender») oder verwenden Sie eine Adresse einer verifizierten Domain.', 'mlab-smtp' ),
		),
		array(
			'pattern'  => '/daily_quota_exceeded|monthly_quota_exceeded|exceeded your (daily|monthly)/i',
			'provider' => 'resend',
			'hint'     => __( 'Das Mail-Kontingent bei Resend ist aufgebraucht (Tages- oder Monatslimit des Tarifs). Mails gehen über den Ersatzweg, sofern er eingerichtet ist. Das Tageslimit gilt bis 00:00 UTC, für mehr Mails hilft ein Tarifwechsel bei Resend.', 'mlab-smtp' ),
		),
		array(
			'pattern'  => '/rate_limit_exceeded|Too many requests/i',
			'provider' => 'resend',
			'hint'     => __( 'Die Website sendet zu viele Mails auf einmal (Resend erlaubt 10 Anfragen pro Sekunde). Mails gehen über den Ersatzweg, sofern er eingerichtet ist.', 'mlab-smtp' ),
		),
		array(
			'pattern'  => '/application_error|service_unavailable|dns_lookup_failed|Fehler 5\d\d/i',
			'provider' => 'resend',
			'hint'     => __( 'Resend ist vorübergehend gestört. Prüfen Sie resend-status.com. Mails gehen über den Ersatzweg, sofern er eingerichtet ist.', 'mlab-smtp' ),
		),
		array(
			'pattern'  => '/cURL error|could not resolve|timed? ?out|zeitüberschreitung|api\.resend\.com/i',
			'provider' => 'resend',
			'hint'     => __( 'Resend ist von diesem Server aus nicht erreichbar (api.resend.com). Prüfen Sie, ob der Hoster ausgehende HTTPS-Verbindungen sperrt, und versuchen Sie es erneut.', 'mlab-smtp' ),
		),
		array(
			'pattern'  => '/invalid_attachment|missing_required|invalid_parameter|validation_error|Fehler 422/i',
			'provider' => 'resend',
			'hint'     => __( 'Resend hat die Mail abgelehnt, weil Angaben darin ungültig sind (zum Beispiel Absender, Empfänger oder Anhang). Die technische Meldung unten nennt das Feld.', 'mlab-smtp' ),
		),
	);
}

/**
 * Error patterns of the other providers.
 *
 * @return array<int, array{pattern:string, hint:string, provider?:string}>
 */
function mlab_smtp_common_error_hints(): array {
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
			'hint'     => __( 'Das Postfach der Absender-Adresse existiert in Microsoft 365 nicht. Tragen Sie unter «Einstellungen › Absender» die Adresse eines vorhandenen Postfachs ein.', 'mlab-smtp' ),
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
 * Plain-language advice for an error message. For the fallback route the advice follows the provider of the
 * fallback and names the route («Ersatzweg»), so nobody looks for the fault in the main connection.
 *
 * @param string $error Error message of the mailer or provider.
 * @param string $route primary, fallback, or empty to take it from the message («Ersatzweg: …»).
 * @return string Advice, empty when the error is not known.
 */
function mlab_smtp_explain_error( string $error, string $route = '' ): string {
	$fallback = 'fallback' === $route || ( '' === $route && preg_match( '/^Ersatzweg: /', $error ) );
	$provider = (string) mlab_smtp_get( $fallback ? 'fb_provider' : 'provider' );
	foreach ( mlab_smtp_error_hints() as $entry ) {
		if ( isset( $entry['provider'] ) && $entry['provider'] !== $provider ) {
			continue;
		}
		if ( preg_match( $entry['pattern'], $error ) ) {
			return $fallback ? __( 'Ersatzweg: ', 'mlab-smtp' ) . $entry['hint'] : $entry['hint'];
		}
	}
	return '';
}

/**
 * Message and technical detail of an error of one route.
 *
 * @param string $raw   Raw error text.
 * @param string $route primary or fallback.
 * @return array{message:string, detail:string}
 */
function mlab_smtp_route_error_texts( string $raw, string $route ): array {
	$raw  = mlab_smtp_redact( $raw );
	$hint = mlab_smtp_explain_error( $raw, mlab_smtp_route_name( $route ) );
	if ( '' === $hint && 'fallback' === $route ) {
		$hint = __( 'Ersatzweg: ', 'mlab-smtp' ) . $raw;
	}
	return array(
		'message' => '' !== $hint ? $hint : $raw,
		'detail'  => $raw,
	);
}

/**
 * Sends one test mail through the fallback only. The main route is never contacted: the fallback_only flag skips it,
 * and the fallback counts as switched on for this one mail, so it can be tested before it is activated.
 * The result is stored as the test state of the route.
 *
 * @param string $to Recipient.
 * @return array{sent:bool, error:string, hint:string, debug:string, via:string}
 */
function mlab_smtp_run_fallback_test( string $to ): array {
	if ( ! mlab_smtp_fallback_filled() ) {
		return mlab_smtp_test_result( false, __( 'Ersatzweg: Er ist noch nicht vollständig eingetragen, es wurde keine Testmail versendet.', 'mlab-smtp' ), '', '' );
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
	mlab_smtp_flag( 'fallback_test', true );
	mlab_smtp_flag( 'force', true );
	mlab_smtp_flag( 'fallback_only', true );

	/* translators: %s: site name */
	$subject = sprintf( __( 'Testmail über den Ersatzweg von %s', 'mlab-smtp' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	$sent    = wp_mail( $to, $subject, __( 'Diese Nachricht bestätigt, dass der Ersatzweg Mails zustellen kann.', 'mlab-smtp' ) );

	mlab_smtp_flag( 'fallback_only', false );
	mlab_smtp_flag( 'force', false );
	mlab_smtp_flag( 'fallback_test', false );
	remove_filter( 'mlab_smtp_debug', '__return_true' );
	remove_action( 'mlab_smtp_debug_line', $collect );
	remove_action( 'wp_mail_failed', $capture );

	$error = $sent ? '' : ( $errors ? implode( ' ', $errors ) : __( 'Ersatzweg: Unbekannter Fehler.', 'mlab-smtp' ) );
	mlab_smtp_readiness_record( 'fallback', 'test', $sent, $to );
	$result         = mlab_smtp_test_result( $sent, $error, implode( "\n", array_slice( $lines, -15 ) ), (string) mlab_smtp_get( 'fb_provider' ) );
	$result['hint'] = '' === $error ? '' : mlab_smtp_route_error_texts( $error, 'fallback' )['message'];
	return $result;
}
