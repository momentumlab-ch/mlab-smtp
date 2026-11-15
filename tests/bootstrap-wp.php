<?php
// CLI-Bootstrap: lädt das echte WordPress aus tests/env/wordpress (SQLite) mit aktivem Plugin.
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
$mlab_env = __DIR__ . '/env/wordpress';
if ( ! is_file( $mlab_env . '/wp-load.php' ) || ! is_file( $mlab_env . '/wp-config.php' ) ) {
	fwrite( STDERR, "WordPress-Testumgebung fehlt. Zuerst: bash tests/env/setup.sh\n" );
	exit( 2 );
}
$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI']    = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REMOTE_ADDR']    = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

require_once $mlab_env . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php'; // dbDelta
require_once __DIR__ . '/lib/TestCase.php';
require_once __DIR__ . '/lib/http-mock.php';

if ( ! defined( 'MLAB_SMTP_VERSION' ) ) {
	fwrite( STDERR, "Plugin mlab-smtp ist nicht aktiv. bash tests/env/setup.sh erneut ausführen.\n" );
	exit( 2 );
}

// Die Tests prüfen die deutschen Quelltexte. Die Testumgebung läuft mit der Standardsprache Englisch, und das Plugin
// würde dann die englische Übersetzung laden. Darum läuft jeder Test auf Deutsch, ausser er wechselt selbst die Sprache.
add_filter( 'locale', static fn() => 'de_DE', 99 );
unload_textdomain( 'mlab-smtp' );
mlab_smtp_load_textdomain();
