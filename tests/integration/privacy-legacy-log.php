<?php
// Altes Protokoll (Option mlab_smtp_log): Löschung, Aufbewahrung, «Protokoll leeren» und Migration lassen nichts zurückkehren.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/store-helpers.php';
global $wpdb;

function mlab_t_legacy( int $age_days ): array {
	static $n = 0;
	++$n;
	$mail = 0 === $n % 2 ? 'user@example.test' : 'other@example.test';
	return array(
		'id'      => '',
		'time'    => time() - $age_days * DAY_IN_SECONDS - $n,
		'to'      => 'Max <' . $mail . '>, extra' . $n . '@example.test',
		'subject' => 'Alt ' . $n,
		'ok'      => true,
		'via'     => 'SMTP',
		'error'   => '',
	);
}
function mlab_t_legacy_mails(): array {
	$mails = array();
	foreach ( (array) get_option( MLAB_SMTP_LOG_OPTION, array() ) as $e ) {
		$mails = array_merge( $mails, mlab_smtp_legacy_entry_addresses( $e ) );
	}
	return $mails;
}
function mlab_t_seed( array $entries ): void {
	mlab_t_reset_store();
	delete_option( MLAB_SMTP_MIGRATION_OPTION );
	delete_option( MLAB_SMTP_MIGRATION_LOCK );
	update_option( MLAB_SMTP_LOG_OPTION, $entries, false );
}

// 1. Personenbezogene Löschung: abgeschlossene Migration (Zeilen in der Tabelle und Kopie in der Option).
mlab_t_seed( array( mlab_t_legacy( 1 ), mlab_t_legacy( 1 ), mlab_t_legacy( 1 ), mlab_t_legacy( 1 ) ) );
assert_true( mlab_smtp_migration_run( 100 )['done'], 'migration completed' );
$e = mlab_smtp_privacy_erase( 'USER@example.test', 1 );
assert_true( ! $e['items_retained'] && $e['done'], 'erasure reports success' );
assert_true( ! in_array( 'user@example.test', mlab_t_legacy_mails(), true ), 'legacy copy has no entry of the erased person' );
assert_true( in_array( 'other@example.test', mlab_t_legacy_mails(), true ), 'legacy copy keeps other people (downgrade stays possible)' );
mlab_smtp_migration_run( 100 );
assert_same( array(), mlab_smtp_store_ids_by_email( 'user@example.test' ), 'a repeated migration does not bring the erased person back' );

// 2. Unvollständige Migration: Löschung greift vor dem nächsten Batch.
mlab_t_seed( array( mlab_t_legacy( 1 ), mlab_t_legacy( 1 ), mlab_t_legacy( 1 ), mlab_t_legacy( 1 ) ) );
mlab_smtp_migration_run( 1 );
assert_true( ! mlab_smtp_migration_run( 1 )['done'], 'migration is still open' );
mlab_smtp_privacy_erase( 'user@example.test', 1 );
mlab_smtp_migration_run( 100 );
assert_same( array(), mlab_smtp_store_ids_by_email( 'user@example.test' ), 'open migration does not restore erased data' );
assert_true( ! in_array( 'user@example.test', mlab_t_legacy_mails(), true ), 'legacy copy stays clean' );

// 3. Aufbewahrung: alte Einträge verschwinden aus der Kopie und kommen nicht über die Migration zurück.
mlab_smtp_update( 'log_retention', 7 );
mlab_t_seed( array( mlab_t_legacy( 1 ), mlab_t_legacy( 20 ), mlab_t_legacy( 40 ) ) );
mlab_smtp_log_purge();
assert_same( 1, count( get_option( MLAB_SMTP_LOG_OPTION, array() ) ), 'retention keeps only entries inside the period in the legacy copy' );
assert_same( 1, (int) mlab_smtp_store_list()['total'], 'only the recent entry is migrated' );
mlab_t_seed( array( mlab_t_legacy( 1 ), mlab_t_legacy( 20 ) ) );
mlab_smtp_migration_run( 100 );
assert_same( 1, (int) mlab_smtp_store_list()['total'], 'migration skips entries beyond retention' );

// 4. Protokoll leeren: auch die Kopie, auch bei offener Migration.
mlab_t_seed( array( mlab_t_legacy( 1 ), mlab_t_legacy( 1 ), mlab_t_legacy( 1 ) ) );
mlab_smtp_migration_run( 1 );
assert_true( mlab_smtp_log_clear(), 'clear reports success' );
assert_same( false, get_option( MLAB_SMTP_LOG_OPTION, false ), 'legacy copy removed' );
mlab_smtp_migration_run( 100 );
assert_same( 0, (int) mlab_smtp_store_list()['total'], 'migration after clear imports nothing' );

// 5. Keine Erfolgsmeldung, wenn die Kopie nicht bereinigt werden konnte (Sperre gehalten).
mlab_t_seed( array( mlab_t_legacy( 1 ), mlab_t_legacy( 1 ) ) );
assert_true( mlab_smtp_migration_lock( 120 ), 'lock held by a running migration' );
$e = mlab_smtp_privacy_erase( 'user@example.test', 1 );
assert_true( $e['items_retained'] && array() !== $e['messages'], 'erasure reports retained data and a message' );
assert_true( ! mlab_smtp_log_clear(), 'clear reports failure' );
delete_option( MLAB_SMTP_MIGRATION_LOCK );
assert_true( mlab_smtp_log_clear(), 'clear works again once the lock is free' );
echo "ok\n";
