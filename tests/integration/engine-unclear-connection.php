<?php
// Unklarer Versand: der Versuch ist an die Verbindung gebunden. Anderer Schlüssel/Konto oder Anbieter, oder ein Versuch ohne Fingerabdruck: keine automatische Wiederholung.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/engine-helpers.php';
global $wpdb;

function ec_due( int $id ): void {
	global $wpdb;
	$wpdb->update( mlab_smtp_table( 'messages' ), array( 'next_attempt_at' => time() - 1 ), array( 'id' => $id ) );
}
// Erste Antwort: Zeitüberschreitung (unklar), danach Erfolg.
function ec_start(): int {
	mlab_e_reset();
	$n = 0;
	mlab_e_reply( '#api\.resend\.com/emails#', static function () use ( &$n ) {
		return 1 === ++$n ? mlab_e_timeout() : array( 'code' => 200, 'body' => array( 'id' => 'em_c' ) );
	} );
	$id = mlab_e_queue_message();
	mlab_smtp_process_queue();
	return $id;
}

// 1. Gleiche Verbindung: die Wiederholung läuft weiter, mit demselben Schlüssel.
$id = ec_start();
$row = mlab_smtp_store_attempts( $id )[0];
assert_same( 'unknown', $row['outcome'], 'first attempt is unclear' );
assert_true( 64 === strlen( $row['conn_hash'] ), 'attempt carries a connection fingerprint' );
assert_true( ! str_contains( wp_json_encode( $row ), ENG_KEY ), 'no secret in the stored attempt' );
ec_due( $id );
mlab_smtp_process_queue();
assert_same( 'accepted', mlab_smtp_store_get( $id )['status'], 'same connection: retry goes out' );
assert_same( 2, count( mlab_e_resend_requests() ), 'same connection: second request sent' );
$keys = array_map( static fn( $r ) => mlab_e_header( $r, 'Idempotency-Key' ), mlab_e_resend_requests() );
assert_same( $keys[0], $keys[1], 'same connection: same idempotency key' );

// 2. Anderer API-Schlüssel (anderes Konto): keine automatische Wiederholung.
$id = ec_start();
mlab_smtp_store( array( 'resend_api_key' => 're_other_account_key_000000000000' ) );
ec_due( $id );
mlab_smtp_process_queue();
$m = mlab_smtp_store_get( $id );
assert_same( 'unclear', $m['status'], 'other key: manual check required' );
assert_same( 1, count( mlab_e_resend_requests() ), 'other key: nothing sent' );
assert_true( str_contains( (string) $m['last_error'], 'Verbindung' ), 'other key: the reason names the changed connection' );

// 3. Anderer Anbieter.
$id = ec_start();
mlab_smtp_store( array( 'provider' => 'smtp', 'smtp_host' => 'mail.example.test' ) );
ec_due( $id );
mlab_smtp_process_queue();
assert_same( 'unclear', mlab_smtp_store_get( $id )['status'], 'provider change: manual check required' );
assert_same( 1, count( mlab_e_resend_requests() ), 'provider change: nothing sent' );

// 4. Versuch ohne Fingerabdruck (aus einer älteren Version): vorsichtig behandelt.
$id = ec_start();
$wpdb->query( 'UPDATE ' . mlab_smtp_table( 'attempts' ) . " SET conn_hash = '' WHERE message_id = " . (int) $id );
ec_due( $id );
mlab_smtp_process_queue();
assert_same( 'unclear', mlab_smtp_store_get( $id )['status'], 'no fingerprint: manual check required' );
assert_same( 1, count( mlab_e_resend_requests() ), 'no fingerprint: nothing sent' );

// 5. Geänderter Absender ändert die Verbindung nicht.
$id = ec_start();
mlab_smtp_store( array( 'from_email' => 'other@example.test' ) );
ec_due( $id );
mlab_smtp_process_queue();
assert_same( 'accepted', mlab_smtp_store_get( $id )['status'], 'sender change: retry still goes out' );
echo "ok\n";
