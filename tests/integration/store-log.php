<?php
// Protokoll-API auf den Tabellen: Eintragsform, find, clear, purge nach Aufbewahrung, Stats.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/store-helpers.php';
mlab_t_reset_store();
delete_option( MLAB_SMTP_LOG_OPTION );

$ok  = mlab_t_msg( array( 'status' => 'accepted', 'via' => 'Resend', 'created_at' => time() - 60, 'recipients' => array( 'a@example.test', 'b@example.test' ) ) );
$bad = mlab_t_msg( array( 'status' => 'failed', 'last_error' => 'Server nicht erreichbar', 'created_at' => time() - 30 ) );
$fb  = mlab_t_msg( array( 'status' => 'accepted', 'fallback' => true, 'via' => 'SMTP', 'created_at' => time() - 20 ) );
$e   = mlab_smtp_log_get();
assert_same( 3, count( $e ), 'three entries' );
foreach ( array( 'id', 'time', 'to', 'subject', 'ok', 'fallback', 'via', 'error', 'status', 'uuid', 'attempts' ) as $key ) {
	assert_true( array_key_exists( $key, $e[0] ), "entry has key $key" );
}
assert_true( $e[0]['fallback'] && $e[0]['ok'], 'newest entry is the fallback delivery' );
assert_same( 'a@example.test, b@example.test', mlab_smtp_log_find( $e[2]['id'] )['to'], 'find by id returns the entry' );
assert_same( null, mlab_smtp_log_find( 'unknown' ), 'find of an unknown id is null' );
assert_same( 2, count( mlab_smtp_log_get( array( 'status' => 'bad' ) ) ), 'bad = failed or fallback' );
assert_same( 1, count( mlab_smtp_log_get( array( 'status' => 'ok' ) ) ), 'ok' );
assert_same( 1, count( mlab_smtp_log_get( array( 'search' => 'erreichbar' ) ) ), 'search in error' );
$stats = mlab_smtp_stats();
assert_same( 3, $stats['sent'] + $stats['failed'], 'stats count the entries' );
assert_same( 1, $stats['failed'], 'stats count the failure' );

// Gleichzeitige Mails: aktive Zeile wird aktualisiert statt doppelt angelegt.
mlab_smtp_log_active_message( $ok );
mlab_smtp_log_active_message( 0 );

// Aufbewahrung.
mlab_t_msg( array( 'status' => 'accepted', 'created_at' => time() - 40 * DAY_IN_SECONDS ) );
$opt = (array) get_option( MLAB_SMTP_OPTION, array() );
update_option( MLAB_SMTP_OPTION, array_merge( $opt, array( 'log_retention' => 30 ) ) );
mlab_smtp_log_purge();
assert_same( 3, mlab_smtp_store_list()['total'], 'purge removes only entries older than the retention' );
mlab_smtp_log_clear();
assert_same( 0, mlab_smtp_store_list()['total'], 'clear empties the log' );
$q = mlab_t_msg( array( 'status' => 'queued' ) );
mlab_smtp_log_clear();
assert_true( null !== mlab_smtp_store_get( $q ), 'clear keeps messages that still wait for sending' );
update_option( MLAB_SMTP_OPTION, $opt );
t_finish();
