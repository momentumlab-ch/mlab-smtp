<?php
// Bereitschaft der Versandwege: Fingerabdruck je Eingabe, Zustandsübergänge, Invalidierung (echtes WordPress, SQLite).
require dirname( __DIR__ ) . '/bootstrap-wp.php';

const RS_MAIN_KEY = 're_main_key_1234567890';
const RS_FB_KEY   = 're_fallback_key_1234567890';

function rs_reset( array $extra = array() ): void {
	delete_option( MLAB_SMTP_OPTION );
	delete_option( MLAB_SMTP_READINESS_OPTION );
	mlab_smtp_store(
		array_merge(
			array(
				'provider'          => 'smtp',
				'setup_done'        => 1,
				'from_email'        => 'info@example.ch',
				'smtp_host'         => 'mail.example.ch',
				'fallback_enabled'  => 0,
				'fb_provider'       => 'smtp',
				'fb_smtp_host'      => 'backup.example.ch',
				'fb_smtp_port'      => 587,
				'fb_smtp_secure'    => 'tls',
				'fb_smtp_user'      => 'backup@example.ch',
				'fb_smtp_pass'      => 'pw-one',
				'fb_resend_api_key' => RS_FB_KEY,
			),
			$extra
		)
	);
}

// 1. Der Fingerabdruck ändert sich mit jeder relevanten Eingabe des Ersatzwegs.
rs_reset();
$base    = mlab_smtp_route_fingerprint( 'fallback' );
$changes = array(
	'fb_smtp_host'   => 'other.example.ch',
	'fb_smtp_port'   => 465,
	'fb_smtp_secure' => 'ssl',
	'fb_smtp_user'   => 'other@example.ch',
	'fb_smtp_pass'   => 'pw-two',
	'from_email'     => 'info@other.ch',
	'fb_provider'    => 'resend',
);
foreach ( $changes as $key => $value ) {
	rs_reset();
	mlab_smtp_store( array( $key => $value ) );
	assert_true( mlab_smtp_route_fingerprint( 'fallback' ) !== $base, "fingerprint of the fallback changes with $key" );
}
rs_reset();
assert_same( $base, mlab_smtp_route_fingerprint( 'fallback' ), 'fingerprint is stable for unchanged input' );
assert_true( 64 === strlen( $base ) && ctype_xdigit( $base ), 'fingerprint is a hex hash' );

// 2. Sender domain: same domain with another local part changes the sender part, still differs.
rs_reset( array( 'fb_provider' => 'resend' ) );
$a = mlab_smtp_route_fingerprint( 'fallback' );
mlab_smtp_store( array( 'fb_resend_api_key' => 're_other_key_1234567890' ) );
assert_true( $a !== mlab_smtp_route_fingerprint( 'fallback' ), 'fingerprint of the fallback changes with the Resend key' );
assert_same( mlab_smtp_route_inputs( 'fallback' )['domain'], 'example.ch', 'inputs carry the sender domain' );

// 3. Route-Trennung: Key des Ersatzwegs ändert den Hauptweg nicht und umgekehrt.
rs_reset( array( 'provider' => 'resend', 'resend_api_key' => RS_MAIN_KEY, 'fb_provider' => 'smtp' ) );
$main = mlab_smtp_route_fingerprint( 'primary' );
$fb   = mlab_smtp_route_fingerprint( 'fallback' );
mlab_smtp_store( array( 'fb_resend_api_key' => 're_changed_key_1234567890', 'fb_smtp_host' => 'x.example.ch' ) );
assert_same( $main, mlab_smtp_route_fingerprint( 'primary' ), 'fallback settings never change the fingerprint of the main route' );
mlab_smtp_store( array( 'resend_api_key' => 're_main_changed_1234567890' ) );
assert_same( mlab_smtp_route_fingerprint( 'fallback' ), mlab_smtp_route_fingerprint( 'fallback' ), 'fingerprint is deterministic' );
assert_true( $main !== mlab_smtp_route_fingerprint( 'primary' ), 'main key changes the main fingerprint' );

// 4. Zustandsübergänge: eingetragen -> geprüft -> getestet -> veraltet.
rs_reset();
$state = mlab_smtp_route_state( 'fallback' );
assert_true( $state['eingetragen'] && ! $state['geprueft'] && ! $state['getestet'] && ! $state['veraltet'], 'filled in is not checked or tested' );
assert_same( 'warn', $state['level'], 'filled-in only is a warning, not ready' );
mlab_smtp_readiness_record( 'fallback', 'check', true );
$state = mlab_smtp_route_state( 'fallback' );
assert_true( $state['geprueft'] && ! $state['getestet'], 'state geprueft after a passed check' );
mlab_smtp_readiness_record( 'fallback', 'test', true, 'person@example.com' );
$state = mlab_smtp_route_state( 'fallback' );
assert_true( $state['getestet'] && ! $state['veraltet'], 'state getestet after a passed test mail' );
assert_same( 'ok', $state['level'], 'tested and not stale is ok' );
assert_true( ! str_contains( $state['tested_to'], 'person@' ) && '' !== $state['tested_to'], 'recipient is stored masked' );
assert_true( ! str_contains( wp_json_encode( get_option( MLAB_SMTP_READINESS_OPTION ) ), 'person@example.com' ), 'full recipient never reaches the option' );

// 5. Jede Änderung macht das Ergebnis veraltet.
foreach ( array( 'fb_smtp_host' => 'new.example.ch', 'fb_smtp_port' => 2525, 'fb_smtp_secure' => 'ssl', 'fb_smtp_user' => 'new@example.ch', 'from_email' => 'noreply@example.ch', 'fb_smtp_pass' => 'new-pass' ) as $key => $value ) {
	rs_reset();
	mlab_smtp_readiness_record( 'fallback', 'check', true );
	mlab_smtp_readiness_record( 'fallback', 'test', true, 'a@example.com' );
	mlab_smtp_store( array( $key => $value ) );
	$state = mlab_smtp_route_state( 'fallback' );
	assert_true( ! $state['getestet'] && ! $state['geprueft'] && $state['veraltet'], "changing $key invalidates check and test (veraltet)" );
	assert_same( 'warn', $state['level'], "changing $key is a warning" );
}
rs_reset();
mlab_smtp_readiness_record( 'fallback', 'test', true, 'a@example.com' );
mlab_smtp_store( array( 'from_email' => 'info@other-domain.ch' ) );
assert_true( mlab_smtp_route_state( 'fallback' )['veraltet'], 'changing the sender domain invalidates the test' );
mlab_smtp_readiness_record( 'fallback', 'check', true );
mlab_smtp_readiness_record( 'fallback', 'test', true, 'a@example.com' );
assert_true( mlab_smtp_route_state( 'fallback' )['getestet'], 'a new test after the change counts again' );

// 6. Fehlgeschlagene Prüfung und fehlgeschlagene Testmail zählen nicht.
rs_reset();
mlab_smtp_readiness_record( 'fallback', 'test', true, 'a@example.com' );
mlab_smtp_readiness_record( 'fallback', 'test', false, 'a@example.com' );
assert_true( ! mlab_smtp_route_state( 'fallback' )['getestet'], 'a failed test mail removes the tested state' );

// 7. Eingetragen heisst nicht bereit: mlab_smtp_fallback_ready() behält seine Bedeutung für den Versand.
rs_reset( array( 'fallback_enabled' => 1 ) );
assert_true( mlab_smtp_fallback_ready(), 'fallback_ready stays true for enabled and filled' );
assert_true( ! mlab_smtp_fallback_tested_answer()['tested'], 'but the fallback is not tested' );
assert_same( 'warn', mlab_smtp_health_fallback()['level'], 'health row warns while the fallback is only filled in' );
mlab_smtp_readiness_record( 'fallback', 'check', true );
mlab_smtp_readiness_record( 'fallback', 'test', true, 'a@example.com' );
assert_same( 'ok', mlab_smtp_health_fallback()['level'], 'health row is ok when tested and enabled' );
mlab_smtp_store( array( 'fb_smtp_host' => 'changed.example.ch' ) );
assert_same( 'warn', mlab_smtp_health_fallback()['level'], 'health row warns again after a change' );
rs_reset( array( 'fallback_enabled' => 1, 'fb_smtp_host' => '' ) );
assert_true( ! mlab_smtp_route_state( 'fallback' )['eingetragen'], 'an empty host is not filled in' );

// 8. Antwort für die Übersicht.
rs_reset();
mlab_smtp_readiness_record( 'fallback', 'check', true );
mlab_smtp_readiness_record( 'fallback', 'test', true, 'a@example.com' );
$answer = mlab_smtp_fallback_tested_answer();
assert_true( $answer['tested'] && ! $answer['enabled'] && 'warn' === $answer['level'], 'tested but switched off is a warning for the overview' );
assert_same( 'Ist der Ersatzweg tatsächlich getestet?', $answer['question'], 'overview question text' );

// 9. Hauptweg: eigene Zustände, getrennt vom Ersatzweg.
rs_reset();
mlab_smtp_readiness_record( 'primary', 'test', true, 'a@example.com' );
assert_true( mlab_smtp_route_state( 'primary' )['getestet'] && ! mlab_smtp_route_state( 'fallback' )['getestet'], 'results are stored per route' );

delete_option( MLAB_SMTP_OPTION );
delete_option( MLAB_SMTP_READINESS_OPTION );
t_finish();
