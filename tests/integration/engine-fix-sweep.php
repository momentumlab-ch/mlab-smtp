<?php
// Fix 6 und 7: Sweep setzt Ablauf der Nutzlast, zählt nur Versuche des aktuellen Claims und begrenzt Wiedereinreihungen.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/engine-helpers.php';
global $wpdb;

// 6. unklar nach Sweep: Nutzlast läuft ab (höchstens 14 Tage).
mlab_e_reset();
$id = mlab_e_queue_message();
$t  = time();
$row = mlab_smtp_store_claim_batch( 1, 300, $t )[0];
mlab_smtp_store_add_attempt( $id, array( 'route' => 'primary', 'provider' => 'resend', 'started_at' => $t, 'request_sent_at' => $t ) );
$count = mlab_smtp_store_sweep( $t + 1000 );
$m     = mlab_smtp_store_get( $id );
assert_same( 1, $count['unclear'], 'sweep: unclear' );
assert_same( 'unclear', $m['status'], 'sweep: status unclear' );
$exp = (int) $m['payload_expires_at'];
assert_true( $exp > $t + 1000 && $exp <= $t + 1000 + 14 * DAY_IN_SECONDS + 5, 'sweep: unclear payload expires within 14 days (' . $exp . ')' );
assert_same( 1, (int) $m['has_payload'], 'sweep: payload kept for the manual decision' );

// 7a. Runde 2: der Worker stirbt vor dem Senden. Der Versuch aus Runde 1 zählt nicht, die Nachricht wird wieder eingereiht.
mlab_e_reset();
$id = mlab_e_queue_message();
$t  = time() - 5000;
$a  = mlab_smtp_store_add_attempt( $id, array( 'route' => 'primary', 'provider' => 'resend', 'started_at' => $t, 'request_sent_at' => $t ) );
mlab_smtp_store_finish_attempt( $a, array( 'outcome' => 'temporary', 'http_status' => 503 ) );
$wpdb->update( mlab_smtp_table( 'messages' ), array( 'status' => 'temp_failed', 'next_attempt_at' => $t ), array( 'id' => $id ) );
$now = time();
assert_same( 1, count( mlab_smtp_store_claim_batch( 1, 300, $now ) ), 'round 2 claimed' );
$count = mlab_smtp_store_sweep( $now + 1000 );
assert_same( 1, $count['requeued'], 'round 2: died before sending, requeued' );
assert_same( 'queued', mlab_smtp_store_get( $id )['status'], 'round 2: status queued, not unclear' );

// 7b. Ein Fatal vor jeder Anfrage reiht nicht endlos wieder ein.
mlab_e_reset();
$id = mlab_e_queue_message( array( 'max_attempts' => 3 ) );
$now = time();
$final = '';
for ( $i = 1; $i <= 10 && '' === $final; $i++ ) {
	$now += 2000;
	$claimed = mlab_smtp_store_claim_batch( 1, 300, $now );
	if ( ! $claimed ) {
		break;
	}
	mlab_smtp_store_sweep( $now + 1000 );
	$st = mlab_smtp_store_get( $id )['status'];
	if ( 'queued' !== $st ) {
		$final = $st;
	}
}
assert_same( 'failed', $final, 'cap: endless requeue ends as failed' );
assert_true( $i <= 5, 'cap: after at most max_attempts requeues (' . $i . ')' );
$m = mlab_smtp_store_get( $id );
assert_true( '' !== $m['last_error'], 'cap: reason stored' );
assert_true( (int) $m['payload_expires_at'] > 0, 'cap: failed payload has an expiry' );
