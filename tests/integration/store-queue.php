<?php
// Store: Claim, Lease, Rang, Seiten, Suche, Bereinigung, Nutzlast, gleichzeitiges Protokollieren, Maskierung.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/store-helpers.php';
global $wpdb;
mlab_t_reset_store();
$now = 1800000000;

// Claim-Exklusivität.
$id = mlab_t_msg( array( 'next_attempt_at' => $now - 10 ) );
$a  = mlab_smtp_store_claim_batch( 5, 300, $now );
$b  = mlab_smtp_store_claim_batch( 5, 300, $now );
assert_same( 1, count( $a ), 'first claim wins the row' );
assert_same( 0, count( $b ), 'second claim of the same row gets nothing' );
assert_same( 'processing', $a[0]['status'], 'claimed row is processing' );
assert_true( strlen( (string) $a[0]['lock_token'] ) >= 16, 'claimed row has a lock token' );
assert_same( $now + 300, (int) $a[0]['locked_until'], 'lease is at least five minutes' );
assert_same( false, mlab_smtp_store_complete( $id, 'wrong-token', 'accepted', array(), $now ), 'wrong lock token cannot complete' );
assert_same( false, mlab_smtp_store_release( $id, 'wrong-token', $now + 60, array(), 'temp_failed' ), 'wrong lock token cannot release' );
assert_same( 'processing', mlab_smtp_store_get( $id )['status'], 'row unchanged after a wrong token' );
assert_true( mlab_smtp_store_release( $id, $a[0]['lock_token'], $now + 60, array( 'last_error' => 'timeout' ), 'temp_failed' ), 'release with the right token' );
$row = mlab_smtp_store_get( $id );
assert_same( 'temp_failed', $row['status'], 'released row is temp_failed' );
assert_true( null === $row['lock_token'] && null === $row['locked_until'], 'release clears the lock' );
assert_same( 0, count( mlab_smtp_store_claim_batch( 5, 300, $now ) ), 'not due yet' );
$c = mlab_smtp_store_claim_batch( 5, 300, $now + 61 );
assert_same( 1, count( $c ), 'due again after next_attempt_at' );
assert_true( mlab_smtp_store_complete( $id, $c[0]['lock_token'], 'accepted', array( 'provider_msg_id' => 'em_1' ), $now + 62 ), 'complete with the right token' );
assert_same( 'accepted', mlab_smtp_store_get( $id )['status'], 'completed as accepted' );
assert_same( false, mlab_smtp_store_complete( $id, $c[0]['lock_token'], 'failed', array(), $now + 63 ), 'a second completion with the old token is discarded' );

// Lease-Ablauf: vor request_sent_at -> queued, danach -> unclear.
mlab_t_reset_store();
$p = array( 'to' => array( 'user@example.test' ) );
$q1 = mlab_t_msg( array( 'next_attempt_at' => $now ) );
$q2 = mlab_t_msg( array( 'next_attempt_at' => $now ) );
assert_true( true === mlab_smtp_store_set_payload( $q1, $p ) && true === mlab_smtp_store_set_payload( $q2, $p ), 'payloads stored' );
$claimed = mlab_smtp_store_claim_batch( 5, 300, $now );
assert_same( 2, count( $claimed ), 'two rows claimed' );
mlab_smtp_store_add_attempt( $q1, array( 'route' => 'primary', 'provider' => 'resend', 'started_at' => $now ) );
$att = mlab_smtp_store_add_attempt( $q2, array( 'route' => 'primary', 'provider' => 'resend', 'started_at' => $now ) );
mlab_smtp_store_mark_request_sent( $att, $now + 1 );
assert_same( array( 'requeued' => 0, 'unclear' => 0 ), mlab_smtp_store_sweep( $now + 100 ), 'lease not expired: sweep leaves rows alone' );
$sw = mlab_smtp_store_sweep( $now + 301 );
assert_same( array( 'requeued' => 1, 'unclear' => 1 ), $sw, 'expired lease: one requeued, one unclear' );
assert_same( 'queued', mlab_smtp_store_get( $q1 )['status'], 'no request_sent_at -> back to queued' );
assert_same( 'unclear', mlab_smtp_store_get( $q2 )['status'], 'request_sent_at set -> unclear' );
assert_same( 1, (int) mlab_smtp_store_get( $q1 )['attempts'], 'attempt counter counted' );
assert_true( ! mlab_smtp_store_complete( $q2, $claimed[0]['lock_token'], 'accepted', array(), $now + 400 ), 'a worker that lost its lease cannot complete' );
// Synchrone Zeile (ohne Nutzlast) mit abgelaufener Lease -> unklar.
$s = mlab_smtp_store_create( array( 'mode' => 'sync', 'lease' => 300, 'recipients' => 'user@example.test', 'subject' => 's', 'created_at' => $now ) );
assert_same( 'processing', mlab_smtp_store_get( $s )['status'], 'sync row is written before sending as processing' );
mlab_smtp_store_sweep( time() + 1000 );
assert_same( 'unclear', mlab_smtp_store_get( $s )['status'], 'orphaned sync row becomes unclear' );

// Rangregel.
mlab_t_reset_store();
$r = mlab_t_msg( array( 'status' => 'accepted' ) );
assert_true( mlab_smtp_store_apply_event_status( $r, 'delivered' ), 'accepted -> delivered' );
assert_true( ! mlab_smtp_store_apply_event_status( $r, 'accepted' ), 'delivered -> accepted is refused (no downgrade)' );
assert_same( 'delivered', mlab_smtp_store_get( $r )['status'], 'late event does not downgrade' );
assert_true( mlab_smtp_store_apply_event_status( $r, 'bounced' ), 'delivered -> bounced' );
assert_true( ! mlab_smtp_store_apply_event_status( $r, 'delivered' ), 'bounced -> delivered is refused' );
$u = mlab_t_msg( array( 'status' => 'unclear' ) );
assert_true( mlab_smtp_store_apply_event_status( $u, 'accepted' ), 'event resolves unclear' );
$f = mlab_t_msg( array( 'status' => 'queued' ) );
assert_true( ! mlab_smtp_store_apply_event_status( $f, 'delivered' ), 'events do not touch queued rows' );

// Seiten und Suche.
mlab_t_reset_store();
for ( $i = 1; $i <= 25; $i++ ) {
	mlab_t_msg( array( 'status' => 0 === $i % 5 ? 'failed' : 'accepted', 'subject' => "Mail $i", 'recipients' => array( "user$i@example.test" ), 'created_at' => $now + $i, 'last_error' => 5 === $i ? 'Verbindung abgelehnt' : '' ) );
}
$l1 = mlab_smtp_store_list( array( 'per_page' => 10, 'page' => 1 ) );
$l3 = mlab_smtp_store_list( array( 'per_page' => 10, 'page' => 3 ) );
assert_same( 25, $l1['total'], 'total count' );
assert_same( 3, $l1['pages'], 'page count' );
assert_same( 'Mail 25', $l1['items'][0]['subject'], 'newest first' );
assert_same( 5, count( $l3['items'] ), 'last page holds the rest' );
assert_same( 5, mlab_smtp_store_list( array( 'status' => 'failed' ) )['total'], 'status filter' );
assert_same( 1, mlab_smtp_store_list( array( 'search' => 'user7@example.test' ) )['total'], 'search by recipient' );
assert_same( 1, mlab_smtp_store_list( array( 'search' => 'abgelehnt' ) )['total'], 'search in error' );
assert_same( 7, mlab_smtp_store_list( array( 'search' => 'Mail 2' ) )['total'], 'search in subject (Mail 2 and 20..25)' );
assert_same( 0, mlab_smtp_store_list( array( 'search' => "x' OR '1'='1" ) )['total'], 'search is not injectable' );
assert_same( 5, mlab_smtp_store_counts()['failed'], 'counts by status' );
assert_same( 5, count( mlab_smtp_log_get( array( 'status' => 'bad' ) ) ), 'log_get bad = failed' );
assert_same( 20, count( mlab_smtp_log_get( array( 'status' => 'ok' ) ) ), 'log_get ok' );
assert_same( 3, count( mlab_smtp_log_get( array( 'limit' => 3 ) ) ), 'log_get limit' );

// Bereinigung nach Aufbewahrung.
$old  = mlab_t_msg( array( 'status' => 'accepted', 'created_at' => $now - 100 * DAY_IN_SECONDS ) );
$oldq = mlab_t_msg( array( 'status' => 'queued', 'created_at' => $now - 100 * DAY_IN_SECONDS ) );
mlab_smtp_store_add_attempt( $old, array( 'route' => 'primary' ) );
mlab_smtp_store_add_event( array( 'provider' => 'resend', 'event_id' => 'e-old', 'message_id' => $old ) );
$n = mlab_smtp_store_purge( $now + 100, 2, 50 );
assert_same( 26, $n, "purge deletes finished old rows in batches" );
assert_true( null !== mlab_smtp_store_get( $oldq ), 'purge keeps waiting messages' );
assert_same( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'attempts' ) ), 'purge removes attempts' );
assert_same( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'events' ) ), 'purge removes events' );
assert_same( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'recipients' ) . ' WHERE message_id = ' . (int) $old ), 'purge removes recipient index rows' );
$exp = mlab_t_msg( array( 'status' => 'failed' ) );
mlab_smtp_store_set_payload( $exp, array( 'a' => 1 ), time() - 5 );
mlab_smtp_store_clear_expired_payloads();
assert_same( null, mlab_smtp_store_get_payload( $exp ), 'expired payload is cleared' );

// Nutzlast.
$pl = mlab_t_msg();
$snap = array( 'subject' => 'Geheim', 'attachments' => array( array( 'name' => 'a.txt', 'content' => base64_encode( "bin\0ary" ) ) ) );
assert_true( true === mlab_smtp_store_set_payload( $pl, $snap ), 'payload stored' );
$raw = $wpdb->get_var( 'SELECT payload FROM ' . mlab_smtp_table( 'messages' ) . ' WHERE id = ' . (int) $pl );
assert_true( ! str_contains( (string) $raw, 'Geheim' ), 'payload is encrypted at rest' );
assert_same( $snap, mlab_smtp_store_get_payload( $pl ), 'payload round-trips' );
add_filter( 'mlab_smtp_queue_max_bytes', static fn() => 100 );
assert_true( is_wp_error( mlab_smtp_store_set_payload( $pl, array( 'x' => str_repeat( 'a', 500 ) ) ) ), 'size cap on the encoded size' );
remove_all_filters( 'mlab_smtp_queue_max_bytes' );
mlab_smtp_store_complete( $pl, 'x', 'accepted' );
mlab_smtp_store_set_status( $pl, 'accepted' );
assert_same( null, mlab_smtp_store_get_payload( $pl ), 'payload is deleted once the message is accepted' );

// Viele Einträge, keiner geht verloren (jede Nachricht eine Zeile).
mlab_t_reset_store();
update_option( MLAB_SMTP_OPTION, array_merge( (array) get_option( MLAB_SMTP_OPTION, array() ), array( 'log_enabled' => 1 ) ) );
for ( $i = 0; $i < 60; $i++ ) {
	mlab_smtp_store_create( array( 'status' => 'accepted', 'recipients' => "burst$i@example.test", 'subject' => "burst $i" ) );
}
assert_same( 60, mlab_smtp_store_list( array( 'per_page' => 200 ) )['total'], '60 inserts, none lost' );
assert_same( 60, count( array_unique( array_column( mlab_smtp_store_list( array( 'per_page' => 200 ) )['items'], 'uuid' ) ) ), 'all uuids unique' );

// Maskierung.
$m = mlab_t_msg();
mlab_smtp_store_set_status( $m, 'failed', array( 'last_error' => 'Auth failed: Bearer abcdefghijklmnopqrstuvwxyz0123456789 re_abcdefghijklmnop1234567890' ) );
$err = mlab_smtp_store_get( $m )['last_error'];
assert_true( ! str_contains( $err, 'abcdefghijklmnopqrstuvwxyz0123456789' ), 'stored error is redacted' );
$at = mlab_smtp_store_add_attempt( $m );
mlab_smtp_store_finish_attempt( $at, array( 'outcome' => 'permanent', 'error_text' => 'key re_abcdefghijklmnop1234567890 refused' ) );
assert_true( ! str_contains( mlab_smtp_store_attempts( $m )[0]['error_text'], 're_abcdefghijklmnop1234567890' ), 'attempt error text is redacted' );
assert_true( strlen( mlab_smtp_store_hmac_key() ) >= 64 && mlab_smtp_store_hmac_key() === get_option( 'mlab_smtp_hmac_key' ), 'hmac key is random and kept in the option' );
assert_same( mlab_smtp_store_hmac( ' User@Example.test ' ), mlab_smtp_store_hmac( 'user@example.test' ), 'hmac of lowercased, trimmed address' );
t_finish();
