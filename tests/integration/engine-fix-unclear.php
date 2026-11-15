<?php
// Fix 4 und 5: kein Ersatzweg nach unklarem Ergebnis (über Runden hinweg), 23-h-Regel beim Übernehmen.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/engine-helpers.php';
global $wpdb;

function eng_due( int $id ): void {
	global $wpdb;
	$wpdb->update( mlab_smtp_table( 'messages' ), array( 'next_attempt_at' => time() - 1 ), array( 'id' => $id ) );
}
function eng_is_main( array $a ): bool { return str_contains( $a['headers']['Authorization'], 'main' ); }
function eng_reply( callable $cb, array $settings = array() ): void {
	mlab_e_reset( $settings );
	mlab_e_with_fallback();
	mlab_e_reply( '#api\.resend\.com/emails#', $cb );
}
function eng_ok(): array { return array( 'code' => 200, 'body' => array( 'id' => 'em_w' ) ); }

// 4a. Runde 1: Hauptweg unklar (kein Wechsel). Runde 2: Hauptweg 503: der Ersatzweg darf NICHT senden.
$main_calls = 0;
eng_reply( static function ( $u, $a ) use ( &$main_calls ) {
	if ( eng_is_main( $a ) ) {
		++$main_calls;
		return 1 === $main_calls ? mlab_e_timeout() : array( 'code' => 503, 'body' => array( 'message' => 'down' ) );
	}
	return eng_ok();
} );
$id = mlab_e_queue_message();
mlab_smtp_process_queue();
eng_due( $id );
mlab_smtp_process_queue();
$fb = array_filter( mlab_e_resend_requests(), static fn( $r ) => ! str_contains( mlab_e_header( $r, 'Authorization' ), 'main' ) );
assert_same( 0, count( $fb ), 'two rounds: the fallback never sends after an unclear primary' );
assert_true( 'accepted' !== mlab_smtp_store_get( $id )['status'], 'two rounds: not accepted through the fallback' );

// 4b. Hauptweg pausiert (Retry-After) nach unklarer Runde 1: Ersatzweg darf nicht einspringen, die Nachricht wartet.
$main_calls = 0;
eng_reply( static function ( $u, $a ) use ( &$main_calls ) {
	if ( eng_is_main( $a ) ) {
		++$main_calls;
		return mlab_e_timeout();
	}
	return eng_ok();
} );
$id = mlab_e_queue_message();
mlab_smtp_process_queue();
eng_due( $id );
mlab_smtp_engine_pauses( mlab_smtp_engine_pause_key( 'primary', 'resend' ), time() + 600 );
mlab_smtp_process_queue();
$fb = array_filter( mlab_e_resend_requests(), static fn( $r ) => ! str_contains( mlab_e_header( $r, 'Authorization' ), 'main' ) );
assert_same( 0, count( $fb ), 'paused primary: the fallback does not send after an unclear primary' );
assert_true( in_array( mlab_smtp_store_get( $id )['status'], array( 'queued', 'temp_failed' ), true ), 'paused primary: message waits' );

// 4c. Mit der Einstellung darf der Ersatzweg einspringen.
eng_reply( static fn( $u, $a ) => eng_is_main( $a ) ? mlab_e_timeout() : eng_ok(), array( 'queue_unclear_failover' => 1 ) );
$id = mlab_e_queue_message();
mlab_smtp_process_queue();
assert_same( 'accepted', mlab_smtp_store_get( $id )['status'], 'setting on: fallback after unclear works' );

// 5. 23-h-Regel beim Übernehmen: nach Ablauf des Fensters kein automatischer Versand.
eng_reply( static fn() => mlab_e_timeout() );
$id = mlab_e_queue_message();
mlab_smtp_process_queue();
assert_same( 1, count( mlab_e_resend_requests() ), 'window: first attempt sent' );
$wpdb->update( mlab_smtp_table( 'attempts' ), array( 'started_at' => time() - 24 * HOUR_IN_SECONDS ), array( 'message_id' => $id ) );
eng_due( $id );
mlab_smtp_process_queue();
$row = mlab_smtp_store_get( $id );
assert_same( 1, count( mlab_e_resend_requests() ), 'window: no resend after the idempotency key expired' );
assert_same( 'unclear', $row['status'], 'window: stays unclear for a manual decision' );
assert_same( 1, (int) $row['has_payload'], 'window: payload kept' );
assert_true( (int) $wpdb->get_var( 'SELECT payload_expires_at FROM ' . mlab_smtp_table( 'messages' ) . ' WHERE id = ' . $id ) > 0, 'window: payload has an expiry' );
assert_contains( 'Idempotenz-Fenster abgelaufen', $row['last_error'], 'window: reason shown' );
