<?php
// Löschung: Fehler in jeder Tabelle rollen zurück, werden gemeldet, bleiben auffindbar und sind wiederholbar.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/store-helpers.php';
global $wpdb;

function mlab_t_seed_two(): array {
	mlab_t_reset_store();
	$ids = array();
	for ( $i = 0; $i < 2; $i++ ) {
		$ids[] = $id = mlab_t_msg( array( 'status' => 'accepted', 'recipients' => array( 'user@example.test' ), 'subject' => "S$i" ) );
		mlab_smtp_store_add_attempt( $id, array( 'route' => 'primary' ) );
		mlab_smtp_store_add_event( array( 'provider' => 'resend', 'event_id' => "e$i", 'message_id' => $id, 'detail' => 'x' ) );
	}
	return $ids;
}
function mlab_t_counts(): array {
	global $wpdb;
	$out = array();
	foreach ( array( 'messages', 'attempts', 'events', 'recipients' ) as $t ) {
		$out[ $t ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( $t ) );
	}
	return $out;
}
function mlab_t_break( string $table ): Closure {
	$f = static fn( $sql ) => str_starts_with( $sql, 'DELETE FROM `' . mlab_smtp_table( $table ) . '`' ) ? 'DELETE FROM mlab_smtp_missing_table' : $sql;
	add_filter( 'query', $f );
	return $f;
}

assert_true( mlab_smtp_store_transactional(), 'the store tables support transactions' );
foreach ( array( 'attempts', 'events', 'recipients', 'messages' ) as $table ) {
	mlab_t_seed_two();
	$full = mlab_t_counts();
	$f    = mlab_t_break( $table );
	$e    = mlab_smtp_privacy_erase( 'user@example.test', 1 );
	remove_filter( 'query', $f );
	assert_true( $e['items_retained'] && array() !== $e['messages'], "$table: failure is reported as retained data with a message" );
	assert_same( 0, $e['items_removed'], "$table: no partial success is reported" );
	assert_same( $full, mlab_t_counts(), "$table: rolled back, nothing removed" );
	assert_same( 2, count( mlab_smtp_store_ids_by_email( 'user@example.test' ) ), "$table: the data can still be found" );
	$e = mlab_smtp_privacy_erase( 'user@example.test', 1 );
	assert_true( ! $e['items_retained'] && 2 === $e['items_removed'], "$table: a repeated attempt succeeds" );
	assert_same( array( 'messages' => 0, 'attempts' => 0, 'events' => 0, 'recipients' => 0 ), mlab_t_counts(), "$table: no orphans left" );
}

// «Protokoll leeren» und Aufbewahrung benutzen dieselbe Löschung.
mlab_t_seed_two();
$full = mlab_t_counts();
$f    = mlab_t_break( 'events' );
assert_true( ! mlab_smtp_log_clear(), 'clear reports the failure' );
mlab_smtp_store_purge( time() + 100 );
remove_filter( 'query', $f );
assert_same( $full, mlab_t_counts(), 'clear and retention roll back too' );
assert_true( mlab_smtp_log_clear(), 'clear works once the error is gone' );
assert_same( array( 'messages' => 0, 'attempts' => 0, 'events' => 0, 'recipients' => 0 ), mlab_t_counts(), 'clear leaves no orphans' );

// Lookup-Fehler zählt nicht als «nichts gefunden».
mlab_t_seed_two();
$f = static fn( $sql ) => str_contains( $sql, 'SELECT DISTINCT message_id' ) ? 'SELECT * FROM mlab_smtp_missing_table' : $sql;
add_filter( 'query', $f );
$e = mlab_smtp_privacy_erase( 'user@example.test', 1 );
remove_filter( 'query', $f );
assert_true( $e['items_retained'], 'a failed lookup is reported as retained' );
assert_same( 2, mlab_t_counts()['messages'], 'nothing removed after a failed lookup' );

// Ohne Transaktionsunterstützung: vor der ersten Löschung abbrechen, jeder Datensatz bleibt auffindbar.
mlab_t_seed_two();
$full = mlab_t_counts();
add_filter( 'mlab_smtp_store_transactional', '__return_false' );
$r = mlab_smtp_store_delete_result( mlab_smtp_store_ids_by_email( 'user@example.test' ) );
assert_true( ! $r['ok'] && ! $r['atomic'] && 0 === $r['deleted'], 'unsupported engines fail before deleting anything' );
assert_same( $full, mlab_t_counts(), 'unsupported engines preserve every table and the recipient index' );
remove_filter( 'mlab_smtp_store_transactional', '__return_false' );
assert_true( mlab_smtp_store_delete_result( mlab_smtp_store_ids_by_email( 'user@example.test' ) )['ok'], 'retry after restoring transaction support succeeds' );

// START TRANSACTION darf auf MySQL eine fremde Transaktion nicht implizit committen.
if ( preg_match( '/mysql|maria/i', (string) $wpdb->db_server_info() ) ) {
	mlab_t_seed_two();
	$full = mlab_t_counts();
	$wpdb->query( 'START TRANSACTION' );
	$wpdb->query( 'UPDATE ' . mlab_smtp_table( 'messages' ) . " SET subject = 'caller pending'" );
	$r = mlab_smtp_store_delete_result( mlab_smtp_store_ids_by_email( 'user@example.test' ) );
	assert_true( ! $r['ok'] && 0 === $r['deleted'], 'a caller transaction rejects deletion' );
	assert_same( $full, mlab_t_counts(), 'caller transaction retains every row' );
	$wpdb->query( 'ROLLBACK' );
	assert_same( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'messages' ) . " WHERE subject = 'caller pending'" ), 'caller changes remain rollbackable' );
	assert_true( mlab_smtp_store_delete_result( mlab_smtp_store_ids_by_email( 'user@example.test' ) )['ok'], 'retry outside caller transaction succeeds' );
}
echo "ok\n";
