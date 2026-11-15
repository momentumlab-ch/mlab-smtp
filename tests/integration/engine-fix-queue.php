<?php
// Fix 1: Race zwischen Anlegen der Zeile und Schreiben der Nutzlast (Modus queue).
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/engine-helpers.php';
global $wpdb;

function eng_ok(): array { return array( 'code' => 200, 'body' => array( 'id' => 'em_race' ) ); }

// 1a. Ein Worker übernimmt die Zeile genau nach dem INSERT, bevor eine zweite Anweisung die Nutzlast schreiben könnte.
mlab_e_reset( array( 'delivery_mode' => 'queue' ) );
mlab_e_reply( '#api\.resend\.com/emails#', 'eng_ok' );
$inner = false;
// The recipient index is written right after the message INSERT and before any later payload UPDATE: a worker of another request runs here.
$claim = static function ( $query ) use ( &$inner ) {
	if ( ! $inner && preg_match( '/^\s*INSERT INTO `?' . preg_quote( mlab_smtp_table( 'recipients' ), '/' ) . '/i', $query ) ) {
		$inner = true;
		mlab_smtp_process_queue();
		$inner = false;
	}
	return $query;
};
add_filter( 'query', $claim );
wp_mail( 'user@example.test', 'Betreff', 'Text' );
remove_filter( 'query', $claim );
$row = mlab_e_last_row();
assert_true( 'failed' !== $row['status'], 'race: the message is not failed by a worker that saw no payload (' . $row['status'] . ')' );
assert_true( in_array( $row['status'], array( 'queued', 'accepted' ), true ), 'race: row is queued or already accepted' );
mlab_smtp_process_queue();
$row = mlab_smtp_store_get( (int) $row['id'] );
assert_same( 'accepted', $row['status'], 'race: the message is finally delivered' );
assert_same( 1, count( mlab_e_resend_requests() ), 'race: sent exactly once' );

// 1b. Eine Zeile ohne Nutzlast (eben angelegt) ist nicht bereit: der Worker gibt sie frei, statt sie als fehlgeschlagen abzuschliessen.
mlab_e_reset( array( 'delivery_mode' => 'queue' ) );
mlab_e_reply( '#api\.resend\.com/emails#', 'eng_ok' );
$id = mlab_smtp_store_create( array( 'mode' => 'queued', 'status' => 'queued', 'provider' => 'resend', 'recipients' => array( 'user@example.test' ), 'subject' => 'S', 'max_attempts' => 6 ) );
$done = mlab_smtp_process_queue();
$row  = mlab_smtp_store_get( $id );
assert_same( 'queued', $row['status'], 'no payload yet: released, not failed' );
assert_same( 0, count( mlab_e_resend_requests() ), 'no payload yet: nothing sent' );
assert_same( 0, (int) $row['has_payload'], 'no payload yet: still none' );

// 1c. set_payload überschreibt keine Zeile, deren Status sich schon geändert hat.
$snap = array( 'v' => 1, 'to' => array( array( 'a@example.test', '' ) ) );
mlab_smtp_store_set_status( $id, 'failed', array( 'last_error' => 'x' ) );
assert_same( false, mlab_smtp_store_set_payload( $id, $snap, 0, array( 'queued' ) ), 'guard: set_payload refuses a row whose status changed' );
$row = mlab_smtp_store_get( $id );
assert_same( 'failed', $row['status'], 'guard: status untouched' );
assert_same( 0, (int) $row['has_payload'], 'guard: no payload written' );
assert_same( true, mlab_smtp_store_set_payload( $id, $snap, 0, array( 'failed' ) ), 'guard: allowed status passes' );
