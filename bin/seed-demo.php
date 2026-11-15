<?php
/**
 * Demo-Daten für die Screenshots (nur Testumgebung tests/env, nur Beispieladressen, kein Netzwerk).
 *
 * php bin/seed-demo.php [normal|stoerung|staging|fb-leer|fb-getestet|fb-veraltet]
 *   normal   Standardzustand (Staging-Schutz aus)
 *   stoerung wie normal, plus eine seit 40 Minuten fällige, nicht abgeholte Nachricht (Stau-Hinweis)
 *   fb-…     Ersatzweg leer / getestet / nach dem Test geändert (sonst: eingetragen, nicht geprüft)
 *   staging  wie normal, aber Staging-Schutz auf «blockieren»
 * Jeder Aufruf setzt die Demo-Daten zurück (idempotent).
 */
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require_once dirname( __DIR__ ) . '/tests/bootstrap-wp.php';

$scenario = $argv[1] ?? 'normal';
$now      = time();
$min      = 60;

// 1. Zurücksetzen.
mlab_smtp_db_install();
global $wpdb;
foreach ( mlab_smtp_db_tables() as $table ) {
	$wpdb->query( "DELETE FROM `$table`" );
}
delete_option( MLAB_SMTP_OPTION );
delete_option( MLAB_SMTP_READINESS_OPTION );
delete_option( MLAB_SMTP_STAGING_OPTION );
delete_option( MLAB_SMTP_STATE_OPTION );
delete_option( 'mlab_smtp_engine_last_run' );
// Das mu-plugin tests/env/.../mlab-demo-locale.php schaltet WP-Cron ab, solange diese Datei existiert (kein Worker im Screenshot).
$nocron = dirname( __DIR__ ) . '/tests/env/.run/nocron';
if ( 'stoerung' === $scenario ) {
	@touch( $nocron ); // phpcs:ignore
} elseif ( is_file( $nocron ) ) {
	@unlink( $nocron ); // phpcs:ignore
}

// 2. Einstellungen: Resend als Hauptweg, eigener Mailserver als Ersatzweg.
mlab_smtp_store(
	array(
		'provider'            => 'resend',
		'setup_done'          => 1,
		'resend_api_key'      => 're_DemoKey0000000000000000000000000',
		'from_email'          => 'noreply@beispiel.ch',
		'from_name'           => 'Beispiel AG',
		'fallback_enabled'    => 1,
		'fb_provider'         => 'smtp',
		'fb_smtp_host'        => 'mail.beispiel.invalid',
		'fb_smtp_port'        => 587,
		'fb_smtp_secure'      => 'tls',
		'fb_smtp_user'        => 'versand@beispiel.ch',
		'fb_smtp_pass'        => 'demo-passwort',
		'notify_enabled'      => 1,
		'notify_email'        => 'user@example.test',
		'delivery_mode'       => 'queue',
		'retry_in_background' => 1,
	)
);
mlab_smtp_staging_put(
	array(
		'mode'          => 'staging' === $scenario ? 'block' : 'off',
		'test_email'    => 'user@example.test',
		'fingerprint'   => mlab_smtp_staging_fingerprint(),
		'env_hash'      => mlab_smtp_staging_env_hash(),
		'confirmed_env' => '',
	)
);
mlab_smtp_readiness_record( 'primary', 'check', true );
mlab_smtp_readiness_record( 'primary', 'test', true, 'user@example.test' );
// Ersatzweg: Standard ist «eingetragen, nicht geprüft». Varianten für die Zusammenfassung des Ersatzwegs.
if ( in_array( $scenario, array( 'fb-getestet', 'fb-veraltet' ), true ) ) {
	mlab_smtp_readiness_record( 'fallback', 'check', true );
	mlab_smtp_readiness_record( 'fallback', 'test', true, 'user@example.test' );
}
if ( 'fb-veraltet' === $scenario ) {
	mlab_smtp_store( array( 'fb_smtp_host' => 'mail2.beispiel.invalid' ) ); // Änderung nach dem Test.
}
if ( 'fb-leer' === $scenario ) {
	mlab_smtp_store( array( 'fallback_enabled' => 0, 'fb_smtp_host' => '', 'fb_smtp_user' => '', 'fb_smtp_pass' => '' ) );
}

// 3. Nachrichten.
$n = 0;
$mk = static function ( string $status, string $subject, string $to, int $age_min, array $extra = array(), array $attempts = array(), array $events = array() ) use ( $now, $min, &$n ) {
	++$n;
	$created = $now - $age_min * $min;
	$id      = mlab_smtp_store_create(
		array_merge(
			array(
				'status'     => $status,
				'route'      => 'primary',
				'provider'   => 'resend',
				'via'        => 'resend',
				'recipients' => array( $to ),
				'subject'    => $subject,
				'mode'       => 'queued',
				'created_at' => $created,
				'uuid'       => sprintf( '00000000-0000-4000-8000-%012d', $n ),
			),
			$extra
		)
	);
	foreach ( $attempts as $i => $a ) {
		$aid = mlab_smtp_store_add_attempt(
			$id,
			array(
				'route'           => $a['route'] ?? 'primary',
				'provider'        => $a['provider'] ?? 'resend',
				'started_at'      => $created + 5 + $i * 90,
				'request_sent_at' => $created + 6 + $i * 90,
				'mail_message_id' => $a['mid'] ?? '',
			)
		);
		mlab_smtp_store_finish_attempt( $aid, $a );
	}
	foreach ( $events as $j => $e ) {
		mlab_smtp_store_add_event(
			array(
				'provider'    => 'resend',
				'event_id'    => 'evt_demo_' . $n . '_' . $j,
				'type'        => $e[0],
				'occurred_at' => $created + $e[1],
				'detail'      => $e[2] ?? '',
				'message_id'  => $id,
			)
		);
	}
	return $id;
};

$mk( 'delivered', 'Ihre Bestellung 20418 ist bei uns eingegangen', 'kunde@example.test', 180, array(), array( array( 'outcome' => 'accepted', 'http_status' => 200, 'provider_msg_id' => 're_8f3a21', 'mid' => 're_8f3a21' ) ), array( array( 'email.sent', 2 ), array( 'email.delivered', 9 ) ) );
$mk( 'delivered', 'Passwort zurücksetzen', 'user@example.test', 95, array(), array( array( 'outcome' => 'accepted', 'http_status' => 200, 'provider_msg_id' => 're_77c9d0' ) ), array( array( 'email.delivered', 6 ) ) );
$mk( 'accepted', 'Rechnung RE-2026-0153', 'kunde@example.test', 42, array(), array( array( 'outcome' => 'accepted', 'http_status' => 200, 'provider_msg_id' => 're_2b10aa' ) ) );
$mk( 'accepted', 'Neue Nachricht über das Kontaktformular', 'user@example.test', 12, array(), array( array( 'outcome' => 'accepted', 'http_status' => 200, 'provider_msg_id' => 're_c41e77' ) ) );
$mk( 'queued', 'Terminbestätigung für Dienstag', 'kunde@example.test', 1, array( 'next_attempt_at' => $now + 60 * $min ) );
$mk( 'processing', 'Newsletter-Bestätigung', 'user@example.test', 2, array( 'lease' => 3600 ), array(), array() );
$mk(
	'temp_failed',
	'Lieferschein 20417',
	'kunde@example.test',
	25,
	array(
		'next_attempt_at' => $now + 45 * $min,
		'last_error'      => 'Der Anbieter ist vorübergehend überlastet (HTTP 429). Neuer Versuch folgt.',
	),
	array( array( 'outcome' => 'temporary', 'http_status' => 429, 'retry_after' => 240, 'error_code' => 'rate_limited', 'error_text' => 'Zu viele Anfragen. Bitte später erneut versuchen.' ) )
);
$mk(
	'failed',
	'Zugangsdaten für Ihr Konto',
	'user@example.test',
	300,
	array( 'last_error' => 'Die Empfängeradresse wurde vom Anbieter abgelehnt.' ),
	array(
		array( 'outcome' => 'permanent', 'http_status' => 422, 'error_code' => 'validation_error', 'error_text' => 'Die Empfängeradresse ist ungültig.' ),
		array( 'route' => 'fallback', 'provider' => 'smtp', 'outcome' => 'permanent', 'error_code' => 'smtp_550', 'error_text' => '550 Mailbox nicht verfügbar.' ),
	)
);
$mk(
	'unclear',
	'Zahlungserinnerung Mahnstufe 1',
	'kunde@example.test',
	70,
	array( 'last_error' => 'Die Verbindung brach nach dem Senden ab. Ob der Anbieter die Nachricht angenommen hat, ist unklar.' ),
	array( array( 'outcome' => 'unknown', 'error_code' => 'request_unsent_unknown', 'error_text' => 'Zeitüberschreitung nach dem Senden der Anfrage.' ) )
);
$mk(
	'partial',
	'Einladung zum Kundenanlass',
	'kunde@example.test, user@example.test',
	130,
	array( 'last_error' => 'Eine von zwei Adressen wurde abgelehnt.' ),
	array( array( 'outcome' => 'accepted', 'http_status' => 200, 'provider_msg_id' => 're_a55b90', 'error_text' => 'user@example.test wurde abgelehnt.' ) )
);
$mk( 'blocked', 'Test aus der Kopie der Website', 'kunde@example.test', 500, array( 'last_error' => 'Staging-Schutz: Versand blockiert.' ) );

// Detailansicht: Hauptweg schlägt fehl, Ersatzweg liefert, Zustellereignisse.
$mk(
	'delivered',
	'Ihre Buchung wurde bestätigt',
	'kunde@example.test',
	60,
	array( 'fallback' => 1, 'route' => 'fallback', 'provider' => 'smtp', 'via' => 'smtp' ),
	array(
		array( 'outcome' => 'temporary', 'http_status' => 503, 'error_code' => 'unavailable', 'error_text' => 'Der Dienst ist nicht erreichbar.' ),
		array( 'route' => 'fallback', 'provider' => 'smtp', 'outcome' => 'accepted', 'provider_msg_id' => '250 OK queued as 7F2C1' ),
	),
	array( array( 'email.sent', 100 ), array( 'email.delivered', 130 ) )
);

if ( 'stoerung' === $scenario ) {
	$mk( 'queued', 'Bestellbestätigung 20419', 'kunde@example.test', 40, array( 'next_attempt_at' => $now - 35 * $min ) );
}

echo "Demo-Daten gesetzt ($scenario), " . count( mlab_smtp_store_list( array( 'per_page' => 100 ) )['items'] ?? array() ) . " Nachrichten.\n";
