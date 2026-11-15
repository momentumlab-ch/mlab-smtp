<?php
// Nur MySQL/MariaDB: echte Nebenläufigkeit. Mehrere PHP-Prozesse warten an einer Datei-Schranke und starten gleichzeitig.
// Auf SQLite wird übersprungen (SQLite serialisiert Schreibzugriffe, der Test wäre dort ohne Aussage).
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/store-helpers.php';
global $wpdb;

$worker_role = ( isset( $argv[1] ) && 'worker' === $argv[1] ) ? $argv : null;

if ( $worker_role ) {
	// Aufruf: worker <aufgabe> <schrankenordner> <nr> <parameter>. Meldet bereit, wartet auf "go", arbeitet, schreibt Ergebnis.
	list( , , $task, $dir, $nr, $param ) = array_pad( $worker_role, 6, null );
	touch( "$dir/ready-$nr" );
	$deadline = microtime( true ) + 25;
	while ( ! is_file( "$dir/go" ) && microtime( true ) < $deadline ) {
		usleep( 200 );
	}
	$out = array();
	if ( 'claim' === $task ) {
		foreach ( mlab_smtp_store_claim_batch( (int) $param, 300, 1800000000 ) as $row ) {
			$out[] = (int) $row['id'];
		}
	} elseif ( 'insert' === $task ) {
		for ( $i = 0; $i < (int) $param; $i++ ) {
			$id = mlab_smtp_store_create( array( 'mode' => 'queued', 'recipients' => array( "w$nr-$i@example.test" ), 'subject' => "Worker $nr / $i", 'created_at' => 1800000000 + $i ) );
			if ( $id ) {
				$out[] = (int) $id;
			}
		}
	}
	file_put_contents( "$dir/result-$nr.json", wp_json_encode( $out ) );
	exit( 0 );
}

if ( ! preg_match( '/mysql|maria/i', (string) $wpdb->db_server_info() . ' ' . ( $wpdb->use_mysqli ? 'mysql' : '' ) ) || 'WP_SQLite_DB' === get_class( $wpdb ) ) {
	echo "SKIP mysql-claim: Datenbank ist kein MySQL/MariaDB (MLAB_TEST_DB=mysql setzen, siehe tests/README.md)\n";
	exit( 0 );
}
echo 'Datenbank: ' . $wpdb->get_var( 'SELECT VERSION()' ) . "\n";

/**
 * Startet n Worker, wartet bis alle bereit sind, gibt dann gleichzeitig frei und sammelt die Ergebnisse.
 *
 * @param string $task  claim oder insert.
 * @param int    $n     Anzahl Prozesse.
 * @param int    $param Batchgröße bzw. Anzahl Inserts je Prozess.
 * @return array<int,array> Ergebnisliste je Worker (null bei Fehler).
 */
function mlab_t_run_workers( string $task, int $n, int $param ): array {
	$dir = sys_get_temp_dir() . '/mlab-claim-' . bin2hex( random_bytes( 4 ) );
	mkdir( $dir, 0700 );
	$procs = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$cmd = array( PHP_BINARY, '-d', 'memory_limit=512M', __FILE__, 'worker', $task, $dir, (string) $i, (string) $param );
		$pipes = array();
		$procs[ $i ] = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, array_merge( getenv(), array( 'MLAB_TEST_DB' => 'mysql' ) ) );
		$procs[ $i ] = array( $procs[ $i ], $pipes );
	}
	// Ends early when a worker has already exited without reporting ready (for example missing credentials):
	// waiting the full time for a process that is gone would only turn an error into a long hang.
	$deadline = microtime( true ) + 20;
	while ( count( glob( "$dir/ready-*" ) ) < $n && microtime( true ) < $deadline ) {
		foreach ( $procs as $i => list( $proc ) ) {
			if ( ! proc_get_status( $proc )['running'] && ! is_file( "$dir/ready-$i" ) ) {
				break 2;
			}
		}
		usleep( 20000 );
	}
	touch( "$dir/go" );
	$results = array();
	foreach ( $procs as $i => list( $proc, $pipes ) ) {
		$log = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		proc_close( $proc );
		$f = "$dir/result-$i.json";
		$results[ $i ] = is_file( $f ) ? json_decode( (string) file_get_contents( $f ), true ) : null;
		if ( null === $results[ $i ] ) {
			echo "Worker $i ohne Ergebnis: " . substr( $log, 0, 500 ) . "\n";
		}
		@unlink( $f );
	}
	array_map( 'unlink', glob( "$dir/*" ) );
	rmdir( $dir );
	return $results;
}

$now = 1800000000;

// 1. Zwei Prozesse, eine Zeile: genau einer gewinnt, auch über viele Wiederholungen.
$wins = array( 0, 0 );
$bad  = 0;
for ( $round = 0; $round < 8; $round++ ) {
	mlab_t_reset_store();
	$id  = mlab_t_msg( array( 'next_attempt_at' => $now - 10 ) );
	$res = mlab_t_run_workers( 'claim', 2, 5 );
	if ( in_array( null, $res, true ) ) {
		++$bad;
		continue;
	}
	$total = count( $res[0] ) + count( $res[1] );
	if ( 1 !== $total ) {
		++$bad;
	}
	$wins[0] += count( $res[0] );
	$wins[1] += count( $res[1] );
}
assert_same( 0, $bad, 'zwei Prozesse, eine Zeile: in jeder von 8 Runden gewinnt genau einer' );
assert_same( 8, $wins[0] + $wins[1], 'insgesamt 8 Gewinne bei 8 Runden' );
$row = mlab_smtp_store_get( $id );
assert_same( 'processing', $row['status'], 'die Zeile ist processing' );
assert_true( strlen( (string) $row['lock_token'] ) >= 16, 'die Zeile hat genau ein Lock-Token' );

// 2. Zwei Prozesse, viele Zeilen: jede Zeile genau einmal vergeben, keine doppelt.
mlab_t_reset_store();
$ids = array();
for ( $i = 0; $i < 40; $i++ ) {
	$ids[] = mlab_t_msg( array( 'next_attempt_at' => $now - 10 ) );
}
$res = mlab_t_run_workers( 'claim', 3, 40 );
assert_true( ! in_array( null, $res, true ), 'alle Claim-Worker lieferten ein Ergebnis' );
$all = array_merge( ...array_map( 'array_values', array_map( static fn( $r ) => (array) $r, $res ) ) );
sort( $all );
$expected = $ids;
sort( $expected );
assert_same( $expected, $all, '40 Zeilen, 3 Prozesse: jede Zeile genau einmal vergeben, keine doppelt, keine verloren' );
assert_same( 40, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mlab_smtp_messages WHERE status = 'processing' AND lock_token IS NOT NULL" ), 'alle 40 Zeilen sind processing' );

// 3. Viele gleichzeitige Inserts verlieren keine Zeile.
mlab_t_reset_store();
$res = mlab_t_run_workers( 'insert', 6, 25 );
assert_true( ! in_array( null, $res, true ), 'alle Insert-Worker lieferten ein Ergebnis' );
$returned = array_merge( ...array_map( static fn( $r ) => (array) $r, $res ) );
assert_same( 150, count( $returned ), 'alle 150 Inserts meldeten eine Id' );
assert_same( 150, count( array_unique( $returned ) ), 'alle Ids sind verschieden' );
assert_same( 150, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mlab_smtp_messages" ), 'in der Tabelle stehen 150 Zeilen' );
assert_same( 150, (int) $wpdb->get_var( "SELECT COUNT(DISTINCT uuid) FROM {$wpdb->prefix}mlab_smtp_messages" ), '150 verschiedene UUIDs' );
assert_same( 150, (int) $wpdb->get_var( "SELECT COUNT(DISTINCT message_id) FROM {$wpdb->prefix}mlab_smtp_recipients" ), 'jede Nachricht hat ihren Empfängerindex' );

// 4. Semantik von rows_affected: MySQL meldet geänderte, nicht gefundene Zeilen.
mlab_t_reset_store();
$id = mlab_t_msg( array( 'status' => 'accepted', 'created_at' => $now ) );
$wpdb->query( "UPDATE {$wpdb->prefix}mlab_smtp_messages SET updated_at = {$now} WHERE id = {$id}" );
$same = mlab_smtp_store_set_status( $id, 'accepted', array(), $now );
echo 'INFO set_status mit identischen Werten und gleichem updated_at liefert ' . var_export( $same, true ) . " (MySQL zählt geänderte Zeilen)\n";
assert_true( true === mlab_smtp_store_set_status( $id, 'delivered', array(), $now + 1 ), 'echte Änderung zählt als Gewinn' );

mlab_t_reset_store();
t_finish();
