<?php
// Hilfen für die Oberflächentests: Ausgangslage herstellen und Ansichten als HTML erfassen.
require_once __DIR__ . '/store-helpers.php';

function ui_admin(): void {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	wp_set_current_user( $admins[0]->ID );
}

/** Eingerichtete Website: Hauptweg Resend, Ersatzweg SMTP, Staging-Schutz aus, leere Tabellen. */
function ui_reset( array $extra = array() ): void {
	mlab_t_reset_store();
	delete_option( MLAB_SMTP_OPTION );
	delete_option( MLAB_SMTP_READINESS_OPTION );
	delete_option( MLAB_SMTP_STATE_OPTION );
	delete_option( 'mlab_smtp_engine_last_run' );
	mlab_smtp_store( array_merge( array(
		'provider'         => 'resend',
		'setup_done'       => 1,
		'from_email'       => 'info@example.ch',
		'resend_api_key'   => 're_main_key_1234567890',
		'fallback_enabled' => 0,
		'fb_provider'      => 'smtp',
		'fb_smtp_host'     => 'backup.example.ch',
		'fb_smtp_port'     => 587,
		'fb_smtp_secure'   => 'tls',
		'fb_smtp_user'     => 'backup@example.ch',
		'fb_smtp_pass'     => 'pw-one',
	), $extra ) );
	mlab_smtp_staging_put( array( 'mode' => 'off' ) );
	ui_admin();
}

/** Alles in Ordnung: beide Wege getestet, Ersatzweg aktiv, letzter Versand erfolgreich. */
function ui_all_ok(): void {
	ui_reset( array( 'fallback_enabled' => 1 ) );
	mlab_smtp_readiness_record( 'primary', 'check', true );
	mlab_smtp_readiness_record( 'primary', 'test', true, 'a@example.ch' );
	mlab_smtp_readiness_record( 'fallback', 'check', true );
	mlab_smtp_readiness_record( 'fallback', 'test', true, 'a@example.ch' );
	mlab_smtp_state_set( array( 'last_success' => time() ) );
}

/** Ruft eine Render-Funktion mit gesetzten GET-Parametern auf und liefert das HTML. */
function ui_render( string $function, array $get = array() ): string {
	$_GET = $get;
	ob_start();
	$function();
	return (string) ob_get_clean();
}

/** Ganze Seite wie im Admin. */
function ui_page( array $get = array() ): string {
	return ui_render( 'mlab_smtp_render_page', array_merge( array( 'page' => 'mlab-smtp' ), $get ) );
}

function ui_text( string $html ): string {
	return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
}
