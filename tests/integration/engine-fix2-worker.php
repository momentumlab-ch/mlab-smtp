<?php
// Nachprüfung: Erzeugung (Resend-Zähler), erlaubte Wege nach unklar, Sweep (Ereignisse, abgeschlossene Versuche), Sync-Rückfall.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/engine-helpers.php';
global $wpdb;

function eng_due( int $id ): void {
	global $wpdb;
	$wpdb->update( mlab_smtp_table( 'messages' ), array( 'next_attempt_at' => time() - 1 ), array( 'id' => $id ) );
}
function eng_main( array $a ): bool { return str_contains( $a['headers']['Authorization'], 'main' ); }
function eng_n( bool $main ): int {
	return count( array_filter( mlab_e_resend_requests(), static fn( $r ) => $main === str_contains( mlab_e_header( $r, 'Authorization' ), 'main' ) ) );
}
function eng_ok(): array { return array( 'code' => 200, 'body' => array( 'id' => 'em_w' ) ); }
function eng_503(): array { return array( 'code' => 503, 'body' => array( 'message' => 'down' ) ); }
function eng_setup( callable $cb, array $settings = array(), bool $fb = true ): void {
	mlab_e_reset( $settings );
	if ( $fb ) {
		mlab_e_with_fallback();
	}
	mlab_e_reply( '#api\.resend\.com/emails#', $cb );
}

// NEW 1: eine manuelle Wiederholung nach abgelaufenem Fenster geht raus (neuer Schlüssel).
$n = 0;
eng_setup( static function () use ( &$n ) { return ++$n < 2 ? mlab_e_timeout() : eng_ok(); }, array(), false );
$id = mlab_e_queue_message();
mlab_smtp_process_queue();
$wpdb->update( mlab_smtp_table( 'attempts' ), array( 'started_at' => time() - 24 * HOUR_IN_SECONDS ), array( 'message_id' => $id ) );
eng_due( $id );
mlab_smtp_process_queue();
assert_same( 'unclear', mlab_smtp_store_get( $id )['status'], 'new1: expired window, unclear' );
assert_same( 1, count( mlab_e_resend_requests() ), 'new1: nothing automatic after the window' );
assert_true( true === mlab_smtp_engine_manual_resend( $id ), 'new1: manual resend accepted' );
mlab_smtp_process_queue();
assert_same( 2, count( mlab_e_resend_requests() ), 'new1: the manual resend goes out' );
assert_same( 'accepted', mlab_smtp_store_get( $id )['status'], 'new1: accepted' );
$keys = array_map( static fn( $r ) => mlab_e_header( $r, 'Idempotency-Key' ), mlab_e_resend_requests() );
assert_true( $keys[0] !== $keys[1], 'new1: new idempotency key' );

// NEW 2a: Hauptweg temporär, Ersatzweg unklar: die nächste Runde nutzt nur den Ersatzweg.
$fb_n = 0;
eng_setup( static function ( $u, $a ) use ( &$fb_n ) {
	if ( eng_main( $a ) ) {
		return eng_503();
	}
	return 1 === ++$fb_n ? mlab_e_timeout() : eng_ok();
} );
$id = mlab_e_queue_message();
mlab_smtp_process_queue();
assert_same( 'temp_failed', mlab_smtp_store_get( $id )['status'], 'new2a: round 1 retryable' );
$main_before = eng_n( true );
eng_due( $id );
mlab_smtp_process_queue();
assert_same( $main_before, eng_n( true ), 'new2a: the primary route is not used after an unclear fallback' );
assert_same( 'accepted', mlab_smtp_store_get( $id )['status'], 'new2a: the unclear route is retried with the same key' );

// NEW 2b: fallback_only, unklarer Versuch: wird mit demselben Schlüssel wiederholt statt 23 h zu warten.
$n = 0;
eng_setup( static function () use ( &$n ) { return ++$n < 2 ? mlab_e_timeout() : eng_ok(); } );
mlab_smtp_flag( 'fallback_only', true );
$id = mlab_e_queue_message();
mlab_smtp_process_queue();
eng_due( $id );
mlab_smtp_process_queue();
mlab_smtp_flag( 'fallback_only', false );
assert_same( 'accepted', mlab_smtp_store_get( $id )['status'], 'new2b: fallback_only message is retried, not held' );

// NEW 2c: der unklare Weg ist nicht mehr verfügbar: sofort unklar, kein Warten.
eng_setup( static fn( $u, $a ) => eng_main( $a ) ? eng_503() : mlab_e_timeout() );
$id = mlab_e_queue_message();
mlab_smtp_process_queue();
mlab_smtp_store( array( 'fallback_enabled' => 0 ) );
eng_due( $id );
mlab_smtp_process_queue();
$row = mlab_smtp_store_get( $id );
assert_same( 'unclear', $row['status'], 'new2c: unclear at once when the unclear route is gone' );
assert_same( 1, (int) $row['has_payload'], 'new2c: payload kept for the manual decision' );

// NEW 3: Ereignis während der Verarbeitung, Worker stirbt, Sweep: das Ereignis wird angewendet.
eng_setup( 'eng_ok', array(), false );
$id   = mlab_e_queue_message();
$uuid = mlab_smtp_store_get( $id )['uuid'];
$t    = time();
mlab_smtp_store_claim_batch( 1, 300, $t );
mlab_smtp_store_add_attempt( $id, array( 'route' => 'primary', 'provider' => 'resend', 'started_at' => $t, 'request_sent_at' => $t ) );
mlab_smtp_webhook_process( 'resend', 'evt-sw', array( 'type' => 'email.delivered', 'created_at' => gmdate( 'c' ), 'data' => array( 'email_id' => 'em_s', 'tags' => array( 'mlab_id' => str_replace( '-', '', $uuid ) ) ) ) );
assert_same( 'processing', mlab_smtp_store_get( $id )['status'], 'new3: event did not touch the processing row' );
mlab_smtp_store_sweep( $t + 1000 );
assert_same( 'delivered', mlab_smtp_store_get( $id )['status'], 'new3: the stored event is applied after the sweep' );

// NEW 4: Versuch beendet (angenommen), Runde nie abgeschlossen: Sweep reiht nicht neu ein.
eng_setup( 'eng_ok', array(), false );
$id = mlab_e_queue_message();
$t  = time();
mlab_smtp_store_claim_batch( 1, 300, $t );
$a = mlab_smtp_store_add_attempt( $id, array( 'route' => 'primary', 'provider' => 'resend', 'started_at' => $t, 'request_sent_at' => $t ) );
mlab_smtp_store_finish_attempt( $a, array( 'outcome' => 'accepted', 'provider_msg_id' => 'em_done' ) );
mlab_smtp_store_sweep( $t + 1000 );
$row = mlab_smtp_store_get( $id );
assert_same( 'accepted', $row['status'], 'new4: finished accepted attempt settles accepted, no requeue' );
assert_same( 'em_done', $row['provider_msg_id'], 'new4: provider id taken over' );
assert_same( 0, (int) $row['has_payload'], 'new4: payload cleared' );
$id = mlab_e_queue_message();
mlab_smtp_store_claim_batch( 1, 300, $t );
$a = mlab_smtp_store_add_attempt( $id, array( 'route' => 'primary', 'provider' => 'resend', 'started_at' => $t, 'request_sent_at' => $t ) );
mlab_smtp_store_finish_attempt( $a, array( 'outcome' => 'unknown' ) );
mlab_smtp_store_sweep( $t + 1000 );
assert_same( 'unclear', mlab_smtp_store_get( $id )['status'], 'new4: finished unknown attempt is unclear, no requeue' );
$id = mlab_e_queue_message();
mlab_smtp_store_claim_batch( 1, 300, $t );
$a = mlab_smtp_store_add_attempt( $id, array( 'route' => 'primary', 'provider' => 'resend', 'started_at' => $t, 'request_sent_at' => $t ) );
mlab_smtp_store_finish_attempt( $a, array( 'outcome' => 'temporary' ) );
mlab_smtp_store_sweep( $t + 1000 );
assert_same( 'queued', mlab_smtp_store_get( $id )['status'], 'new4: a definite refusal is safe to requeue' );

// 5. set_status mit keep_events: «keine Änderung» zählt als Erfolg (MySQL meldet 0 betroffene Zeilen).
eng_setup( 'eng_ok', array(), false );
$id = mlab_e_queue_message( array( 'status' => 'delivered', 'completed_at' => time() ) );
$now = time();
assert_true( mlab_smtp_store_set_status( $id, 'accepted', array(), $now, true ), 'min5: first guarded call ok' );
assert_true( mlab_smtp_store_set_status( $id, 'accepted', array(), $now, true ), 'min5: repeated call in the same second still reports success' );
assert_same( 'delivered', mlab_smtp_store_get( $id )['status'], 'min5: still delivered' );
$id = mlab_e_queue_message( array( 'status' => 'accepted', 'completed_at' => $now ) );
assert_true( mlab_smtp_store_set_status( $id, 'accepted', array(), $now ), 'min5: same status twice in a second is no failure' );
assert_true( mlab_smtp_store_set_status( $id, 'accepted', array(), $now ), 'min5: and again' );

// 6. Rückfall in den Hintergrund: ändert sich die Zeile zwischendurch, wird sie nicht überschrieben.
eng_setup( static fn() => eng_503(), array( 'retry_in_background' => 1, 'max_attempts' => 3 ), false );
$hit = false;
$f   = static function ( $q ) use ( &$hit, $wpdb ) {
	if ( ! $hit && str_contains( $q, "`status` = 'temp_failed'" ) && preg_match( '/^\s*UPDATE/i', $q ) ) {
		$hit = true;
		$wpdb->query( 'UPDATE ' . mlab_smtp_table( 'messages' ) . " SET status = 'unclear', lock_token = NULL, locked_until = NULL" );
	}
	return $q;
};
add_filter( 'query', $f );
wp_mail( 'user@example.test', 'S', 'T' );
remove_filter( 'query', $f );
assert_true( $hit, 'min6: the race was simulated' );
assert_true( 'temp_failed' !== mlab_e_last_row()['status'], 'min6: a changed row is not overwritten by temp_failed' );

// 8. Log-Hook stuft «delivered» nicht herab.
eng_setup( 'eng_ok', array(), false );
$id = mlab_e_queue_message( array( 'status' => 'delivered', 'completed_at' => time() ) );
mlab_smtp_log_active_message( $id );
mlab_smtp_log_add( array( 'to' => array( 'user@example.test' ), 'subject' => 'S' ), null );
mlab_smtp_log_active_message( 0 );
assert_same( 'delivered', mlab_smtp_store_get( $id )['status'], 'min8: log hook does not downgrade delivered' );
