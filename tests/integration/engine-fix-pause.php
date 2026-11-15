<?php
// Fix 9: Pause je Weg und Anbieter: ein 429 des Hauptkontos pausiert das zweite Resend-Konto nicht.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/engine-helpers.php';
global $wpdb;

mlab_e_reset();
mlab_e_with_fallback();
mlab_e_reply( '#api\.resend\.com/emails#', static function ( $u, $a ) {
	if ( str_contains( $a['headers']['Authorization'], 'main' ) ) {
		return array( 'code' => 429, 'body' => array( 'name' => 'rate_limit_exceeded', 'message' => 'slow' ), 'headers' => array( 'retry-after' => '120' ) );
	}
	return array( 'code' => 200, 'body' => array( 'id' => 'em_fb' ) );
} );
$one = mlab_e_queue_message();
mlab_smtp_process_queue();
assert_same( 'accepted', mlab_smtp_store_get( $one )['status'], 'message 1: accepted through the fallback account' );
$pauses = mlab_smtp_engine_pauses();
assert_true( isset( $pauses[ mlab_smtp_engine_pause_key( 'primary', 'resend' ) ] ), 'main account is paused' );
assert_true( ! isset( $pauses[ mlab_smtp_engine_pause_key( 'fallback', 'resend' ) ] ), 'fallback account is not paused' );
$two = mlab_e_queue_message();
$before = count( mlab_e_resend_requests() );
mlab_smtp_process_queue();
assert_same( 'accepted', mlab_smtp_store_get( $two )['status'], 'message 2: the fallback account still sends while the main account pauses' );
assert_same( $before + 1, count( mlab_e_resend_requests() ), 'message 2: exactly one request, to the fallback account' );
