<?php
/**
 * Supportbericht: Klartext auf Deutsch, bei Bedarf erzeugt, als Download ausgeliefert, nie gespeichert.
 *
 * Die Funktion mlab_smtp_support_report() liefert den Text. Erweiterung über den Filter mlab_smtp_support_report_sections
 * (Array Titel => Zeilen). Admin-Aktion: admin-post.php?action=mlab_smtp_support_report (Nonce-Aktion gleich).
 * Enthält keine Secrets, keine Betreffe und nur maskierte E-Mail-Adressen; der fertige Text läuft zuletzt
 * durch mlab_smtp_redact( $text, true ).
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_mlab_smtp_support_report', 'mlab_smtp_support_report_download' );

/**
 * The report text.
 */
function mlab_smtp_support_report(): string {
	$sections = array(
		__( 'Umgebung', 'mlab-smtp' )                    => mlab_smtp_support_environment(),
		__( 'Versandwege', 'mlab-smtp' )                 => mlab_smtp_support_routes(),
		__( 'Einstellungen (ja/nein)', 'mlab-smtp' )     => mlab_smtp_support_settings(),
		__( 'Gesundheitsprüfung', 'mlab-smtp' )          => mlab_smtp_support_health(),
		__( 'Nachrichten pro Status', 'mlab-smtp' )      => mlab_smtp_support_counts(),
		__( 'Datenbank und Migration', 'mlab-smtp' )     => mlab_smtp_support_database(),
		__( 'Cron', 'mlab-smtp' )                        => mlab_smtp_support_cron(),
		__( 'Gesetzte Konstanten (nur Namen)', 'mlab-smtp' ) => mlab_smtp_support_constants(),
		__( 'Letzte 20 Protokolleinträge', 'mlab-smtp' ) => mlab_smtp_support_log(),
	);
	/**
	 * Adds sections to the support report.
	 *
	 * @param array<string, string[]> $sections Title => lines. Lines must not contain secrets; the text is redacted again at the end.
	 */
	$sections = (array) apply_filters( 'mlab_smtp_support_report_sections', $sections );
	$text     = '=== mlab-smtp Supportbericht ===' . "\n" . 'Erstellt: ' . gmdate( 'Y-m-d H:i' ) . " UTC\n";
	foreach ( $sections as $title => $lines ) {
		$text .= "\n== " . $title . " ==\n" . implode( "\n", array_map( 'strval', (array) $lines ) ) . "\n";
	}
	return mlab_smtp_redact( $text, true );
}

/**
 * Yes/no in German.
 *
 * @param mixed $value Value.
 */
function mlab_smtp_support_yn( $value ): string {
	return $value ? 'ja' : 'nein';
}

/**
 * Versions and environment.
 *
 * @return string[]
 */
function mlab_smtp_support_environment(): array {
	global $wp_version;
	return array(
		'Plugin: ' . MLAB_SMTP_VERSION,
		'WordPress: ' . $wp_version,
		'PHP: ' . PHP_VERSION,
		'Umgebungstyp: ' . wp_get_environment_type(),
		'Multisite: ' . mlab_smtp_support_yn( is_multisite() ),
		'Sprache: ' . get_locale(),
	);
}

/**
 * Active route and fallback (provider names only) with their state.
 *
 * @return string[]
 */
function mlab_smtp_support_routes(): array {
	$lines = array();
	foreach ( array(
		'primary'  => 'Hauptweg',
		'fallback' => 'Ersatzweg',
	) as $route => $label ) {
		$state   = mlab_smtp_route_state( $route );
		$enabled = 'fallback' !== $route || mlab_smtp_get( 'fallback_enabled' );
		$lines[] = $label . ': ' . ( $enabled ? ( '' !== $state['provider'] ? $state['provider'] : '-' ) : 'aus' )
			. ' | eingetragen: ' . mlab_smtp_support_yn( $state['eingetragen'] )
			. ' | geprüft: ' . mlab_smtp_support_yn( $state['geprueft'] )
			. ' | getestet: ' . mlab_smtp_support_yn( $state['getestet'] )
			. ' | veraltet: ' . mlab_smtp_support_yn( $state['veraltet'] )
			. ' | Stufe: ' . $state['level'] . ' | ' . $state['text'];
	}
	return $lines;
}

/**
 * Delivery settings as yes/no.
 *
 * @return string[]
 */
function mlab_smtp_support_settings(): array {
	$staging = function_exists( 'mlab_smtp_staging_status' ) ? mlab_smtp_staging_status() : array(
		'mode'      => '-',
		'effective' => '-',
	);
	$alerts  = function_exists( 'mlab_smtp_alerts_get' ) ? mlab_smtp_alerts_get() : array( 'url' => '' );
	return array(
		'Versand über Warteschlange: ' . mlab_smtp_support_yn( 'queue' === mlab_smtp_get( 'delivery_mode' ) ),
		'Ersatzweg aktiv: ' . mlab_smtp_support_yn( mlab_smtp_get( 'fallback_enabled' ) ),
		'Protokoll: ' . mlab_smtp_support_yn( mlab_smtp_get( 'log_enabled' ) ),
		'Benachrichtigung: ' . mlab_smtp_support_yn( mlab_smtp_get( 'notify_enabled' ) ),
		'Absender erzwingen: ' . mlab_smtp_support_yn( mlab_smtp_get( 'force_from' ) ),
		'Alarm-Webhook eingetragen: ' . mlab_smtp_support_yn( '' !== $alerts['url'] ),
		'Staging-Schutz: Modus ' . $staging['mode'] . ', wirksam ' . $staging['effective'],
	);
}

/**
 * Health rows.
 *
 * @return string[]
 */
function mlab_smtp_support_health(): array {
	$lines = array();
	foreach ( mlab_smtp_health_checks() as $row ) {
		$lines[] = '[' . $row['level'] . '] ' . $row['title'] . ': ' . mlab_smtp_redact( (string) $row['text'], true );
	}
	return $lines ? $lines : array( '(keine Prüfungen)' );
}

/**
 * Counts per status.
 *
 * @return string[]
 */
function mlab_smtp_support_counts(): array {
	$lines = array();
	foreach ( mlab_smtp_store_counts() as $status => $count ) {
		$lines[] = $status . ': ' . $count;
	}
	return $lines;
}

/**
 * Schema version and migration state.
 *
 * @return string[]
 */
function mlab_smtp_support_database(): array {
	$migration = function_exists( 'mlab_smtp_migration_state' ) ? mlab_smtp_migration_state() : array();
	$lines     = array(
		'Schema-Version: ' . (string) get_option( 'mlab_smtp_db_version', '0' ) . ' (erwartet ' . MLAB_SMTP_SCHEMA_VERSION . ')',
		'Tabellen bereit: ' . mlab_smtp_support_yn( function_exists( 'mlab_smtp_db_ready' ) ? mlab_smtp_db_ready() : false ),
	);
	foreach ( $migration as $key => $value ) {
		if ( is_scalar( $value ) ) {
			$lines[] = 'Migration ' . $key . ': ' . $value;
		}
	}
	return $lines;
}

/**
 * Cron status.
 *
 * @return string[]
 */
function mlab_smtp_support_cron(): array {
	$lines = array( 'DISABLE_WP_CRON: ' . mlab_smtp_support_yn( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) );
	foreach ( (array) apply_filters( 'mlab_smtp_support_cron_hooks', array( MLAB_SMTP_CRON_HOOK, 'mlab_smtp_worker', 'mlab_smtp_sweep' ) ) as $hook ) {
		$next    = wp_next_scheduled( (string) $hook );
		$lines[] = $hook . ': ' . ( $next ? gmdate( 'Y-m-d H:i', $next ) . ' UTC' : 'nicht geplant' );
	}
	return $lines;
}

/**
 * Configuration constants that are set: names only, never values.
 *
 * @return string[]
 */
function mlab_smtp_support_constants(): array {
	$internal = array( 'MLAB_SMTP_VERSION', 'MLAB_SMTP_FILE', 'MLAB_SMTP_DIR', 'MLAB_SMTP_URL', 'MLAB_SMTP_TIMEOUT', 'MLAB_SMTP_SCREEN' );
	$names    = array( 'WP_ENVIRONMENT_TYPE', 'DISABLE_WP_CRON', 'WP_DEBUG', 'WP_CONTENT_DIR' );
	$user     = (array) ( get_defined_constants( true )['user'] ?? array() );
	foreach ( array_keys( $user ) as $name ) {
		if ( 0 === strpos( (string) $name, 'MLAB_SMTP_' ) && ! in_array( $name, $internal, true ) && ! preg_match( '/_(OPTION|HOOK|STATE|VERSION)$/', (string) $name ) ) {
			$names[] = (string) $name;
		}
	}
	$set = array_filter( array_unique( $names ), 'defined' );
	return $set ? array_values( $set ) : array( '(keine)' );
}

/**
 * Last 20 log entries: masked address, no subject (length only), redacted error.
 *
 * @return string[]
 */
function mlab_smtp_support_log(): array {
	$lines = array();
	foreach ( mlab_smtp_log_get( array( 'limit' => 20 ) ) as $entry ) {
		$to      = array_map( 'mlab_smtp_mask_email', array_filter( array_map( 'trim', explode( ',', (string) $entry['to'] ) ) ) );
		$lines[] = gmdate( 'Y-m-d H:i', (int) $entry['time'] ) . ' | ' . $entry['status'] . ' | an: ' . implode( ', ', $to )
			. ' | Betreff: ' . mb_strlen( (string) $entry['subject'] ) . ' Zeichen | via: ' . $entry['via']
			. ' | Versuche: ' . $entry['attempts']
			. ( '' !== $entry['error'] ? ' | Fehler: ' . mlab_smtp_redact( (string) $entry['error'], true ) : '' );
	}
	return $lines ? $lines : array( '(keine Einträge)' );
}

/**
 * Admin-post handler: sends the report as a download.
 */
function mlab_smtp_support_report_download(): void {
	if ( function_exists( 'mlab_smtp_guard' ) ) {
		mlab_smtp_guard( 'mlab_smtp_support_report' );
	} else {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'mlab-smtp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mlab_smtp_support_report' );
	}
	$text = mlab_smtp_support_report();
	nocache_headers();
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="mlab-smtp-supportbericht-' . gmdate( 'Ymd-His' ) . '.txt"' );
	echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text download, redacted.
	exit;
}
