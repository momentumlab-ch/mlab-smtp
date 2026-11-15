<?php
// Fix 3: Ereignisse während «processing» werden gemerkt und erst nach dem Abschluss angewendet; nie zurückstufen.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/engine-helpers.php';
global $wpdb;

function eng_delivered_event( string $uuid, string $id ): void {
	$payload = array( 'type' => 'email.delivered', 'created_at' => gmdate( 'c' ), 'data' => array( 'email_id' => 'em_x', 'tags' => array( 'mlab_id' => str_replace( '-', '', $uuid ) ) ) );
	mlab_smtp_webhook_process( 'resend', $id, $payload );
}
$accepted = 0;
add_action( 'mlab_smtp_message_accepted', static function () use ( &$accepted ) { ++$accepted; } );

// 3a. Worker: Ereignis während des Sendens.
mlab_e_reset();
$id   = mlab_e_queue_message();
$uuid = mlab_smtp_store_get( $id )['uuid'];
mlab_e_reply( '#api\.resend\.com/emails#', static function () use ( $uuid ) {
	eng_delivered_event( $uuid, 'evt-w' );
	return array( 'code' => 200, 'body' => array( 'id' => 'em_x' ) );
} );
$done = mlab_smtp_process_queue();
$row  = mlab_smtp_store_get( $id );
assert_same( array( 'accepted' => 1 ), $done, 'worker: completion is not lost' );
assert_same( 'delivered', $row['status'], 'worker: delivered event applied after completion' );
assert_same( 0, (int) $row['has_payload'], 'worker: payload cleared' );
assert_same( 1, $accepted, 'worker: accepted hook fired once' );
assert_same( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'events' ) ), 'worker: event stored' );

// 3b. Synchron: Ereignis vor write_final.
$accepted = 0;
mlab_e_reset();
mlab_e_reply( '#api\.resend\.com/emails#', static function () {
	$row = mlab_e_last_row();
	eng_delivered_event( $row['uuid'], 'evt-s' );
	return array( 'code' => 200, 'body' => array( 'id' => 'em_x' ) );
} );
wp_mail( 'user@example.test', 'S', 'T' );
$row = mlab_e_last_row();
assert_same( 'delivered', $row['status'], 'sync: delivered is not downgraded to accepted' );
assert_same( 1, $accepted, 'sync: accepted hook once' );

// 3c. Kein Herabstufen durch set_status mit Schutz.
mlab_smtp_store_set_status( (int) $row['id'], 'accepted', array(), 0, true );
assert_same( 'delivered', mlab_smtp_store_get( (int) $row['id'] )['status'], 'guarded set_status keeps delivered' );
