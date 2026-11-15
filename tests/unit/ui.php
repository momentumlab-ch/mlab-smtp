<?php
// Standalone test of the Resend module with WordPress stubs. Not part of the plugin.
error_reporting( E_ALL );
define( 'MLAB_SMTP_SCREEN', 'toplevel_page_mlab-smtp' ); define( 'ABSPATH', '/x/' );
define( 'MB_IN_BYTES', 1048576 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MLAB_SMTP_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'MLAB_SMTP_VERSION', '1.3.0' );
define( 'MLAB_SMTP_TIMEOUT', 10 );
define( 'MLAB_SMTP_RESEND_API_KEY', 're_test_1234567890abcdef' );

class WP_Error {
	public $code; public $msg; public $data = array();
	public function __construct( $code = '', $msg = '', $data = '' ) { $this->code = $code; $this->msg = $msg; if ( '' !== $data ) { $this->data = $data; } }
	public function get_error_message() { return $this->msg; }
	public function get_error_code() { return $this->code; }
	public function add_data( $d ) { $this->data = $d; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function __( $s ) { return $s; }
function _n( $a, $b, $n ) { return 1 === $n ? $a : $b; }
function add_action() {}
function add_filter() {}
function apply_filters( $t, $v ) { return $v; }
function wp_json_encode( $d ) { return json_encode( $d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }
function wp_salt() { return 'salt'; }
function wp_generate_uuid4() { return 'uuid-' . bin2hex( random_bytes( 4 ) ); }
function number_format_i18n( $n ) { return (string) $n; }
function sanitize_email( $e ) { return $e; }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; }
function set_transient( $k, $v ) { $GLOBALS['tr'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['tr'][ $k ] ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_request( $url, $args ) {
	$GLOBALS['calls'][] = array( $args['method'], $url, $args );
	$path = substr( $url, strlen( 'https://api.resend.com' ) );
	$key  = $args['method'] . ' ' . $path;
	$mock = $GLOBALS['mock'][ $key ] ?? null;
	if ( is_callable( $mock ) ) { $mock = $mock( $args ); }
	if ( null === $mock ) { return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: api.resend.com' ); }
	return array( 'code' => $mock[0], 'body' => json_encode( $mock[1] ) );
}

require MLAB_SMTP_DIR . 'inc/providers.php';
require MLAB_SMTP_DIR . 'inc/options.php';
require MLAB_SMTP_DIR . 'inc/crypto.php';
require MLAB_SMTP_DIR . 'inc/security.php';
require MLAB_SMTP_DIR . 'inc/http.php';
require MLAB_SMTP_DIR . 'inc/detect.php';
require MLAB_SMTP_DIR . 'inc/mailer.php';
require MLAB_SMTP_DIR . 'inc/resend.php';
require MLAB_SMTP_DIR . 'inc/state.php';
require MLAB_SMTP_DIR . 'inc/health.php';
require MLAB_SMTP_DIR . 'inc/check.php';



function plugin_basename( $f ) { return 'x'; }
define( 'MLAB_SMTP_FILE', '/x/mlab-smtp.php' );
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s ) { return esc_html( $s ); }
function admin_url( $p = '' ) { return 'https://site.test/wp-admin/' . $p; }
function wp_nonce_url( $u, $a ) { return $u . '&_wpnonce=N'; }
function add_query_arg( $a, $u ) { return $u . '?' . http_build_query( $a ); }
function checked( $a, $b, $e = true ) { return (string) $a === (string) $b ? ' checked' : ''; }
function get_current_user_id() { return 1; }
function wp_nonce_field() {}
function get_bloginfo() { return 'Site'; }
function wp_specialchars_decode( $s ) { return $s; }
function sanitize_text_field( $s ) { return $s; }
function wp_unslash( $s ) { return $s; }
function wp_json_encode_stub() {}
require MLAB_SMTP_DIR . 'inc/admin/components.php';
require MLAB_SMTP_DIR . 'inc/admin/page.php';

$GLOBALS['opts'] = array( MLAB_SMTP_OPTION => array( 'provider' => 'resend', 'from_email' => 'x@neu.example.ch', 'setup_done' => 1 ) );
$fail = 0;
function ok( $cond, $label ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }
function out( callable $f ) { ob_start(); $f(); return ob_get_clean(); }
function domains( $rows ) { $GLOBALS['tr'] = array(); $GLOBALS['mock']['GET /domains'] = array( 200, array( 'data' => $rows ) ); }
$GLOBALS['mock'] = array();
$GLOBALS['mock']['GET /domains/d2'] = array( 200, array( 'records' => array(
	array( 'record' => 'SPF', 'name' => 'send', 'type' => 'MX', 'value' => 'feedback-smtp.eu-west-1.amazonses.com', 'status' => 'not_started', 'priority' => 10 ),
	array( 'record' => 'DKIM', 'name' => 'resend._domainkey', 'type' => 'TXT', 'value' => 'p=MIG<script>', 'status' => 'pending' ),
) ) );

domains( array( array( 'id' => 'd2', 'name' => 'neu.example.ch', 'status' => 'pending' ) ) );
$h = out( 'mlab_smtp_render_resend_domain_panel' );
ok( str_contains( $h, 'noch nicht verifiziert' ) && str_contains( $h, 'resend._domainkey' ) && str_contains( $h, 'Priorität 10' ), 'pending panel shows message, records and MX priority' );
ok( str_contains( $h, 'mlab_smtp_resend_recheck' ) && str_contains( $h, 'Nochmals prüfen' ), 'pending panel has recheck link' );
ok( ! str_contains( $h, '<script>' ) && str_contains( $h, '&lt;script&gt;' ), 'record values are escaped' );

domains( array( array( 'id' => 'd1', 'name' => 'andere.ch', 'status' => 'verified' ) ) );
$h = out( 'mlab_smtp_render_resend_domain_panel' );
ok( str_contains( $h, 'nicht angelegt' ) && str_contains( $h, 'andere.ch' ), 'missing panel lists verified domains' );

domains( array( array( 'id' => 'd2', 'name' => 'neu.example.ch', 'status' => 'verified' ) ) );
$h = out( 'mlab_smtp_render_resend_domain_panel' );
ok( str_contains( $h, 'verifiziert. Mails können' ) && ! str_contains( $h, 'Nochmals prüfen' ), 'verified panel is calm, no recheck' );

$GLOBALS['tr'] = array(); $GLOBALS['mock']['GET /domains'] = array( 401, array( 'statusCode' => 401, 'name' => 'restricted_api_key', 'message' => 'restricted' ) );
$h = out( 'mlab_smtp_render_resend_domain_panel' );
ok( str_contains( $h, 'nur senden' ) && str_contains( $h, 'is-info' ), 'sending-key panel is informational' );

$GLOBALS['tr'] = array(); unset( $GLOBALS['mock']['GET /domains'] );
$h = out( 'mlab_smtp_render_resend_domain_panel' );
ok( str_contains( $h, 'nicht abgerufen' ) && str_contains( $h, 'nicht erreichbar' ) && str_contains( $h, 'Could not resolve' ), 'error panel: message + plain-language hint + technical text' );

$h = out( 'mlab_smtp_render_connect_resend' );
ok( str_contains( $h, 'name="resend_api_key"' ) && str_contains( $h, 'type="password"' ) && str_contains( $h, 'data-mlab-check' ), 'connect step: key field (password) and check button' );
ok( ! str_contains( $h, 're_test_1234567890abcdef' ), 'connect step never prints the key' );

ok( isset( mlab_smtp_ui_providers()['resend']['card'] ), 'resend has display data and a card' );
$GLOBALS['opts'][ MLAB_SMTP_OPTION ]['provider'] = 'resend';
domains( array( array( 'id' => 'd2', 'name' => 'neu.example.ch', 'status' => 'pending' ) ) );
$row = mlab_smtp_health_resend();
ok( 'warn' === $row['level'] && 'Domain prüfen' === $row['action_label'], 'health row warns with action while pending' );
ok( false === mlab_smtp_provider_def( 'resend' )['spf'], 'registry: SPF root-domain check skipped for Resend' );
domains( array( array( 'id' => 'd2', 'name' => 'neu.example.ch', 'status' => 'verified' ) ) );
ok( 'ok' === mlab_smtp_health_resend()['level'], 'health row ok when verified' );
ok( in_array( 'resend', mlab_smtp_choices()['provider'], true ) && mlab_smtp_provider_ready( 'resend' ), 'resend is a provider and ready with a key' );
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
