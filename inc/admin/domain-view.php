<?php
/**
 * Separate sender and advisory DNS controls for each route.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_mlab_smtp_domain_recheck', 'mlab_smtp_handle_domain_recheck' );

/**
 * Editable backup sender fields. Empty values retain backwards-compatible inheritance.
 */
function mlab_smtp_render_fallback_sender_fields(): void {
	echo '<div class="mlab-fields is-open"><h3>' . esc_html__( 'Absender des Ersatzwegs', 'mlab-smtp' ) . '</h3><p>' . esc_html__( 'Leer = Hauptversand übernehmen. Für den Webhoster zum Beispiel website@send.momentumlab.ch verwenden. Diese Adresse muss beim Hoster erlaubt sein; der SMTP-Servername ist davon unabhängig.', 'mlab-smtp' ) . '</p>';
	mlab_smtp_ui_field(
		'fb_from_email',
		__( 'Ersatzweg: Absender-Adresse', 'mlab-smtp' ),
		array(
			'type'        => 'email',
			'placeholder' => (string) mlab_smtp_get( 'from_email' ),
		)
	);
	mlab_smtp_ui_field( 'fb_from_name', __( 'Ersatzweg: Absender-Name', 'mlab-smtp' ), array( 'placeholder' => (string) mlab_smtp_get( 'from_name' ) ) );
	mlab_smtp_ui_field(
		'fb_reply_to',
		__( 'Ersatzweg: Antwortadresse', 'mlab-smtp' ),
		array(
			'type' => 'email',
			'help' => __( 'Optional: Antworten an Ihr M365-Postfach leiten. Ein eigener Wert ersetzt auch die Antwortadresse eines Kontaktformulars. Leer lässt dessen Antwortadresse unverändert.', 'mlab-smtp' ),
		)
	);
	mlab_smtp_ui_field( 'fb_dkim_selector', __( 'DKIM-Selektor (optional)', 'mlab-smtp' ), array( 'help' => __( 'Den Selektor erhalten Sie vom Mailanbieter. Ohne Selektor ist DKIM nicht beurteilbar. Ein gefundener Schlüssel beweist noch keine Signatur einer versendeten Mail.', 'mlab-smtp' ) ) );
	$sender = mlab_smtp_sender_for_route( 'fallback' );
	echo '<p><strong>' . esc_html__( 'Aktuell wirksamer Absender', 'mlab-smtp' ) . ':</strong> ' . esc_html( $sender['email'] ) . '</p></div>';
}

/**
 * Advisory report and an independent POST recheck form.
 *
 * @param string $route primary or fallback.
 * @param bool   $form Include the recheck form.
 */
function mlab_smtp_render_domain_check( string $route, bool $form = true ): void {
	$report = mlab_smtp_domain_report( $route );
	$title  = 'fallback' === $route ? __( 'Ersatzversand: DNS-Prüfung', 'mlab-smtp' ) : __( 'Hauptversand: DNS-Prüfung', 'mlab-smtp' );
	echo '<section class="mlab-card"><h2>' . esc_html( $title ) . '</h2><p><strong>' . esc_html( $report['domain'] ) . '</strong></p><p>' . esc_html__( 'Nur Hinweise: Diese Prüfung hält den Abschluss nicht auf. Ein Mailanbieter kann unbestätigte Absender trotzdem ablehnen. Nach DNS-Änderungen nochmals prüfen und eine Testmail senden.', 'mlab-smtp' ) . '</p>';
	$labels = array(
		'found'             => __( 'Eintrag gefunden', 'mlab-smtp' ),
		'provider_verified' => __( 'Vom Anbieter bestätigt', 'mlab-smtp' ),
		'unknown'           => __( 'Nicht beurteilbar', 'mlab-smtp' ),
		'missing'           => __( 'Eintrag fehlt', 'mlab-smtp' ),
		'warning'           => __( 'Bitte prüfen', 'mlab-smtp' ),
	);
	foreach ( array( 'SPF', 'DKIM', 'DMARC' ) as $type ) {
		mlab_smtp_ui_hint( in_array( $report[ $type ], array( 'found', 'provider_verified' ), true ) ? 'ok' : 'warn', $type, $labels[ $report[ $type ] ] );
	}
	echo '<details><summary>' . esc_html__( 'Was muss ich beim DNS-Anbieter eintragen?', 'mlab-smtp' ) . '</summary><p>' . esc_html__( 'SPF erlaubt dem Mailanbieter den Versand. Pro Domain darf es nur einen SPF-Eintrag geben; vorhandene Angaben zusammenführen. Ein gefundener Eintrag bestätigt noch nicht die Berechtigung Ihres konkreten Servers.', 'mlab-smtp' ) . '</p><p>' . esc_html__( 'DKIM: TXT- oder CNAME-Eintrag mit dem Selektor und Wert Ihres Hosters übernehmen. DMARC: Richtlinie unter _dmarc veröffentlichen; bei Subdomains kann die Richtlinie der Organisationsdomain gelten. p=none überwacht nur. MX für das M365-Postfach nicht ändern.', 'mlab-smtp' ) . '</p><p>' . esc_html__( 'Bei Resend gelten die im Resend-Backend angezeigten DNS-Namen und Werte. Ein Sending-only-Key kann den Domain-Status nicht lesen; das ist kein Nachweis für fehlende DNS-Einträge.', 'mlab-smtp' ) . '</p></details>';
	if ( mlab_smtp_has_pro() && '' !== $report['domain'] ) {
		echo '<details><summary>' . esc_html__( 'Pro: Technische DNS-Einträge', 'mlab-smtp' ) . '</summary><p>' . esc_html( $report['dmarc_domain'] ) . '</p><pre>';
		$records = array(
			'SPF / TXT' => mlab_smtp_domain_txt( mlab_smtp_domain_lookup( $report['domain'], DNS_TXT, false ) ),
			'DMARC'     => mlab_smtp_domain_txt( mlab_smtp_domain_lookup( '_dmarc.' . $report['dmarc_domain'], DNS_TXT, false ) ),
		);
		echo esc_html( (string) wp_json_encode( $records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		echo '</pre></details>';
	}
	if ( $form ) {
		mlab_smtp_ui_form_open( 'mlab_smtp_domain_recheck' );
		echo '<input type="hidden" name="route" value="' . esc_attr( $route ) . '"><p><button type="submit" class="button">' . esc_html__( 'Nochmals prüfen', 'mlab-smtp' ) . '</button></p>';
		mlab_smtp_ui_form_close();
	}
	echo '</section>';
}

/**
 * A recheck reads DNS without sending a mail or changing activation readiness.
 */
function mlab_smtp_handle_domain_recheck(): void {
	mlab_smtp_guard( 'mlab_smtp_domain_recheck' );
	$input = mlab_smtp_post_input();
	$route = 'fallback' === ( $input['route'] ?? '' ) ? 'fallback' : 'primary';
	mlab_smtp_domain_report( $route, true );
	mlab_smtp_flash( 'success', __( 'DNS-Prüfung aktualisiert. DNS-Änderungen können verzögert sichtbar werden.', 'mlab-smtp' ) );
	mlab_smtp_back( array( 'view' => 'settings' ) );
}
