<?php
// Router für den PHP-Built-in-Server: Pretty URLs, wp-admin, statische Dateien.
$mlab_root = __DIR__ . '/wordpress';
$mlab_path = rawurldecode( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
if ( str_contains( $mlab_path, '..' ) ) {
	http_response_code( 400 );
	return true;
}
$mlab_file = $mlab_root . $mlab_path;
if ( is_dir( $mlab_file ) ) {
	$mlab_file = rtrim( $mlab_file, '/' ) . '/index.php';
	$mlab_path = rtrim( $mlab_path, '/' ) . '/index.php';
}
if ( is_file( $mlab_file ) ) {
	if ( 'php' !== pathinfo( $mlab_file, PATHINFO_EXTENSION ) ) {
		return false; // Statische Datei: der Server liefert sie aus.
	}
	$_SERVER['SCRIPT_NAME']     = $mlab_path;
	$_SERVER['PHP_SELF']        = $mlab_path;
	$_SERVER['SCRIPT_FILENAME'] = $mlab_file;
	chdir( dirname( $mlab_file ) );
	require $mlab_file;
	return true;
}
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $mlab_root . '/index.php';
chdir( $mlab_root );
require $mlab_root . '/index.php';
