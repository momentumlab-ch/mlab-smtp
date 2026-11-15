<?php
// Minimale Assert-Helfer. Ausgabe: PASS/FAIL je Assertion, Exit-Code 1 bei Fehlern.
error_reporting( E_ALL );

$GLOBALS['mlab_t_fail'] = 0;
$GLOBALS['mlab_t_pass'] = 0;

function t_report( bool $cond, string $label, string $detail = '' ): bool {
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . ( ! $cond && '' !== $detail ? "\n     " . $detail : '' ) . "\n";
	$GLOBALS[ $cond ? 'mlab_t_pass' : 'mlab_t_fail' ]++;
	return $cond;
}

function assert_true( $cond, string $label ): bool {
	return t_report( true === (bool) $cond, $label );
}

function assert_same( $expected, $actual, string $label ): bool {
	return t_report( $expected === $actual, $label, 'erwartet ' . var_export( $expected, true ) . ', erhalten ' . var_export( $actual, true ) );
}

function assert_contains( string $needle, string $haystack, string $label ): bool {
	return t_report( str_contains( $haystack, $needle ), $label, "'$needle' nicht gefunden in: " . substr( $haystack, 0, 200 ) );
}

/** Erwartet, dass $fn eine Exception wirft (optional einer bestimmten Klasse). */
function assert_throws( callable $fn, string $label, string $class = Throwable::class ): bool {
	try {
		$fn();
	} catch ( Throwable $e ) {
		return t_report( $e instanceof $class, $label, 'falsche Exception: ' . get_class( $e ) );
	}
	return t_report( false, $label, 'keine Exception geworfen' );
}

/** Am Ende jeder Testdatei aufrufen. */
function t_finish(): void {
	echo "\n" . ( $GLOBALS['mlab_t_fail'] ? $GLOBALS['mlab_t_fail'] . " FAILED\n" : "ALL PASSED\n" );
	exit( $GLOBALS['mlab_t_fail'] ? 1 : 0 );
}
