<?php
// Einstellungsseite: Aufbau, Abschnitte je Zustand, keine Secrets im HTML, Nonces, Speichern, Escaping.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/store-helpers.php';
require dirname( __DIR__ ) . '/lib/settings-helpers.php';

$secrets = array(
	'resend_api_key'        => 're_main_key_1234567890',
	'fb_resend_api_key'     => 're_fallback_key_0987654321',
	'resend_webhook_secret' => 'whsec_hauptsecret123456',
	'smtp_pass'             => 'smtp-passwort-xyz-987654',
);
delete_option( MLAB_SMTP_OPTION );
delete_option( MLAB_SMTP_STAGING_OPTION );
delete_option( MLAB_SMTP_ALERTS_OPTION );
delete_option( MLAB_SMTP_ALERTS_STATE );
mlab_t_reset_store();
mlab_smtp_store( array_merge( array( 'provider' => 'resend', 'setup_done' => 1, 'from_email' => 'info@example.ch' ), $secrets ) );
assert_same( array(), mlab_smtp_alerts_save( array( 'url' => 'https://hook.example.test/a', 'secret' => 'alert-signing-secret-777', 'events' => array( 'primary_failing' => 1, 'final_failures' => 1 ) ) ), 'alerts stored' );
add_filter( 'mlab_smtp_environment_type', static fn() => 'production' );
mlab_smtp_staging_init();
st_admin();
$html = st_render();

// Aufbau: Index, Reihenfolge, ein Speichern-Knopf.
$ids = array( 'connection', 'fallback', 'delivery', 'webhooks', 'sender', 'staging', 'alerts', 'notify', 'log', 'privacy', 'encryption' );
$pos = -1;
foreach ( $ids as $id ) {
	assert_true( str_contains( $html, 'href="#mlab-s-' . $id . '"' ), "index links $id" );
	$at = strpos( $html, 'id="mlab-s-' . $id . '"' );
	assert_true( false !== $at && $at > $pos, "section $id in order" );
	$pos = (int) $at;
}
assert_same( 1, substr_count( $html, 'class="mlab-savebar"' ), 'one save bar' );
assert_true( str_contains( $html, 'name="delivery_mode"' ) && str_contains( $html, 'name="log_retention"' ), 'existing fields kept' );

// Keine Secrets.
foreach ( array_merge( array_values( $secrets ), array( 'alert-signing-secret-777' ) ) as $secret ) {
	assert_true( ! str_contains( $html, $secret ), 'secret not in html' );
}
assert_true( str_contains( $html, 'placeholder="gespeichert"' ), 'alert secret placeholder' );

// Staging: Standard (auto, Produktion) = frei.
assert_true( str_contains( $html, 'Schutz vor ungewollten Mails (Staging)' ) && str_contains( $html, 'Versand frei' ), 'staging free state' );
assert_true( ! str_contains( $html, 'Das ist die produktive Website' ), 'no confirm button when free' );
assert_true( str_contains( $html, 'name="staging_mode"' ) && ! preg_match( '/name="staging_mode"[^>]*disabled/', $html ), 'mode editable' );

// Staging blockiert.
update_option( MLAB_SMTP_STAGING_OPTION, array_merge( mlab_smtp_staging_get(), array( 'mode' => 'block' ) ), false );
$html = st_render();
assert_true( str_contains( $html, 'Versand blockiert' ) && str_contains( $html, 'Das ist die produktive Website' ), 'blocked state with confirm button' );
update_option( MLAB_SMTP_STAGING_OPTION, array_merge( mlab_smtp_staging_get(), array( 'mode' => 'redirect', 'test_email' => 'test@example.ch' ) ), false );
$html = st_render();
assert_true( str_contains( $html, 'Mails werden umgeleitet' ) && str_contains( $html, 'value="test@example.ch"' ), 'redirect state and address' );

// Alarmierung.
assert_true( str_contains( $html, 'value="https://hook.example.test/a"' ), 'alert url shown' );
assert_true( preg_match( '/name="alert_events\[primary_failing\]"[^>]*checked/', $html ) === 1 && preg_match( '/name="alert_events\[queue_stalled\]"[^>]*checked/', $html ) !== 1, 'event toggles' );
assert_true( str_contains( $html, 'Es wurde noch kein Alarm gesendet.' ), 'no alert yet' );
update_option( MLAB_SMTP_ALERTS_STATE, array( 'last' => array( 'ok' => false, 'time' => time(), 'code' => 503, 'event' => 'test', 'message' => 'Der Empfänger hat den Alarm abgelehnt.' ) ), false );
$html = st_render();
assert_true( str_contains( $html, 'fehlgeschlagen' ) && str_contains( $html, 'HTTP 503' ), 'last alert result' );

// Nonces und Aktionsformulare ausserhalb des Hauptformulars.
foreach ( array( 'mlab_smtp_staging_confirm', 'mlab_smtp_alert_test', 'mlab_smtp_support_report', 'mlab_smtp_reencrypt' ) as $action ) {
	assert_true( preg_match( '/<form[^>]*id="mlab-act-[a-z-]+"[^>]*>\s*<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="_wpnonce" value="[0-9a-f]{10}"/', $html ) === 1, "nonce form $action" );
}
assert_true( str_contains( $html, 'Supportbericht herunterladen' ) && str_contains( $html, 'Alarm testen' ), 'buttons' );
assert_true( str_contains( $html, 'name="action" value="mlab_smtp_save_settings"' ), 'main form action' );

// Datenschutz und Verschlüsselung.
assert_true( str_contains( $html, 'export-personal-data.php' ) && str_contains( $html, 'erase-personal-data.php' ) && str_contains( $html, 'tab=policyguide' ), 'privacy links' );
assert_true( str_contains( $html, 'Nächste automatische Bereinigung' ) && ! str_contains( $html, 'liegen verschlüsselt in der Datenbank' ), 'retention line, no queue note in sync mode' );
mlab_smtp_store( array( 'delivery_mode' => 'queue' ) );
assert_true( str_contains( st_render(), 'liegen verschlüsselt in der Datenbank' ), 'queue payload note' );
assert_true( str_contains( $html, 'Verschlüsselung verfügbar: ja' ) && str_contains( $html, 'Kennung ' . mlab_smtp_key_id( mlab_smtp_key() ) ), 'encryption status and key id' );
assert_true( str_contains( $html, 'Sicherheitsschlüssel (Salts)' ) && str_contains( $html, '5 lesbar, 0 nicht lesbar' ), 'source salts and counts' );

// Speichern über den Hauptknopf: Staging und Alarm.
$post = array( 'staging_form' => '1', 'staging_mode' => 'redirect', 'staging_test_email' => 'neu@example.ch', 'alert_form' => '1', 'alert_url' => 'https://hook.example.test/b', 'alert_secret' => '', 'alert_events' => array( 'queue_stalled' => '1' ) );
assert_true( str_starts_with( st_call( 'mlab_smtp_handle_save_settings', 'mlab_smtp_save_settings', $post ), 'redirect:' ), 'save redirects' );
assert_same( 'neu@example.ch', mlab_smtp_staging_get()['test_email'], 'staging saved' );
assert_same( 'https://hook.example.test/b', mlab_smtp_alerts_get()['url'], 'alert url saved' );
assert_true( mlab_smtp_alerts_get()['has_secret'], 'empty secret keeps stored one' );
assert_true( ! mlab_smtp_alerts_get()['events']['primary_failing'] && mlab_smtp_alerts_get()['events']['queue_stalled'], 'events saved' );
$errors = mlab_smtp_save_security_forms( array( 'alert_form' => '1', 'alert_url' => 'http://hook.example.test/x' ) );
assert_true( (bool) $errors, 'http url rejected with message' );
assert_same( 'https://hook.example.test/b', mlab_smtp_alerts_get()['url'], 'rejected url not stored' );
$errors = mlab_smtp_save_security_forms( array( 'staging_form' => '1', 'staging_mode' => 'redirect', 'staging_test_email' => 'kaputt' ) );
assert_true( (bool) $errors, 'invalid test address rejected' );

// Escaping.
update_option( MLAB_SMTP_STAGING_OPTION, array_merge( mlab_smtp_staging_get(), array( 'mode' => 'redirect', 'test_email' => 'a"><script>x@example.ch' ) ), false );
$html = st_render();
assert_true( ! str_contains( $html, '"><script>x@' ), 'test address escaped' );

// Rechte der Handler.
wp_set_current_user( 0 );
assert_same( 'die:403', st_call( 'mlab_smtp_handle_save_settings', 'mlab_smtp_save_settings', $post ), 'anonymous cannot save' );
st_admin();
assert_same( 'die:403', st_call( 'mlab_smtp_handle_save_settings', null, $post ), 'save without nonce rejected' );
assert_same( 'die:403', st_call( 'mlab_smtp_staging_handle_confirm', null ), 'confirm without nonce rejected' );
assert_same( 'die:403', st_call( 'mlab_smtp_alert_handle_test', null ), 'alert test without nonce rejected' );
$sub = wp_insert_user( array( 'user_login' => 'st_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $sub );
assert_same( 'die:403', st_call( 'mlab_smtp_staging_handle_confirm', 'mlab_smtp_staging_confirm' ), 'subscriber cannot confirm' );
assert_same( 'die:403', st_call( 'mlab_smtp_alert_handle_test', 'mlab_smtp_alert_test' ), 'subscriber cannot test alert' );

// Aufräumen: die Testdatenbank wird von anderen Tests geteilt.
delete_option( MLAB_SMTP_STAGING_OPTION );
delete_option( MLAB_SMTP_ALERTS_OPTION );
delete_option( MLAB_SMTP_ALERTS_STATE );
delete_option( MLAB_SMTP_OPTION );

t_finish();
