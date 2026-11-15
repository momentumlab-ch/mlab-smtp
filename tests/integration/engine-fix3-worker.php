<?php
// Dritte Nachprüfung: unklar bleibt unklar (nie failed), Weg+Anbieter, Sweep-Folgen, Ausnahmen, Statuswechsel nur bei Änderung.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/engine-helpers.php';
global $wpdb;

function eng_due( int $id ): void {
	global $wpdb;
	$wpdb->update( mlab_smtp_table( 'messages' ), array( 'next_attempt_at' => time() - 1 ), array( 'id' => $id ) );
}
function eng_setup( callable $cb, array $settings = array() ): void {
	mlab_e_reset( $settings );
	mlab_e_reply( '#api\.resend\.com/emails#', $cb );
}

// N1: Runde 1 unklar, Runde 2 endgültig/Höchstzahl: nie «failed», Inhalt bleibt.
foreach ( array( 'temporary at the limit' => array( 503, 2 ), 'permanent' => array( 422, 6 ) ) as $label => $conf ) {
	$n = 0;
	eng_setup( static function () use ( &$n, $conf ) { return 1 === ++$n ? mlab_e_timeout() : array( 'code' => $conf[0], 'body' => array( 'name' => 'x', 'message' => 'no' ) ); }, array( 'max_attempts' => $conf[1] ) );
	$id = mlab_e_queue_message( array( 'max_attempts' => $conf[1] ) );
	mlab_smtp_process_queue();
	eng_due( $id );
	mlab_smtp_process_queue();
	$row = mlab_smtp_store_get( $id );
	assert_same( 'unclear', $row['status'], "n1 ($label): unclear, never failed" );
	assert_same( 1, (int) $row['has_payload'], "n1 ($label): payload kept" );
}

// N2: der Anbieter des unklaren Wegs wurde gewechselt: sofort unklar, keine Anfrage an einen anderen Anbieter.
eng_setup( static fn() => mlab_e_timeout() );
$id = mlab_e_queue_message();
mlab_smtp_process_queue();
assert_same( 1, count( mlab_e_resend_requests() ), 'n2: first attempt' );
mlab_smtp_store( array( 'provider' => 'smtp' ) );
eng_due( $id );
mlab_smtp_process_queue();
$row = mlab_smtp_store_get( $id );
assert_same( 'unclear', $row['status'], 'n2: provider of the unclear route changed: unclear at once' );
assert_same( 1, count( mlab_e_resend_requests() ), 'n2: the same key is not sent to another provider' );
assert_same( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'attempts' ) . ' WHERE message_id = ' . $id ), 'n2: no second attempt row' );

// N3: Sweep, beendeter Versuch angenommen: Aktionen, Zuordnung wartender Ereignisse; partial bleibt partial.
eng_setup( static fn() => array( 'code' => 200, 'body' => array( 'id' => 'em_x' ) ) );
$changed = array();
$accepted = 0;
add_action( 'mlab_smtp_message_status_changed', static function ( $id, $new, $old, $src ) use ( &$changed ) { $changed[] = array( $id, $new, $old, $src ); }, 10, 4 );
add_action( 'mlab_smtp_message_accepted', static function () use ( &$accepted ) { ++$accepted; } );
mlab_smtp_webhook_process( 'resend', 'evt-orphan', array( 'type' => 'email.delivered', 'created_at' => gmdate( 'c' ), 'data' => array( 'email_id' => 'em_sw', 'tags' => new stdClass() ) ) );
$id = mlab_e_queue_message();
$t  = time();
mlab_smtp_store_claim_batch( 1, 300, $t );
$a = mlab_smtp_store_add_attempt( $id, array( 'route' => 'primary', 'provider' => 'resend', 'started_at' => $t, 'request_sent_at' => $t ) );
mlab_smtp_store_finish_attempt( $a, array( 'outcome' => 'accepted', 'provider_msg_id' => 'em_sw' ) );
mlab_smtp_store_sweep( $t + 1000 );
assert_same( 'delivered', mlab_smtp_store_get( $id )['status'], 'n3: orphan event matched and applied after the sweep accepted' );
assert_true( in_array( array( $id, 'accepted', 'processing', 'worker' ), $changed, true ), 'n3: status_changed fired for the sweep' );
assert_same( 1, $accepted, 'n3: accepted action fired' );
$id = mlab_e_queue_message();
mlab_smtp_store_claim_batch( 1, 300, $t );
$a = mlab_smtp_store_add_attempt( $id, array( 'route' => 'primary', 'provider' => 'resend', 'started_at' => $t, 'request_sent_at' => $t ) );
mlab_smtp_store_finish_attempt( $a, array( 'outcome' => 'partial' ) );
mlab_smtp_store_sweep( $t + 1000 );
assert_same( 'partial', mlab_smtp_store_get( $id )['status'], 'n3: finished partial attempt ends partial' );

// N4: eine Datenbank-Ausnahme beim Rückfall in den Hintergrund entkommt wp_mail() nicht.
eng_setup( static fn() => array( 'code' => 503, 'body' => array( 'message' => 'down' ) ), array( 'retry_in_background' => 1, 'max_attempts' => 3 ) );
$f = static function ( $q ) {
	if ( str_contains( $q, "`status` = 'temp_failed'" ) && preg_match( '/^\s*UPDATE/i', $q ) ) {
		throw new RuntimeException( 'driver' );
	}
	return $q;
};
add_filter( 'query', $f );
$thrown = false;
try {
	wp_mail( 'user@example.test', 'S', 'T' );
} catch ( Throwable $e ) {
	$thrown = true;
}
remove_filter( 'query', $f );
assert_same( false, $thrown, 'n4: no exception escapes wp_mail()' );

// N5: status_changed nur bei echter Änderung.
eng_setup( static fn() => array( 'code' => 200, 'body' => array( 'id' => 'em_x' ) ) );
$id = mlab_e_queue_message( array( 'status' => 'accepted', 'completed_at' => time() ) );
$changed = array();
mlab_smtp_engine_set_status( $id, 'accepted', array(), 'sync' );
assert_same( array(), $changed, 'n5: no status_changed when the status did not change' );
$id = mlab_e_queue_message( array( 'status' => 'processing', 'lease' => 300 ) );
mlab_smtp_engine_set_status( $id, 'accepted', array(), 'sync' );
assert_same( 1, count( $changed ), 'n5: fired for a real change' );
