<?php
// Webhooks: unverarbeitete Ereignisse werden zuverlässig, fair und begrenzt nachbearbeitet.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/store-helpers.php';
global $wpdb;

function wr_add( string $event_id, string $type, string $email_id, ?int $message = null, string $provider = 'resend' ): int {
	return (int) mlab_smtp_store_add_event( array(
		'provider'    => $provider,
		'event_id'    => $event_id,
		'type'        => $type,
		'message_id'  => $message,
		'ref_email'   => $email_id,
		'detail'      => wp_json_encode( array( 'email_id' => $email_id, 'mlab_id' => '', 'info' => '' ) ),
	) );
}
function wr_row( int $event ): array {
	global $wpdb;
	return (array) $wpdb->get_row( 'SELECT * FROM ' . mlab_smtp_table( 'events' ) . ' WHERE id = ' . $event, ARRAY_A );
}
function wr_due( int $event ): void {
	global $wpdb;
	$wpdb->update( mlab_smtp_table( 'events' ), array( 'retry_at' => 0 ), array( 'id' => $event ) );
}
function wr_msg( string $status, string $email_id ): int {
	$id = mlab_t_msg( array( 'status' => $status ) );
	mlab_smtp_store_add_attempt( $id, array( 'route' => 'primary', 'provider' => 'resend' ) );
	mlab_smtp_store_set_status( $id, $status, array( 'provider_msg_id' => $email_id ) );
	return $id;
}
function wr_fail_sql( string $prefix ): Closure {
	$f = static fn( $sql ) => str_starts_with( $sql, $prefix ) ? 'UPDATE mlab_smtp_missing_table SET x = 1' : $sql;
	add_filter( 'query', $f );
	return $f;
}
$msg_update = static fn() => 'UPDATE `' . mlab_smtp_table( 'messages' ) . '`';
$ev_update  = static fn() => 'UPDATE `' . mlab_smtp_table( 'events' ) . '` SET processed_at';

// 1. Ereignis vor bekannter Anbieter-ID: bleibt offen, wird gezielt nach der ID verarbeitet.
mlab_t_reset_store();
$e   = wr_add( 'a1', 'email.delivered', 'em_a' );
$res = mlab_smtp_webhook_settle( mlab_smtp_webhook_normalize_row( wr_row( $e ) ) );
assert_same( 'unmatched', $res, 'event before the provider id is unmatched' );
assert_true( null === wr_row( $e )['processed_at'] && (int) wr_row( $e )['retry_at'] > time(), 'it stays open with a later due time' );
$m = wr_msg( 'accepted', 'em_a' );
mlab_smtp_webhook_rematch_message( $m );
assert_same( 'delivered', mlab_smtp_store_get( $m )['status'], 'targeted run applies it' );
assert_true( null !== wr_row( $e )['processed_at'] && (int) wr_row( $e )['message_id'] === $m, 'it is matched and processed' );

// 2. Zuordnung klappt, Statusupdate scheitert: matched, aber offen; der Hintergrundlauf verarbeitet es später.
mlab_t_reset_store();
$m = wr_msg( 'accepted', 'em_b' );
$e = wr_add( 'b1', 'email.delivered', 'em_b' );
$f = wr_fail_sql( $msg_update() );
$r = mlab_smtp_webhook_reprocess();
remove_filter( 'query', $f );
assert_same( 'accepted', mlab_smtp_store_get( $m )['status'], 'status not changed after the failure' );
assert_true( (int) wr_row( $e )['message_id'] === $m && null === wr_row( $e )['processed_at'], 'event is matched but still open' );
assert_same( 0, $r['processed'], 'nothing counted as processed' );
$again = mlab_smtp_webhook_reprocess();
assert_same( 0, $again['checked'], 'a failed event waits for its delay and does not spin' );
wr_due( $e );
$r = mlab_smtp_webhook_reprocess();
assert_same( 'delivered', mlab_smtp_store_get( $m )['status'], 'later background run applies the status' );
assert_true( null !== wr_row( $e )['processed_at'] && 1 === $r['processed'], 'event is processed afterwards' );

// 3. Fehler beim Setzen von processed_at: nicht verarbeitet, wiederholbar, Status bleibt korrekt.
mlab_t_reset_store();
$m = wr_msg( 'accepted', 'em_c' );
$e = wr_add( 'c1', 'email.delivered', 'em_c', $m );
$f = wr_fail_sql( $ev_update() );
$res = mlab_smtp_webhook_settle( mlab_smtp_webhook_normalize_row( wr_row( $e ) ) );
remove_filter( 'query', $f );
assert_same( 'error', $res, 'a failing processed marker is an error' );
assert_true( null === wr_row( $e )['processed_at'], 'event stays unprocessed' );
wr_due( $e );
mlab_smtp_webhook_reprocess();
assert_true( null !== wr_row( $e )['processed_at'], 'marker is set by the retry' );
assert_same( 'delivered', mlab_smtp_store_get( $m )['status'], 'status stays correct' );

// 4. Ereignis während processing: zurückgestellt, nach dem Abschluss verarbeitet.
mlab_t_reset_store();
$m = mlab_t_msg( array( 'status' => 'processing' ) );
$wpdb->update( mlab_smtp_table( 'messages' ), array( 'provider_msg_id' => 'em_d' ), array( 'id' => $m ) );
$e = wr_add( 'd1', 'email.delivered', 'em_d', $m );
assert_same( 'deferred', mlab_smtp_webhook_settle( mlab_smtp_webhook_normalize_row( wr_row( $e ) ) ), 'processing row defers the event' );
assert_true( null === wr_row( $e )['processed_at'], 'deferred event stays open' );
$wpdb->update( mlab_smtp_table( 'messages' ), array( 'status' => 'accepted' ), array( 'id' => $m ) );
mlab_smtp_store_apply_pending_events( $m );
assert_same( 'delivered', mlab_smtp_store_get( $m )['status'], 'applied after completion' );
assert_true( null !== wr_row( $e )['processed_at'], 'processed after completion' );

// 5. Doppelt und «parallel» (zweimal dasselbe Ereignis): idempotent, ein Datensatz, ein Status.
mlab_t_reset_store();
$m  = wr_msg( 'accepted', 'em_e' );
$e  = wr_add( 'e1', 'email.delivered', 'em_e', $m );
$ev = mlab_smtp_webhook_normalize_row( wr_row( $e ) );
assert_same( 'changed', mlab_smtp_webhook_settle( $ev ), 'first run changes the status' );
assert_same( 'unchanged', mlab_smtp_webhook_settle( $ev ), 'second run is a harmless no-op' );
assert_true( ! wr_add( 'e1', 'email.delivered', 'em_e', $m ), 'the same event id cannot be stored twice' );

// 6. Reihenfolge: das spätere Ereignis (delivered) kommt vor dem früheren (sent): Status wird nicht zurückgestuft.
mlab_t_reset_store();
$m = wr_msg( 'accepted', 'em_f' );
$e1 = wr_add( 'f1', 'email.delivered', 'em_f', $m );
$e2 = wr_add( 'f2', 'email.sent', 'em_f', $m );
mlab_smtp_webhook_settle( mlab_smtp_webhook_normalize_row( wr_row( $e1 ) ) );
mlab_smtp_webhook_settle( mlab_smtp_webhook_normalize_row( wr_row( $e2 ) ) );
assert_same( 'delivered', mlab_smtp_store_get( $m )['status'], 'out-of-order events do not downgrade' );
assert_true( null !== wr_row( $e1 )['processed_at'] && null !== wr_row( $e2 )['processed_at'], 'both are processed' );

// 7. Ein Ereignis, das während der Verarbeitung hinzukommt, wird nicht pauschal als verarbeitet markiert.
mlab_t_reset_store();
$m = mlab_t_msg( array( 'status' => 'accepted' ) );
$a = wr_add( 'g1', 'email.sent', 'em_g', $m );
$late_id = 0;
$inject  = static function ( $sql ) use ( &$late_id, $m ) {
	if ( 0 === $late_id && str_starts_with( $sql, 'UPDATE `' . mlab_smtp_table( 'events' ) . '` SET processed_at' ) ) {
		$late_id = wr_add( 'g2', 'email.delivered', 'em_g', $m );
	}
	return $sql;
};
add_filter( 'query', $inject );
mlab_smtp_store_apply_pending_events( $m );
remove_filter( 'query', $inject );
assert_true( $late_id > 0 && null === wr_row( $late_id )['processed_at'], 'an event that arrived meanwhile stays open' );
assert_true( null !== wr_row( $a )['processed_at'], 'the evaluated event is processed' );
mlab_smtp_store_apply_pending_events( $m );
assert_true( null !== wr_row( $late_id )['processed_at'] && 'delivered' === mlab_smtp_store_get( $m )['status'], 'the next run evaluates it' );

// 8. Rückstau: mehr als 200 dauerhaft unzuordenbare ältere Ereignisse, danach zuordenbare.
mlab_t_reset_store();
for ( $i = 0; $i < 260; $i++ ) {
	wr_add( "orphan$i", 'email.delivered', "em_none_$i" );
}
$ms = array();
$es = array();
for ( $i = 0; $i < 5; $i++ ) {
	$ms[ $i ] = wr_msg( 'accepted', "em_ok_$i" );
	$es[ $i ] = wr_add( "ok$i", 'email.delivered', "em_ok_$i" );
	// Ohne die gezielte Zuordnung: als sei die ID erst nach dem Ereignis bekannt geworden.
}
$runs = 0;
while ( $runs < 8 ) {
	++$runs;
	mlab_smtp_webhook_reprocess();
	if ( 'delivered' === mlab_smtp_store_get( $ms[4] )['status'] ) {
		break;
	}
}
assert_true( $runs <= 6, "later matchable events are processed within ceil(265/50) = 6 runs (took $runs)" );
foreach ( $ms as $i => $m ) {
	assert_same( 'delivered', mlab_smtp_store_get( $m )['status'], "matchable event $i processed behind the backlog" );
}
assert_same( 260, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'events' ) . ' WHERE processed_at IS NULL' ), 'orphans stay open (not marked processed)' );
$idle = mlab_smtp_webhook_reprocess();
assert_same( 0, $idle['checked'], 'permanent orphans are not retried before their delay (no endless restart)' );
echo "ok\n";
