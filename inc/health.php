<?php
/**
 * Gesundheitsprüfungen für die Übersicht.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds one check row.
 *
 * @param string $level  ok, warn or bad.
 * @param string $title  Short title.
 * @param string $text   Explanation, says what to do.
 * @param string $label  Label of the action link, empty for none.
 * @param string $url    Target of the action link.
 * @return array{level:string, title:string, text:string, action_label:string, action_url:string}
 */
function mlab_smtp_health_row( string $level, string $title, string $text, string $label = '', string $url = '' ): array {
	return array(
		'level'        => $level,
		'title'        => $title,
		'text'         => $text,
		'action_label' => $label,
		'action_url'   => $url,
	);
}

/**
 * All health checks of the current configuration. Empty while the plugin is not set up.
 *
 * @return array<int, array{level:string, title:string, text:string, action_label:string, action_url:string}>
 */
function mlab_smtp_health_checks(): array {
	if ( ! mlab_smtp_is_configured() ) {
		return array();
	}
	$checks = array(
		mlab_smtp_health_connection(),
		mlab_smtp_health_credentials(),
		mlab_smtp_health_secret_expiry(),
		mlab_smtp_health_google(),
		mlab_smtp_health_spf(),
		mlab_smtp_health_dmarc(),
		mlab_smtp_health_notify(),
		mlab_smtp_health_fallback(),
	);
	return array_values( array_filter( $checks ) );
}

/**
 * URL of the settings tab.
 */
function mlab_smtp_health_settings_url(): string {
	return mlab_smtp_page_url( array( 'view' => 'settings' ) );
}

/**
 * Check: last known state of the connection.
 *
 * @return array
 */
function mlab_smtp_health_connection(): array {
	$status = mlab_smtp_status();
	$levels = array(
		'ok'   => 'ok',
		'warn' => 'warn',
		'bad'  => 'bad',
	);
	$text   = '' !== $status['text'] ? $status['text'] : __( 'Der letzte Versand war erfolgreich.', 'mlab-smtp' );
	return mlab_smtp_health_row( $levels[ $status['state'] ] ?? 'warn', __( 'Verbindung', 'mlab-smtp' ), $text, 'ok' === $status['state'] ? '' : __( 'Verbindung prüfen', 'mlab-smtp' ), 'ok' === $status['state'] ? '' : mlab_smtp_health_settings_url() );
}

/**
 * Check: stored secrets that can no longer be decrypted (salts changed) or required fields that are missing.
 *
 * @return array|null Row, null when all secrets are readable.
 */
function mlab_smtp_health_credentials(): ?array {
	foreach ( mlab_smtp_secret_keys() as $key ) {
		if ( ! mlab_smtp_is_locked( $key ) && mlab_smtp_secret_unreadable( $key ) ) {
			return mlab_smtp_health_row( 'bad', __( 'Zugangsdaten nicht lesbar', 'mlab-smtp' ), __( 'Gespeicherte Passwörter oder Schlüssel können nicht mehr entschlüsselt werden, zum Beispiel nach einem Wechsel der WordPress-Schlüssel. Bitte geben Sie sie in den Einstellungen neu ein.', 'mlab-smtp' ), __( 'Einstellungen öffnen', 'mlab-smtp' ), mlab_smtp_health_settings_url() );
		}
	}
	if ( ! mlab_smtp_has_credentials() ) {
		return mlab_smtp_health_row( 'bad', __( 'Zugangsdaten fehlen', 'mlab-smtp' ), mlab_smtp_credentials_error(), __( 'Assistent öffnen', 'mlab-smtp' ), mlab_smtp_page_url( array( 'view' => 'wizard' ) ) );
	}
	return null;
}

/**
 * Check: expiry of the Microsoft 365 secret.
 *
 * @return array|null Row, null for other providers.
 */
function mlab_smtp_health_secret_expiry(): ?array {
	if ( 'm365' !== mlab_smtp_get( 'provider' ) ) {
		return null;
	}
	$title = __( 'Ablauf des Client-Secrets', 'mlab-smtp' );
	$label = __( 'Datum eintragen', 'mlab-smtp' );
	$days  = mlab_smtp_secret_days_left();
	if ( null === $days ) {
		return mlab_smtp_health_row( 'warn', $title, __( 'Das Ablaufdatum ist nicht erfasst. Tragen Sie es ein, damit Sie 30 Tage vorher erinnert werden. Sie finden es in Microsoft Entra unter «Zertifikate & Geheimnisse».', 'mlab-smtp' ), $label, mlab_smtp_health_settings_url() );
	}
	if ( $days < 0 ) {
		return mlab_smtp_health_row( 'bad', $title, __( 'Das Secret ist abgelaufen. Erstellen Sie in Microsoft Entra ein neues und tragen Sie es ein.', 'mlab-smtp' ), __( 'Secret erneuern', 'mlab-smtp' ), mlab_smtp_health_settings_url() );
	}
	if ( $days <= 30 ) {
		/* translators: %d: number of days */
		return mlab_smtp_health_row( 'warn', $title, sprintf( _n( 'Das Secret läuft in %d Tag ab. Erstellen Sie rechtzeitig ein neues.', 'Das Secret läuft in %d Tagen ab. Erstellen Sie rechtzeitig ein neues.', $days, 'mlab-smtp' ), $days ), __( 'Secret erneuern', 'mlab-smtp' ), mlab_smtp_health_settings_url() );
	}
	/* translators: %d: number of days */
	return mlab_smtp_health_row( 'ok', $title, sprintf( __( 'Das Secret ist noch %d Tage gültig.', 'mlab-smtp' ), $days ) );
}

/**
 * Check: Google app in test mode (refresh token expires after 7 days).
 *
 * @return array|null Row, null for other providers.
 */
function mlab_smtp_health_google(): ?array {
	if ( 'google' !== mlab_smtp_get( 'provider' ) ) {
		return null;
	}
	$title = __( 'Google-Zugriff', 'mlab-smtp' );
	if ( false !== stripos( (string) mlab_smtp_state_get()['last_error'], 'invalid_grant' ) ) {
		return mlab_smtp_health_row(
			'bad',
			$title,
			mlab_smtp_explain_error( 'invalid_grant' ),
			__( 'Neu verbinden', 'mlab-smtp' ),
			mlab_smtp_page_url(
				array(
					'view'     => 'wizard',
					'step'     => 2,
					'provider' => 'google',
				)
			)
		);
	}
	return mlab_smtp_health_row( 'ok', $title, __( 'Verbunden. Die Google-App muss in der Google Cloud Console auf «In Produktion» stehen, sonst läuft der Zugriff nach 7 Tagen ab.', 'mlab-smtp' ) );
}

/**
 * Domain whose DNS records are checked: the sender domain, otherwise the site's admin e-mail domain.
 */
function mlab_smtp_health_domain(): string {
	$domain = mlab_smtp_domain_of( (string) mlab_smtp_get( 'from_email' ) );
	return '' !== $domain ? $domain : mlab_smtp_domain_of( (string) get_option( 'admin_email' ) );
}

/**
 * TXT records of a host as plain strings.
 *
 * @param string $host Host name.
 * @return string[]|null Strings, null when the lookup is not possible.
 */
function mlab_smtp_health_txt( string $host ): ?array {
	$records = mlab_smtp_dns_lookup( $host, DNS_TXT );
	if ( null === $records ) {
		return null;
	}
	return array_map(
		static function ( array $record ): string {
			return (string) ( $record['txt'] ?? '' );
		},
		$records
	);
}

/**
 * Check: SPF record of the sender domain.
 *
 * @return array
 */
function mlab_smtp_health_spf(): array {
	$title  = __( 'SPF-Eintrag', 'mlab-smtp' );
	$domain = mlab_smtp_health_domain();
	$txt    = mlab_smtp_health_txt( $domain );
	if ( null === $txt ) {
		return mlab_smtp_health_row( 'warn', $title, __( 'Der SPF-Eintrag konnte nicht geprüft werden, weil die DNS-Abfrage auf diesem Server nicht möglich ist. Prüfen Sie ihn bei Ihrem Domain-Anbieter.', 'mlab-smtp' ) );
	}
	$spf = array_values( array_filter( $txt, static fn( string $t ): bool => 0 === stripos( $t, 'v=spf1' ) ) );
	if ( ! $spf ) {
		/* translators: %s: domain */
		return mlab_smtp_health_row( 'bad', $title, sprintf( __( 'Für %s gibt es keinen SPF-Eintrag. Ohne ihn landen Mails eher im Spam. Ergänzen Sie ihn bei Ihrem Domain-Anbieter als TXT-Eintrag.', 'mlab-smtp' ), $domain ) );
	}
	$needle = array(
		'm365'   => 'include:spf.protection.outlook.com',
		'google' => 'include:_spf.google.com',
	)[ (string) mlab_smtp_get( 'provider' ) ] ?? '';
	if ( '' !== $needle && false === stripos( $spf[0], $needle ) ) {
		/* translators: %s: SPF include to add */
		return mlab_smtp_health_row( 'warn', $title, sprintf( __( 'Der SPF-Eintrag erlaubt Ihren Anbieter noch nicht. Ergänzen Sie «%s» im bestehenden Eintrag.', 'mlab-smtp' ), $needle ) );
	}
	return mlab_smtp_health_row( 'ok', $title, __( 'Der SPF-Eintrag ist vorhanden.', 'mlab-smtp' ) );
}

/**
 * Check: DMARC record of the sender domain.
 *
 * @return array
 */
function mlab_smtp_health_dmarc(): array {
	$title = __( 'DMARC-Eintrag', 'mlab-smtp' );
	$txt   = mlab_smtp_health_txt( '_dmarc.' . mlab_smtp_health_domain() );
	if ( null === $txt ) {
		return mlab_smtp_health_row( 'warn', $title, __( 'Der DMARC-Eintrag konnte nicht geprüft werden, weil die DNS-Abfrage auf diesem Server nicht möglich ist. Prüfen Sie ihn bei Ihrem Domain-Anbieter.', 'mlab-smtp' ) );
	}
	foreach ( $txt as $record ) {
		if ( 0 === stripos( $record, 'v=DMARC1' ) ) {
			return mlab_smtp_health_row( 'ok', $title, __( 'Der DMARC-Eintrag ist vorhanden.', 'mlab-smtp' ) );
		}
	}
	return mlab_smtp_health_row( 'warn', $title, __( 'Es gibt keinen DMARC-Eintrag. Er schützt Ihre Domain vor Missbrauch und verbessert die Zustellung. Ergänzen Sie bei Ihrem Domain-Anbieter einen TXT-Eintrag für _dmarc.', 'mlab-smtp' ) );
}

/**
 * Check: notifications.
 *
 * @return array
 */
function mlab_smtp_health_notify(): array {
	$title = __( 'Benachrichtigung', 'mlab-smtp' );
	if ( mlab_smtp_get( 'notify_enabled' ) ) {
		/* translators: %s: e-mail address */
		return mlab_smtp_health_row( 'ok', $title, sprintf( __( 'Bei Störungen wird %s benachrichtigt.', 'mlab-smtp' ), mlab_smtp_notify_recipient() ) );
	}
	return mlab_smtp_health_row( 'warn', $title, __( 'Die Benachrichtigung ist ausgeschaltet. Sie erfahren dann nicht, wenn Mails nicht mehr ankommen.', 'mlab-smtp' ), __( 'Einschalten', 'mlab-smtp' ), mlab_smtp_health_settings_url() );
}

/**
 * Check: fallback route.
 *
 * @return array
 */
function mlab_smtp_health_fallback(): array {
	$title = __( 'Ersatzweg', 'mlab-smtp' );
	if ( mlab_smtp_fallback_ready() ) {
		return mlab_smtp_health_row( 'ok', $title, __( 'Fällt der Hauptweg aus, stellt der Ersatzweg die Mails zu.', 'mlab-smtp' ) );
	}
	return mlab_smtp_health_row( 'warn', $title, __( 'Es ist kein Ersatzweg eingerichtet. Fällt der Hauptweg aus, gehen Mails verloren. Empfohlen: ein zweiter Mailserver.', 'mlab-smtp' ), __( 'Einrichten', 'mlab-smtp' ), mlab_smtp_health_settings_url() );
}
