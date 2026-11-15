<?php
/**
 * Registry der Versandwege: die eine Stelle, die jeden Anbieter beschreibt.
 *
 * Hier stehen nur Daten und Funktionsnamen, keine übersetzbaren Texte (die Texte der Oberfläche stehen in
 * mlab_smtp_ui_providers()). Die Registry darf deshalb schon vor "init" gelesen werden. Ein neuer Anbieter
 * braucht einen Eintrag hier, einen Eintrag in mlab_smtp_ui_providers() und seine eigenen Funktionen.
 *
 * Felder je Anbieter:
 * - api:          true bei einem Versand über eine Web-API, false bei SMTP.
 * - required:     Schlüssel, die für den Versand ausgefüllt sein müssen.
 * - group:        Schlüssel, die der Verbinden-Schritt des Assistenten speichert.
 * - secrets:      Schlüssel, die verschlüsselt gespeichert werden.
 * - credentials:  Schlüssel, die die Verbindung festlegen. Ändert sich einer, sind zwischengespeicherte Tokens wertlos.
 * - token:        Funktion, die das Zugriffstoken der API liefert (bool $fresh), null ohne Token.
 * - check:        Funktion, die die Verbindung ohne Versand prüft (true|WP_Error).
 * - send:         Funktion, die die fertige Nachricht über die API sendet (PHPMailer-Objekt, true|WP_Error).
 * - spf:          Eintrag, der im SPF der Hauptdomain stehen muss, leer ohne Vorgabe, false wenn der Anbieter den
 *                 SPF-Eintrag der Hauptdomain nicht braucht.
 * - health:       Funktionen, die zusätzliche Zeilen der Gesundheitsprüfung liefern.
 * - fb_required:   Schlüssel, die der Anbieter als Ersatzweg braucht, null wenn er kein Ersatzweg sein kann.
 * - fb_check:      Funktion, die den Ersatzweg ohne Versand prüft (true|WP_Error).
 * - fb_send:       Funktion, die die Nachricht über den Ersatzweg sendet (PHPMailer-Objekt, true|WP_Error), null bei SMTP.
 * - sender_block: Funktion, die den Assistenten im Schritt «Absender» anhält (Text oder null).
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Die eingebauten Versandwege. Die Reihenfolge ist die der Anzeige.
 *
 * @return array<string, array<string, mixed>>
 */
function mlab_smtp_core_provider_defs(): array {
	return array(
		'm365'   => array(
			'api'          => true,
			'required'     => array( 'm365_tenant', 'm365_client_id', 'm365_client_secret' ),
			'group'        => array( 'm365_tenant', 'm365_client_id', 'm365_client_secret', 'm365_secret_expires', 'from_email' ),
			'secrets'      => array( 'm365_client_secret' ),
			'credentials'  => array( 'm365_tenant', 'm365_client_id', 'm365_client_secret' ),
			'token'        => 'mlab_smtp_m365_token',
			'check'        => 'mlab_smtp_check_m365',
			'send'         => 'mlab_smtp_send_m365_mail',
			'spf'          => 'include:spf.protection.outlook.com',
			'health'       => array( 'mlab_smtp_health_secret_expiry' ),
			'sender_block' => null,
			'fb_required'  => null,
			'fb_check'     => null,
			'fb_send'      => null,
		),
		'google' => array(
			'api'          => true,
			'required'     => array( 'google_client_id', 'google_client_secret', 'google_refresh_token' ),
			'group'        => array( 'google_client_id', 'google_client_secret' ),
			'secrets'      => array( 'google_client_secret', 'google_refresh_token' ),
			'credentials'  => array( 'google_client_id', 'google_client_secret', 'google_refresh_token' ),
			'token'        => 'mlab_smtp_google_token',
			'check'        => 'mlab_smtp_check_google',
			'send'         => 'mlab_smtp_send_google_mail',
			'spf'          => 'include:_spf.google.com',
			'health'       => array( 'mlab_smtp_health_google' ),
			'sender_block' => null,
			'fb_required'  => null,
			'fb_check'     => null,
			'fb_send'      => null,
		),
		'resend' => array(
			'api'          => true,
			'required'     => array( 'resend_api_key' ),
			'group'        => array( 'resend_api_key' ),
			'secrets'      => array( 'resend_api_key', 'fb_resend_api_key', 'resend_webhook_secret', 'fb_resend_webhook_secret' ),
			'credentials'  => array( 'resend_api_key', 'fb_resend_api_key' ),
			'token'        => null,
			'check'        => 'mlab_smtp_resend_check',
			'send'         => 'mlab_smtp_send_resend',
			'spf'          => false,
			'health'       => array( 'mlab_smtp_health_resend' ),
			'sender_block' => 'mlab_smtp_resend_sender_block',
			'fb_required'  => array( 'fb_resend_api_key' ),
			'fb_check'     => 'mlab_smtp_check_fb_resend',
			'fb_send'      => 'mlab_smtp_send_resend_fallback',
		),
		'smtp'   => array(
			'api'          => false,
			'required'     => array( 'smtp_host' ),
			'group'        => array( 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_auth', 'smtp_user', 'smtp_pass' ),
			'secrets'      => array( 'smtp_pass' ),
			'credentials'  => array(),
			'token'        => null,
			'check'        => 'mlab_smtp_check_smtp_main',
			'send'         => null,
			'spf'          => '',
			'health'       => array( 'mlab_smtp_health_smtp_security' ),
			'sender_block' => null,
			'fb_required'  => array( 'fb_smtp_host' ),
			'fb_check'     => 'mlab_smtp_check_fb_smtp',
			'fb_send'      => null,
		),
	);
}

/**
 * Eintrag eines Versandwegs.
 *
 * @param string $provider smtp, m365, google or resend.
 * @return array<string, mixed> Leer bei einem unbekannten Anbieter.
 */
function mlab_smtp_provider_def( string $provider ): array {
	return mlab_smtp_provider_defs()[ $provider ] ?? array();
}

/**
 * Schlüssel aller Anbieter zu einem Feld der Registry, ohne Doppelte.
 *
 * @param string $field secrets or credentials.
 * @return string[]
 */
function mlab_smtp_provider_keys( string $field ): array {
	$keys = array();
	foreach ( mlab_smtp_provider_defs() as $def ) {
		$keys = array_merge( $keys, $def[ $field ] );
	}
	return array_values( array_unique( $keys ) );
}

/**
 * Pflichtfelder eines Eintrags der Registry.
 *
 * @return string[]
 */
function mlab_smtp_provider_def_fields(): array {
	return array( 'api', 'required', 'group', 'secrets', 'credentials', 'token', 'check', 'send', 'spf', 'health', 'sender_block', 'fb_required', 'fb_check', 'fb_send' );
}

/**
 * Alle Versandwege: die eingebauten und die, die ein anderes Plugin über den Filter mlab_smtp_providers ergänzt.
 * Ein Zusatz darf keinen eingebauten Weg ersetzen, braucht alle Felder und muss seine Funktionen mitbringen
 * (sie sind aufrufbar). Unvollständige Einträge werden ignoriert, damit ein fehlerhaftes Zusatzplugin den Versand
 * nicht stört. Das Ergebnis wird für die Anfrage gemerkt.
 *
 * @param bool $reset True, um den gemerkten Stand zu verwerfen (Tests).
 * @return array<string, array<string, mixed>>
 */
function mlab_smtp_provider_defs( bool $reset = false ): array {
	static $defs = null;
	if ( $reset ) {
		$defs = null;
	}
	if ( null !== $defs ) {
		return $defs;
	}
	$defs = mlab_smtp_core_provider_defs();
	/**
	 * Adds providers. Return an array of id => definition with every field of mlab_smtp_core_provider_defs().
	 *
	 * @param array $extra Empty by default.
	 */
	foreach ( (array) apply_filters( 'mlab_smtp_providers', array() ) as $id => $def ) {
		if ( is_string( $id ) && '' !== $id && ! isset( $defs[ $id ] ) && is_array( $def ) && ! array_diff( mlab_smtp_provider_def_fields(), array_keys( $def ) ) ) {
			$defs[ $id ] = $def;
		}
	}
	return $defs;
}
