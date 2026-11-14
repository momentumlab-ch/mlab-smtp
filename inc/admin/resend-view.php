<?php
/**
 * Resend in the setup wizard: connect step (API key) and the domain panel of the sender step.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Step 2 for Resend: a short guide and the API key field. The key is entered here and stored encrypted;
 * nothing has to be edited in files.
 */
function mlab_smtp_render_connect_resend(): void {
	echo '<h2>' . esc_html__( 'Mit Resend verbinden', 'mlab-smtp' ) . '</h2><p class="mlab-muted">' . esc_html__( 'Resend versendet die Mails Ihrer Website über Ihre eigene, geprüfte Domain. Der Key wird nur hier eingegeben und verschlüsselt gespeichert.', 'mlab-smtp' ) . '</p>';
	mlab_smtp_render_guide(
		__( 'Anleitung bei Resend (3 Schritte)', 'mlab-smtp' ),
		static function (): void {
			mlab_smtp_render_guide_item( __( 'Domain hinzufügen', 'mlab-smtp' ), __( 'Resend › Domains › Add Domain. Empfohlen ist eine Subdomain wie updates.ihre-domain.ch: Das schützt den Ruf Ihrer Hauptdomain.', 'mlab-smtp' ) );
			mlab_smtp_render_guide_item( __( 'DNS-Einträge setzen', 'mlab-smtp' ), __( 'Resend zeigt SPF- und DKIM-Einträge. Tragen Sie diese bei Ihrem Domain-Anbieter ein. Im Schritt «Absender» zeigt Ihnen dieses Plugin, ob alles verifiziert ist.', 'mlab-smtp' ) );
			mlab_smtp_render_guide_item( __( 'API-Key erstellen', 'mlab-smtp' ), __( 'Resend › API Keys › Create API Key. Wählen Sie «Sending access» und Ihre Domain. Kopieren Sie den Key (beginnt mit re_), er wird nur einmal angezeigt.', 'mlab-smtp' ) );
		},
		'https://resend.com/domains',
		__( 'Resend öffnen', 'mlab-smtp' )
	);
	echo '<div class="mlab-fields is-open">';
	mlab_smtp_ui_field(
		'resend_api_key',
		__( 'API-Key', 'mlab-smtp' ),
		array(
			'type'        => 'password',
			'placeholder' => 're_…',
			'help'        => __( 'Beginnt mit «re_». «Sending access» genügt und ist am sichersten.', 'mlab-smtp' ),
		)
	);
	echo '</div>';
	mlab_smtp_render_check_button( __( 'Verbindung prüfen', 'mlab-smtp' ) );
}

/**
 * Status of the sender domain at Resend, with the DNS records that are still missing and the way to check again.
 * Printed in step 3 above the sender fields.
 */
function mlab_smtp_render_resend_domain_panel(): void {
	$state   = mlab_smtp_resend_domain_state( mlab_smtp_resend_sender_domain(), true );
	$text    = mlab_smtp_resend_state_text( $state );
	$message = $text['message'];
	if ( '' === $message ) {
		return;
	}
	if ( '' !== $state['error'] && 'error' === $state['state'] ) {
		$message .= ' ' . mlab_smtp_explain_error( $state['error'] );
	}
	mlab_smtp_ui_hint( $text['level'], $text['title'], trim( $message ) );
	if ( $state['records'] ) {
		mlab_smtp_render_resend_records( $state['records'] );
	}
	if ( '' !== $state['error'] ) {
		mlab_smtp_ui_technical( $state['error'] );
	}
	if ( in_array( $state['state'], array( 'missing', 'pending', 'failed', 'partial' ), true ) ) {
		echo '<div class="mlab-row"><a class="button" href="' . esc_url( mlab_smtp_resend_recheck_url() ) . '">' . esc_html__( 'Nochmals prüfen', 'mlab-smtp' ) . '</a><a class="button-link" href="https://resend.com/domains" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Domains bei Resend öffnen', 'mlab-smtp' ) . ' ↗</a></div>';
	}
}

/**
 * Table of the DNS records to set at the domain provider.
 *
 * @param array $records Records from mlab_smtp_resend_open_records().
 */
function mlab_smtp_render_resend_records( array $records ): void {
	echo '<div class="mlab-tablewrap mlab-dns"><table class="mlab-table"><caption class="screen-reader-text">' . esc_html__( 'DNS-Einträge für Resend', 'mlab-smtp' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Typ', 'mlab-smtp' ) . '</th><th scope="col">' . esc_html__( 'Name', 'mlab-smtp' ) . '</th><th scope="col">' . esc_html__( 'Wert', 'mlab-smtp' ) . '</th></tr></thead><tbody>';
	foreach ( $records as $record ) {
		$type = trim( $record['record'] . ' ' . $record['type'] );
		if ( '' !== $record['priority'] ) {
			/* translators: %s: priority of an MX record */
			$type .= ' ' . sprintf( __( '(Priorität %s)', 'mlab-smtp' ), $record['priority'] );
		}
		echo '<tr><td>' . esc_html( $type ) . '</td><td>';
		mlab_smtp_ui_copy( $record['name'] );
		echo '</td><td>';
		mlab_smtp_ui_copy( $record['value'] );
		echo '</td></tr>';
	}
	echo '</tbody></table></div>';
}
