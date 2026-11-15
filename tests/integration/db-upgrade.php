<?php
// Schema: Erstinstallation und Upgrade vom vorherigen Stand; die Version wird erst nach vollständiger Prüfung gesetzt.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/store-helpers.php';
global $wpdb;

const DBU_NEW_COLUMNS = array( 'attempts' => array( 'conn_hash' ), 'events' => array( 'processed_at', 'retry_at', 'tries', 'ref_email', 'ref_uuid' ) );
const DBU_NEW_KEYS    = array( 'events' => array( 'due', 'ref_email', 'ref_uuid' ) );

// Vorheriger Stand: dieselben Tabellen ohne die neuen Spalten und Schlüssel.
function dbu_old_schema(): void {
	global $wpdb;
	mlab_smtp_db_drop();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	foreach ( mlab_smtp_db_schema( $wpdb->get_charset_collate() ) as $sql ) {
		$lines = array();
		foreach ( explode( "\n", $sql ) as $line ) {
			$drop = false;
			foreach ( array_merge( ...array_values( DBU_NEW_COLUMNS ) ) as $col ) {
				$drop = $drop || preg_match( '/^\s+' . $col . '\s/', $line ) || preg_match( '/^\s+(UNIQUE )?KEY ' . $col . ' /', $line );
			}
			$drop = $drop || preg_match( '/^\s+KEY due /', $line );
			if ( ! $drop ) {
				$lines[] = $line;
			}
		}
		$sql = preg_replace( '/,(\s*\n\))/', '$1', implode( "\n", $lines ) );
		dbDelta( $sql );
	}
	update_option( 'mlab_smtp_db_version', '1.5.0.2', false );
	delete_option( MLAB_SMTP_DB_ERROR_OPTION );
}
function dbu_fail( string $needle ): Closure {
	$f = static fn( $sql ) => ( str_starts_with( ltrim( $sql ), 'ALTER TABLE' ) && str_contains( $sql, $needle ) ) ? 'ALTER TABLE mlab_smtp_missing_table ADD COLUMN x INT' : $sql;
	add_filter( 'query', $f );
	return $f;
}

// 1. Erstinstallation.
mlab_smtp_db_drop();
delete_option( 'mlab_smtp_db_version' );
delete_option( MLAB_SMTP_DB_ERROR_OPTION );
assert_true( mlab_smtp_db_install(), 'fresh install succeeds' );
assert_same( array(), mlab_smtp_db_missing(), 'fresh install has every column and key' );
assert_same( MLAB_SMTP_SCHEMA_VERSION, get_option( 'mlab_smtp_db_version' ), 'version set after the fresh install' );
assert_same( false, get_option( MLAB_SMTP_DB_ERROR_OPTION, false ), 'no error state after success' );

// 2. Upgrade vom vorherigen Stand: Daten bleiben, neue Spalten und Schlüssel sind da.
dbu_old_schema();
assert_true( count( mlab_smtp_db_missing() ) >= 9, 'the old shape is detected as incomplete' );
$wpdb->insert( mlab_smtp_table( 'messages' ), array( 'uuid' => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa', 'status' => 'accepted', 'mode' => 'queued', 'created_at' => 1, 'updated_at' => 1 ) );
$mid = (int) $wpdb->insert_id;
$wpdb->insert( mlab_smtp_table( 'events' ), array( 'provider' => 'resend', 'event_id' => 'old1', 'type' => 'email.sent', 'message_id' => $mid, 'occurred_at' => 1, 'received_at' => 1, 'detail' => '{}' ) );
mlab_smtp_db_maybe_upgrade();
assert_same( array(), mlab_smtp_db_missing(), 'upgrade adds all new columns and keys' );
assert_same( MLAB_SMTP_SCHEMA_VERSION, get_option( 'mlab_smtp_db_version' ), 'version updated after a complete upgrade' );
assert_same( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'messages' ) ), 'existing message kept' );
assert_same( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'events' ) . " WHERE event_id = 'old1' AND processed_at IS NULL AND tries = 0" ), 'existing event kept, open for the background run' );

// 3. Fehlschlag bei jeder neuen Spalte und jedem neuen Schlüssel.
$targets = array();
foreach ( DBU_NEW_COLUMNS as $table => $cols ) {
	foreach ( $cols as $col ) {
		$targets[] = array( $table, $col, "ADD COLUMN $col " );
	}
}
foreach ( DBU_NEW_KEYS as $table => $keys ) {
	foreach ( $keys as $key ) {
		$targets[] = array( $table, $key, "ADD KEY `$key` " );
	}
}
foreach ( $targets as list( $table, $name, $needle ) ) {
	dbu_old_schema();
	$wpdb->insert( mlab_smtp_table( 'messages' ), array( 'uuid' => 'bbbbbbbb-1111-4111-8111-bbbbbbbbbbbb', 'status' => 'accepted', 'mode' => 'queued', 'created_at' => 1, 'updated_at' => 1 ) );
	$f    = dbu_fail( $needle );
	$prev = $wpdb->suppress_errors( true );
	$ok   = mlab_smtp_db_install();
	$wpdb->suppress_errors( $prev );
	remove_filter( 'query', $f );
	$missing = mlab_smtp_db_missing();
	assert_true( ! $ok, "$table.$name: install reports the failure" );
	assert_same( '1.5.0.2', get_option( 'mlab_smtp_db_version' ), "$table.$name: version is not advanced" );
	assert_true( ! mlab_smtp_db_ready(), "$table.$name: the schema is not ready" );
	assert_true( in_array( true, array_map( static fn( $m ) => str_contains( $m, $name ), $missing ), true ) && count( $missing ) <= 3, "$table.$name: the failed item (and at most the keys that depend on it) is missing; the rest was added (partial upgrade)" );
	$state = get_option( MLAB_SMTP_DB_ERROR_OPTION );
	assert_true( is_array( $state ) && str_contains( implode( ' ', $state['missing'] ), $name ), "$table.$name: the error state names it" );
	assert_same( false, mlab_t_msg(), "$table.$name: no queue acceptance while the schema is not ready" );
	// Wiederholung: durch den Zeitschutz nicht bei jeder Anfrage, aber nach Ablauf und bei ausdrücklichem Install.
	mlab_smtp_db_maybe_upgrade();
	assert_same( '1.5.0.2', get_option( 'mlab_smtp_db_version' ), "$table.$name: automatic retry is throttled" );
	update_option( MLAB_SMTP_DB_ERROR_OPTION, array_merge( $state, array( 'time' => time() - MLAB_SMTP_DB_RETRY_SECONDS - 1 ) ), false );
	mlab_smtp_db_maybe_upgrade();
	assert_same( array(), mlab_smtp_db_missing(), "$table.$name: the retry completes the upgrade" );
	assert_same( MLAB_SMTP_SCHEMA_VERSION, get_option( 'mlab_smtp_db_version' ), "$table.$name: version set after the retry" );
	assert_same( false, get_option( MLAB_SMTP_DB_ERROR_OPTION, false ), "$table.$name: error state cleared" );
	assert_same( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'messages' ) ), "$table.$name: existing data survived" );
}
echo "ok\n";
