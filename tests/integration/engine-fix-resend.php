<?php
// Fix 8: manuelles «Erneut senden»: erst Compare-and-Set, dann Zähler und Nutzlast (in derselben Anweisung).
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/engine-helpers.php';
global $wpdb;

mlab_e_reset();
$id = mlab_e_queue_message( array( 'status' => 'unclear', 'completed_at' => time() ) );
$first = mlab_smtp_engine_manual_resend( $id );
assert_true( true === $first, 'resend: first click wins' );
$second = mlab_smtp_engine_manual_resend( $id );
assert_true( is_wp_error( $second ), 'resend: double click is refused' );
assert_same( 1, (int) mlab_smtp_store_get_payload( $id )['resend'], 'resend: counter bumped once' );

// Eine Anfrage, die das Rennen verliert, schreibt keine Nutzlast mehr in eine Zeile, die der Worker schon abgeschlossen hat.
$id = mlab_e_queue_message( array( 'status' => 'unclear', 'completed_at' => time() ) );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . mlab_smtp_table( 'messages' ) . " SET status = 'accepted', payload = NULL WHERE id = %d", $id ) );
$lost = mlab_smtp_engine_manual_resend( $id );
assert_true( is_wp_error( $lost ), 'resend: lost race is refused' );
assert_same( 0, (int) mlab_smtp_store_get( $id )['has_payload'], 'resend: no payload written back to a settled row' );

// Zwischen Lesen und Setzen wird die Zeile geändert (Simulation über den Filter der Abfrage).
$id  = mlab_e_queue_message( array( 'status' => 'unclear', 'completed_at' => time() ) );
$hit = false;
$f   = static function ( $q ) use ( &$hit, $id, $wpdb ) {
	if ( ! $hit && preg_match( '/^\s*UPDATE `?' . preg_quote( mlab_smtp_table( 'messages' ), '/' ) . '/i', $q ) ) {
		$hit = true;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . mlab_smtp_table( 'messages' ) . " SET status = 'accepted', payload = NULL WHERE id = %d", $id ) );
	}
	return $q;
};
add_filter( 'query', $f );
$res = mlab_smtp_engine_manual_resend( $id );
remove_filter( 'query', $f );
assert_true( is_wp_error( $res ), 'resend: change between read and write is refused' );
assert_same( 0, (int) mlab_smtp_store_get( $id )['has_payload'], 'resend: payload stays removed' );
