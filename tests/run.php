<?php
// Test-Runner: php tests/run.php [unit|integration|<datei>]
// Jede Testdatei läuft als eigener PHP-Prozess; gezählt werden PASS/FAIL-Zeilen.
error_reporting( E_ALL );
$root = __DIR__;
$arg  = $argv[1] ?? 'all';
$files = array();
if ( is_file( $arg ) ) {
	$files[] = realpath( $arg );
} elseif ( is_file( "$root/$arg" ) ) {
	$files[] = realpath( "$root/$arg" );
} else {
	foreach ( array( 'unit', 'integration' ) as $suite ) {
		if ( 'all' === $arg || $suite === $arg ) {
			$g = glob( "$root/$suite/*.php" );
			sort( $g );
			$files = array_merge( $files, $g );
		}
	}
}
if ( ! $files ) {
	fwrite( STDERR, "Keine Tests gefunden für '$arg'.\n" );
	exit( 2 );
}
$tp = 0; $tf = 0; $bad = array();
foreach ( $files as $f ) {
	$name = basename( dirname( $f ) ) . '/' . basename( $f );
	echo "== $name\n";
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $f ) . ' 2>&1', $out, $rc );
	$p = 0; $x = 0;
	foreach ( $out as $line ) {
		if ( str_starts_with( $line, 'PASS ' ) ) { $p++; }
		if ( str_starts_with( $line, 'FAIL ' ) ) { $x++; }
		if ( ! str_starts_with( $line, 'PASS ' ) ) { echo $line . "\n"; }
	}
	if ( 0 !== $rc && 0 === $x ) { $x = 1; echo "FAIL $name beendet mit Exit-Code $rc\n"; }
	$out = array();
	echo "-- $name: $p PASS, $x FAIL\n";
	$tp += $p; $tf += $x;
	if ( $x ) { $bad[] = $name; }
}
echo "\nGesamt: $tp PASS, $tf FAIL in " . count( $files ) . " Dateien\n";
if ( $bad ) { echo 'Fehlgeschlagen: ' . implode( ', ', $bad ) . "\n"; }
exit( $tf ? 1 : 0 );
