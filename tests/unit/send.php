<?php
// End-to-end through Mlab_Smtp_Mailer::postSend for the existing API providers (m365, google) and resend.
error_reporting( E_ALL );
define( 'MLAB_SMTP_SCREEN', 'toplevel_page_mlab-smtp' ); define( 'ABSPATH', '/x/' ); define( 'WPINC', 'inc' ); define( 'MB_IN_BYTES', 1048576 ); define( 'MINUTE_IN_SECONDS', 60 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'DAY_IN_SECONDS', 86400 );
define( 'MLAB_SMTP_DIR', dirname( __DIR__, 2 ) . '/' ); define( 'MLAB_SMTP_VERSION', '1.3.0' ); define( 'MLAB_SMTP_TIMEOUT', 10 );
class WP_Error { public $c; public $m; public $d = array(); function __construct( $c = '', $m = '', $d = '' ) { $this->c = $c; $this->m = $m; if ( '' !== $d ) { $this->d = $d; } } function get_error_message() { return $this->m; } function get_error_code() { return $this->c; } function add_data( $d ) { $this->d = $d; } function get_error_data() { return $this->d; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; } function __( $s ) { return $s; } function _n( $a, $b, $n ) { return $a; }
function add_action() {} function add_filter() {} function apply_filters( $t, $v ) { return $v; } function do_action() {}
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; } function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; } function set_transient( $k, $v ) { $GLOBALS['tr'][ $k ] = $v; } function delete_transient( $k ) { unset( $GLOBALS['tr'][ $k ] ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); } function wp_salt() { return 's'; } function wp_json_encode( $d ) { return json_encode( $d ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); } function number_format_i18n( $n ) { return (string) $n; } function wp_generate_uuid4() { return 'u'; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; } function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_post( $url, $args ) { $GLOBALS['calls'][] = array( $url, $args ); if ( str_contains( $url, 'oauth' ) || str_contains( $url, 'token' ) ) { return array( 'code' => 200, 'body' => '{"access_token":"T","expires_in":3600}' ); } return array( 'code' => $GLOBALS['code'] ?? 202, 'body' => $GLOBALS['body'] ?? '' ); }
function wp_remote_request( $url, $args ) { $GLOBALS['calls'][] = array( $url, $args ); return array( 'code' => $GLOBALS['rcode'] ?? 200, 'body' => $GLOBALS['rbody'] ?? '{"id":"1"}' ); }
foreach ( array( 'providers', 'options', 'crypto', 'security', 'http', 'm365', 'google', 'resend', 'state', 'mailer', 'engine', 'engine-send', 'check' ) as $m ) { require MLAB_SMTP_DIR . "inc/$m.php"; }
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require MLAB_SMTP_DIR . 'inc/class-mlab-smtp-mailer.php';
class_alias( \PHPMailer\PHPMailer\Exception::class, 'X' );
$fail = 0; function ok( $c, $l ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $l . "\n"; if ( ! $c ) { $fail++; } }
function send( $provider, $extra = array() ) {
	$GLOBALS['calls'] = array(); $GLOBALS['tr'] = array();
	$GLOBALS['opts'] = array( MLAB_SMTP_OPTION => array_merge( array( 'provider' => $provider, 'setup_done' => 1, 'from_email' => 'a@example.ch', 'm365_tenant' => 't', 'm365_client_id' => 'c' ), $extra ) );
	foreach ( array( 'm365_client_secret', 'google_client_id', 'google_client_secret', 'google_refresh_token', 'resend_api_key' ) as $k ) { $GLOBALS['opts'][ MLAB_SMTP_OPTION ][ $k ] = mlab_smtp_encrypt( 'x' . $k . '1234567890' ); }
	$m = new Mlab_Smtp_Mailer( true ); $m->Mailer = 'sendmail';
	$m->setFrom( 'a@example.ch', 'A' ); $m->addAddress( 'b@example.com' ); $m->Subject = 's'; $m->Body = 'b'; $m->preSend();
	try { return array( $m->postSend(), null ); } catch ( \PHPMailer\PHPMailer\Exception $e ) { return array( false, $e->getMessage() ); }
}
[ $r, $e ] = send( 'm365' );
ok( true === $r && str_contains( end( $GLOBALS['calls'] )[0], 'graph.microsoft.com/v1.0/users/a%40example.ch/sendMail' ), 'm365 sends through Graph sendMail from the sender' );
[ $r, $e ] = send( 'google' );
ok( true === $r && str_contains( end( $GLOBALS['calls'] )[0], 'gmail.googleapis.com/upload/gmail/v1/users/me/messages/send' ), 'google sends through the Gmail upload endpoint' );
[ $r, $e ] = send( 'resend' );
ok( true === $r && str_contains( end( $GLOBALS['calls'] )[0], 'api.resend.com/emails' ), 'resend sends through POST /emails' );
foreach ( array( 'm365', 'google', 'resend' ) as $p ) {
	[ $r, $e ] = send( $p, array( 'from_email' => '' ) );
	ok( false === $r && str_contains( (string) $e, 'keine Absender-Adresse' ), "$p without sender address fails before any request" );
}
$GLOBALS['rcode'] = 500; $GLOBALS['rbody'] = '{"name":"application_error","message":"boom"}';
[ $r, $e ] = send( 'resend' );
ok( false === $r && str_contains( (string) $e, 'application_error' ), 'resend API error surfaces as exception text' );
unset( $GLOBALS['rcode'], $GLOBALS['rbody'] );
// ---- Fallback through Resend: the main route fails, Resend (own key, stored sender) delivers.
function fb_send( $primary, $extra = array() ) {
	$GLOBALS['calls'] = array(); $GLOBALS['tr'] = array();
	$GLOBALS['opts'] = array( MLAB_SMTP_OPTION => array_merge( array( 'provider' => $primary, 'setup_done' => 1, 'from_email' => 'info@example.ch', 'from_name' => 'Firma', 'fallback_enabled' => 1, 'fb_provider' => 'resend', 'm365_tenant' => 't', 'm365_client_id' => 'c' ), $extra ) );
	foreach ( array( 'm365_client_secret', 'resend_api_key', 'fb_resend_api_key' ) as $k ) { if ( ! array_key_exists( $k, $extra ) ) { $GLOBALS['opts'][ MLAB_SMTP_OPTION ][ $k ] = mlab_smtp_encrypt( 'secret-' . $k . '-1234567890' ); } }
	$m = new Mlab_Smtp_Mailer( true ); $m->Mailer = 'sendmail';
	$m->setFrom( 'wordpress@fremd.test', 'WordPress' ); $m->addAddress( 'b@example.com' ); $m->Subject = 's'; $m->Body = 'b'; $m->preSend();
	try { $r = array( $m->postSend(), null ); } catch ( \PHPMailer\PHPMailer\Exception $e ) { $r = array( false, $e->getMessage() ); }
	$r[] = array_values( array_filter( $GLOBALS['calls'], fn( $c ) => str_contains( $c[0], 'api.resend.com' ) ) );
	return $r;
}
$GLOBALS['code'] = 500; // Graph sendMail answers 500 (the token endpoint still works)
[ $r, $e, $rc ] = fb_send( 'm365' );
$sent = $rc ? json_decode( $rc[0][1]['body'], true ) : array();
ok( true === $r && 1 === count( $rc ), 'm365 fails, Resend fallback delivers' );
ok( $rc && 'Bearer secret-fb_resend_api_key-1234567890' === $rc[0][1]['headers']['Authorization'], 'fallback uses its own key, not the main Resend key' );
ok( 'Firma <info@example.ch>' === ( $sent['from'] ?? '' ), 'fallback sends with the stored sender, not the one of the message' );
$route = mlab_smtp_route_result();
ok( 'resend' === $route['via'] && true === $route['fallback'] && str_contains( $route['primary_error'], '500' ), 'route result: via resend, fallback flag, primary error kept' );
$GLOBALS['rcode'] = 403; $GLOBALS['rbody'] = '{"name":"validation_error","message":"The `example.ch` domain is not verified."}';
[ $r, $e ] = fb_send( 'm365' );
ok( false === $r && str_contains( $e, 'Hauptweg:' ) && str_contains( $e, 'Ersatzweg:' ) && str_contains( $e, 'not verified' ), 'both routes fail: error names main route and fallback' );
unset( $GLOBALS['rcode'], $GLOBALS['rbody'] );
[ $r, $e, $rc ] = fb_send( 'm365', array( 'fb_resend_api_key' => '' ) );
ok( false === $r && str_contains( $e, '500' ) && ! str_contains( $e, 'Ersatzweg' ) && 0 === count( $rc ), 'fallback without key: plain main route error, no request to Resend' );
unset( $GLOBALS['code'] );
[ $r, $e, $rc ] = fb_send( 'm365' );
ok( true === $r && 0 === count( $rc ), 'main route ok: fallback is not used' );
[ $r, $e, $rc ] = fb_send( 'resend', array( 'fb_provider' => 'smtp', 'fb_smtp_host' => '' ) );
ok( true === $r, 'resend as main route is unaffected' );
// check_connection of the fallback
$GLOBALS['opts'] = array( MLAB_SMTP_OPTION => array( 'provider' => 'm365', 'fb_provider' => 'resend', 'fb_resend_api_key' => mlab_smtp_encrypt( 're_fallbackkey12345' ) ) );
$GLOBALS['calls'] = array(); $GLOBALS['tr'] = array();
ok( true === mlab_smtp_check_connection( 'fallback' ) && 'Bearer re_fallbackkey12345' === $GLOBALS['calls'][0][1]['headers']['Authorization'], 'fallback connection check asks Resend with the fallback key' );
$GLOBALS['opts'][ MLAB_SMTP_OPTION ]['fb_resend_api_key'] = '';
ok( is_wp_error( mlab_smtp_check_connection( 'fallback' ) ), 'fallback check without key: clear error' );
echo $fail ? "\n$fail FAILED\n" : "";
// token refresh on 401 for token providers goes through the registry
$n = 0; 
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n"; exit( $fail ? 1 : 0 );
