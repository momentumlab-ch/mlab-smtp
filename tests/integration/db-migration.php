<?php
// Migration der alten Option mlab_smtp_log: Batches, Wiederaufnahme, keine Duplikate, alte Option bleibt unberührt.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/store-helpers.php';
global $wpdb;
mlab_t_reset_store();

$old = array();
for ( $i = 1; $i <= 250; $i++ ) {
	$old[] = array(
		'id'       => 1 === $i % 10 ? '' : wp_generate_uuid4(),
		'time'     => 1700000000 + $i,
		'to'       => "legacy$i@example.test, second$i@example.test",
		'subject'  => "Alt $i",
		'ok'       => 0 !== $i % 7,
		'fallback' => 0 === $i % 11,
		'via'      => 'SMTP',
		'error'    => 0 === $i % 7 ? 'Fehler Bearer abcdefghijklmnopqrstuvwxyz0123456789' : '',
	);
}
update_option( MLAB_SMTP_LOG_OPTION, $old, false );
$before = serialize( get_option( MLAB_SMTP_LOG_OPTION ) );

// Ein Eintrag schlägt zunächst fehl.
$failing = $old[4]['id'];
add_filter( 'mlab_smtp_migration_skip_entry', static fn( $skip, $entry ) => $skip || $entry['id'] === $failing, 10, 2 );
$s = mlab_smtp_migration_run( 100 );
assert_same( 99, $s['migrated'], 'first run takes a batch of 100, one entry fails' );
assert_true( ! $s['done'], 'not done after the first batch' );
assert_same( 99, mlab_smtp_store_list()['total'], '99 rows after one batch' );
$s = mlab_smtp_migration_run( 100 );
$s = mlab_smtp_migration_run( 100 );
assert_same( 249, (int) mlab_smtp_store_list()['total'], 'partial failure: 249 of 250 migrated, the run continues' );
assert_true( ! $s['done'] && $s['failed'] > 0 && '' !== $s['last_error'], 'state records the failed entry and the error' );
assert_same( 'Einträge nicht übernommen: 1', $s['last_error'], 'last error text' );

// Sperre: eine laufende Migration blockiert die zweite.
assert_true( mlab_smtp_migration_lock( 120 ), 'lock taken' );
assert_true( ! mlab_smtp_migration_lock( 120 ), 'second lock is refused (atomic add_option)' );
$locked = mlab_smtp_migration_run( 100 );
assert_same( 249, (int) mlab_smtp_store_list()['total'], 'locked run does nothing' );
update_option( MLAB_SMTP_MIGRATION_LOCK, time() - 5, false );
assert_true( mlab_smtp_migration_lock( 120 ), 'expired lock is replaced' );
delete_option( MLAB_SMTP_MIGRATION_LOCK );

// Notiz erst bei anhaltendem Fehler.
ob_start(); mlab_smtp_migration_notice(); $none = ob_get_clean();
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
mlab_smtp_migration_run( 100 );
mlab_smtp_migration_run( 100 );
mlab_smtp_migration_run( 100 );
ob_start(); mlab_smtp_migration_notice(); $notice = ob_get_clean();
assert_true( str_contains( $notice, 'notice-error' ) && str_contains( $notice, 'alte Versandprotokoll' ), 'persistent error shows a German admin notice' );
assert_same( '', $none, 'no notice before the error persists' );

// Wiederaufnahme nach Behebung, keine Duplikate.
remove_all_filters( 'mlab_smtp_migration_skip_entry' );
$s = mlab_smtp_migration_run( 100 );
assert_true( $s['done'], 'done after the failure is gone' );
assert_same( 250, (int) mlab_smtp_store_list()['total'], 'all 250 entries present' );
assert_same( '', $s['last_error'], 'error cleared' );
mlab_smtp_migration_run( 100 );
update_option( MLAB_SMTP_MIGRATION_OPTION, array_merge( mlab_smtp_migration_state(), array( 'done' => false ) ), true );
$s = mlab_smtp_migration_run( 100 );
assert_same( 250, (int) mlab_smtp_store_list( array( 'per_page' => 1 ) )['total'], 'rerun creates no duplicates' );
assert_same( 250, (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT legacy_key) FROM ' . mlab_smtp_table( 'messages' ) ), 'every row has its own legacy_key' );
assert_same( $before, serialize( get_option( MLAB_SMTP_LOG_OPTION ) ), 'old option is neither changed nor deleted' );

// Inhalt der Übernahme.
$row = mlab_smtp_log_find( $old[1]['id'] );
assert_same( 'legacy2@example.test, second2@example.test', $row['to'], 'recipients migrated' );
assert_same( 1700000002, $row['time'], 'time preserved' );
$failed = mlab_smtp_log_find( $old[6]['id'] );
assert_same( 'failed', $failed['status'], 'failed entry keeps its status' );
assert_true( ! str_contains( $failed['error'], 'abcdefghijklmnopqrstuvwxyz0123456789' ), 'migrated error is redacted' );
assert_same( 1, count( mlab_smtp_store_ids_by_email( 'second2@example.test' ) ), 'migrated recipients are in the HMAC index' );

// Ohne alte Option: sofort fertig.
mlab_t_reset_store();
delete_option( MLAB_SMTP_LOG_OPTION );
assert_true( mlab_smtp_migration_run()['done'], 'nothing to migrate marks the migration done' );
mlab_t_reset_store();
t_finish();
