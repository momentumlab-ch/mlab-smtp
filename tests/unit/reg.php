<?php
// Regression test of the provider registry: behaviour of the existing providers must not change.
error_reporting( E_ALL );
define( 'MLAB_SMTP_SCREEN', 'toplevel_page_mlab-smtp' ); define( 'ABSPATH', '/x/' ); define( 'MB_IN_BYTES', 1048576 ); define( 'MINUTE_IN_SECONDS', 60 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'DAY_IN_SECONDS', 86400 );
define( 'MLAB_SMTP_DIR', dirname( __DIR__, 2 ) . '/' ); define( 'MLAB_SMTP_VERSION', '1.3.0' ); define( 'MLAB_SMTP_TIMEOUT', 10 ); define( 'MLAB_SMTP_FILE', '/x/m.php' );
class WP_Error { public $c; public $m; public $d = array(); function __construct( $c = '', $m = '', $d = '' ) { $this->c = $c; $this->m = $m; if ( '' !== $d ) { $this->d = $d; } } function get_error_message() { return $this->m; } function add_data( $d ) { $this->d = $d; } function get_error_data() { return $this->d; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function __( $s ) { return $s; } function _n( $a, $b, $n ) { return 1 === $n ? $a : $b; }
function add_action() {} function add_filter() {} function apply_filters( $t, $v ) { return $v; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; } function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; } function delete_option( $k ) {}
function get_transient( $k ) { return false; } function set_transient() {} function delete_transient() {}
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); } function wp_salt() { return 's'; }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); } function sanitize_email( $e ) { return $e; }
function esc_html( $s ) { return htmlspecialchars( (string) $s ); } function esc_attr( $s ) { return esc_html( $s ); } function esc_url( $s ) { return esc_html( $s ); } function esc_html__( $s ) { return esc_html( $s ); }
function admin_url( $p = '' ) { return 'https://s/wp-admin/' . $p; } function add_query_arg( $a, $u ) { return $u . '?' . http_build_query( $a ); } function plugin_basename() { return 'x'; }
function get_current_user_id() { return 1; } function get_bloginfo() { return 'Site'; } function wp_specialchars_decode( $s ) { return $s; } function sanitize_text_field( $s ) { return $s; } function wp_unslash( $s ) { return $s; }
function checked() { return ''; } function wp_timezone() { return new DateTimeZone( 'UTC' ); } function wp_date( $f, $t = null ) { return gmdate( $f, $t ?? time() ); } function wp_nonce_field() {} function wp_nonce_url( $u ) { return $u; }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
foreach ( array( 'providers', 'options', 'crypto', 'security', 'http', 'm365', 'google', 'resend', 'state', 'mailer', 'health', 'detect', 'check', 'readiness', 'notify' ) as $m ) { require MLAB_SMTP_DIR . "inc/$m.php"; }
require MLAB_SMTP_DIR . 'inc/log.php';
require MLAB_SMTP_DIR . 'inc/admin/page.php';
$fail = 0; function ok( $c, $l ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $l . "\n"; if ( ! $c ) { $fail++; } }
function same( $a, $b ) { sort( $a ); sort( $b ); return $a === $b; }

// Values of version 1.2.0 + Resend, written down independently of the registry.
ok( same( array( 'smtp', 'm365', 'google', 'resend' ), mlab_smtp_choices()['provider'] ), 'provider choices' );
ok( same( array( 'smtp_pass', 'fb_smtp_pass', 'm365_client_secret', 'google_client_secret', 'google_refresh_token', 'resend_api_key', 'fb_resend_api_key', 'resend_webhook_secret', 'fb_resend_webhook_secret' ), mlab_smtp_secret_keys() ), 'secret keys unchanged (+resend, +fallback key, +webhook secrets)' );
ok( same( array( 'm365_tenant', 'm365_client_id', 'm365_client_secret', 'google_client_id', 'google_client_secret', 'google_refresh_token', 'resend_api_key', 'fb_resend_api_key' ), mlab_smtp_credential_keys() ), 'credential keys unchanged (+resend, +fallback key)' );
ok( array( 'smtp_host' ) === mlab_smtp_required_keys( 'smtp' ) && array( 'm365_tenant', 'm365_client_id', 'm365_client_secret' ) === mlab_smtp_required_keys( 'm365' ) && array( 'google_client_id', 'google_client_secret', 'google_refresh_token' ) === mlab_smtp_required_keys( 'google' ) && array( 'resend_api_key' ) === mlab_smtp_required_keys( 'resend' ) && array() === mlab_smtp_required_keys( 'nope' ), 'required keys per provider' );
$g = mlab_smtp_key_groups();
ok( array( 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_auth', 'smtp_user', 'smtp_pass' ) === $g['smtp'] && array( 'm365_tenant', 'm365_client_id', 'm365_client_secret', 'm365_secret_expires', 'from_email' ) === $g['m365'] && array( 'google_client_id', 'google_client_secret' ) === $g['google'] && array( 'resend_api_key' ) === $g['resend'], 'save groups per provider' );
ok( isset( $g['sender'], $g['fallback'], $g['settings'] ) && in_array( 'log_retention', $g['settings'], true ), 'sender / fallback / settings groups kept' );
foreach ( array( 'm365' => true, 'google' => true, 'resend' => true, 'smtp' => false, '' => false ) as $p => $api ) {
	$GLOBALS['opts'] = array( MLAB_SMTP_OPTION => array( 'provider' => $p ) );
	ok( mlab_smtp_is_api_provider() === $api, "is_api_provider($p) = " . var_export( $api, true ) );
}
// Every provider needs a complete definition, so a missing field fails here and not in production.
foreach ( mlab_smtp_provider_defs() as $id => $def ) {
	ok( ! array_diff( array( 'api', 'required', 'group', 'secrets', 'credentials', 'token', 'check', 'send', 'spf', 'health', 'sender_block', 'fb_required', 'fb_check', 'fb_send' ), array_keys( $def ) ), "definition of $id is complete" );
	foreach ( array( 'check', 'send', 'token', 'sender_block', 'fb_check', 'fb_send' ) as $f ) { ok( null === $def[ $f ] || is_callable( $def[ $f ] ), "$id.$f is callable or null" ); }
	foreach ( $def['health'] as $f ) { ok( is_callable( $f ), "$id health function exists" ); }
	ok( ( $def['api'] ? is_callable( $def['send'] ) : null === $def['send'] ), "$id: api providers have a sender, smtp has none" );
	ok( ! array_diff( $def['secrets'], mlab_smtp_secret_keys() ), "$id secrets are encrypted" );
}
$ui = mlab_smtp_ui_providers();
ok( array_keys( $ui ) === array_keys( mlab_smtp_provider_defs() ), 'view registry has the same providers in the same order' );
foreach ( $ui as $id => $meta ) { ok( is_callable( $meta['connect'] ) && isset( $meta['name'], $meta['color'], $meta['letter'], $meta['card']['text'], $meta['card']['bullets'], $meta['success'], $meta['sender'] ), "view data of $id complete" ); }
ok( 'google_account' === $ui['google']['sender']['source'] && $ui['google']['sender']['fixed'] && 'smtp_user' === $ui['smtp']['sender']['source'] && ! $ui['m365']['sender']['fixed'] && ! $ui['smtp']['sender']['fixed'], 'sender behaviour kept (google fixed, sources)' );
ok( str_contains( $ui['google']['success'], 'Google' ) && str_contains( $ui['m365']['success'], 'Microsoft' ) && str_contains( $ui['smtp']['success'], 'Anmeldung' ), 'success texts kept' );

ok( array( 'smtp', 'resend' ) === array_values( array_intersect( array( 'smtp', 'resend', 'm365', 'google' ), mlab_smtp_choices()['fb_provider'] ) ) && 2 === count( mlab_smtp_choices()['fb_provider'] ), 'fallback can be own mail server or Resend (not m365/google)' );
ok( 'smtp' === mlab_smtp_defaults()['fb_provider'], 'fallback defaults to the own mail server: existing setups unchanged' );
$GLOBALS['opts'] = array( MLAB_SMTP_OPTION => array( 'fallback_enabled' => 1, 'fb_smtp_host' => 'mail.x.ch' ) );
ok( mlab_smtp_fallback_ready(), 'fallback ready with server (as before)' );
$GLOBALS['opts'] = array( MLAB_SMTP_OPTION => array( 'fallback_enabled' => 1, 'fb_provider' => 'resend', 'fb_smtp_host' => 'mail.x.ch' ) );
ok( ! mlab_smtp_fallback_ready(), 'Resend fallback needs its key, an SMTP host does not count' );
$GLOBALS['opts'][ MLAB_SMTP_OPTION ]['fb_resend_api_key'] = mlab_smtp_encrypt( 're_fallbackkey12345' );
ok( mlab_smtp_fallback_ready(), 'Resend fallback ready with its key' );
$GLOBALS['opts'][ MLAB_SMTP_OPTION ]['fallback_enabled'] = 0;
ok( ! mlab_smtp_fallback_ready() && mlab_smtp_fallback_filled(), 'switched off: filled but not ready' );
ok( ! is_wp_error( mlab_smtp_sanitize_field( 'fb_resend_api_key', 're_abcdefghij_KL', '' ) ) && is_wp_error( mlab_smtp_sanitize_field( 'fb_resend_api_key', 'nope', '' ) ), 'fallback key is validated like the main key' );
ok( 'resend' === mlab_smtp_sanitize_field( 'fb_provider', 'resend', 'smtp' ) && 'smtp' === mlab_smtp_sanitize_field( 'fb_provider', 'm365', 'smtp' ), 'fb_provider only accepts allowed values' );

// Connection check dispatch.
$GLOBALS['opts'] = array( MLAB_SMTP_OPTION => array( 'provider' => 'smtp', 'smtp_host' => '' ) );
ok( is_wp_error( mlab_smtp_check_connection( 'fallback' ) ), 'fallback without server: error' );
ok( is_wp_error( mlab_smtp_check_connection( 'primary' ) ), 'primary without credentials: error before any provider code runs' );

// Health rows per provider.
foreach ( array( 'm365' => array( true, true, false ), 'google' => array( false, true, false ), 'resend' => array( false, false, true ), 'smtp' => array( false, false, false ) ) as $p => $want ) {
	$def = mlab_smtp_provider_def( $p );
	ok( ( 'mlab_smtp_health_secret_expiry' === ( $def['health'][0] ?? '' ) ) === $want[0] && ( false !== $def['spf'] ) === ! $want[2], "health setup of $p" );
}
ok( 'include:spf.protection.outlook.com' === mlab_smtp_provider_def( 'm365' )['spf'] && 'include:_spf.google.com' === mlab_smtp_provider_def( 'google' )['spf'] && '' === mlab_smtp_provider_def( 'smtp' )['spf'], 'spf includes unchanged' );

// Real health lists per provider (titles), with a configured setup.
function titles( $p ) {
	$GLOBALS['opts'] = array( MLAB_SMTP_OPTION => array( 'provider' => $p, 'setup_done' => 1, 'from_email' => 'a@example.ch', 'resend_api_key' => '', 'm365_secret_expires' => '2099-01-01' ) );
	return array_column( mlab_smtp_health_checks(), 'title' );
}
$t = titles( 'm365' );   ok( in_array( 'SPF-Eintrag', $t, true ) && in_array( 'Ablauf des Client-Secrets', $t, true ) && ! in_array( 'Google-Zugriff', $t, true ) && ! in_array( 'Resend-Domain', $t, true ), 'health rows m365: SPF + secret expiry only' );
$t = titles( 'google' ); ok( in_array( 'SPF-Eintrag', $t, true ) && in_array( 'Google-Zugriff', $t, true ) && ! in_array( 'Ablauf des Client-Secrets', $t, true ), 'health rows google: SPF + google access only' );
$t = titles( 'resend' ); ok( ! in_array( 'SPF-Eintrag', $t, true ) && in_array( 'Resend-Domain', $t, true ) && in_array( 'DMARC-Eintrag', $t, true ), 'health rows resend: no SPF, domain row, DMARC kept' );
$t = titles( 'smtp' );   ok( in_array( 'SPF-Eintrag', $t, true ) && 3 === count( array_intersect( $t, array( 'SPF-Eintrag', 'DMARC-Eintrag', 'Ersatzweg' ) ) ) && ! in_array( 'Resend-Domain', $t, true ), 'health rows smtp: generic rows only' );
ok( 'Verbindung' === titles( 'm365' )[0], 'health order: connection first' );

// Sender step.
$GLOBALS['opts'] = array( MLAB_SMTP_OPTION => array( 'google_account' => 'me@gmail.com', 'smtp_user' => 'u@host.ch' ) );
ok( 'me@gmail.com' === mlab_smtp_sender_prefill( 'google' ) && 'u@host.ch' === mlab_smtp_sender_prefill( 'smtp' ) && '' === mlab_smtp_sender_prefill( 'm365' ), 'sender prefill from the provider account' );
$GLOBALS['opts'][ MLAB_SMTP_OPTION ]['from_email'] = 'x@y.ch';
ok( 'x@y.ch' === mlab_smtp_sender_prefill( 'google' ), 'stored sender wins' );
ob_start(); mlab_smtp_render_sender_fields( 'N', 'me@gmail.com', 'google' ); $h = ob_get_clean();
ok( str_contains( $h, 'readonly' ) && str_contains( $h, 'verbundene Google-Konto' ), 'google sender field is read-only with its help' );
ob_start(); mlab_smtp_render_sender_fields( 'N', 'a@b.ch', 'm365' ); $h = ob_get_clean();
ok( ! str_contains( $h, 'readonly' ) && str_contains( $h, 'vorhandenes Postfach' ), 'm365 sender field editable with default help' );
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n"; exit( $fail ? 1 : 0 );
