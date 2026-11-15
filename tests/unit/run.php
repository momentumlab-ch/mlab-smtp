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
require MLAB_SMTP_DIR . 'inc/check.php';
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$GLOBALS['opts'] = array( MLAB_SMTP_OPTION => array( 'provider' => 'resend', 'from_email' => 'noreply@example.ch', 'from_name' => 'Müller, Hans "X"' ) );
$fail = 0;
function ok( $cond, $label ) { global $fail; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }
function reset_state() { $GLOBALS['tr'] = array(); $GLOBALS['calls'] = array(); $GLOBALS['mock'] = array(); }

// ---- 1. Key sanitizing.
ok( null === mlab_smtp_sanitize_resend_key( '   ' ), 'empty key keeps stored' );
ok( 're_abcdefghij_KL' === mlab_smtp_sanitize_resend_key( " re_abcdefghij_KL\n" ), 'key is trimmed' );
ok( is_wp_error( mlab_smtp_sanitize_resend_key( 'sk-123' ) ), 'wrong key format rejected' );
ok( is_wp_error( mlab_smtp_sanitize_resend_key( "re_abcdefghij\r\nX-Evil: 1" ) ), 'header injection rejected' );

// ---- 2. Payload from a real PHPMailer message.
use PHPMailer\PHPMailer\PHPMailer;
$m = new PHPMailer( true );
$m->setFrom( 'noreply@example.ch', 'Müller, Hans "X"' );
$m->addAddress( 'kunde@example.com', 'Kunde' );
$m->addCC( 'cc@example.com' );
$m->addBCC( 'bcc@example.com' );
$m->addReplyTo( 'info@example.ch', 'Info' );
$m->Subject = 'Grüezi';
$m->isHTML( true );
$m->Body    = '<p>Hallo <img src="cid:logo"></p>';
$m->AltBody = 'Hallo';
$m->addCustomHeader( 'X-Mailer', 'WP' );
$m->addCustomHeader( 'Content-Type', 'text/html' );
$m->addStringAttachment( 'PDFDATA', 'rechnung.pdf', 'base64', 'application/pdf' );
$m->addStringEmbeddedImage( 'PNGDATA', 'logo', 'logo.png', 'base64', 'image/png' );
$m->preSend();
$p = mlab_smtp_resend_payload( $m );
ok( ! is_wp_error( $p ), 'payload built' );
ok( '"Müller, Hans X" <noreply@example.ch>' === $p['from'], 'from formatted and quote stripped: ' . $p['from'] );
ok( array( 'Kunde <kunde@example.com>' ) === $p['to'], 'to' );
ok( array( 'cc@example.com' ) === $p['cc'] && array( 'bcc@example.com' ) === $p['bcc'], 'cc / bcc' );
ok( array( 'Info <info@example.ch>' ) === $p['reply_to'], 'reply_to' );
ok( '<p>Hallo <img src="cid:logo"></p>' === $p['html'] && 'Hallo' === $p['text'], 'html + text' );
ok( 'WP' === ( $p['headers']['X-Mailer'] ?? '' ) && ! isset( $p['headers']['Content-Type'] ), 'custom header passed, reserved dropped' );
ok( 2 === count( $p['attachments'] ), 'two attachments' );
ok( 'rechnung.pdf' === $p['attachments'][0]['filename'] && base64_encode( 'PDFDATA' ) === $p['attachments'][0]['content'] && 'application/pdf' === $p['attachments'][0]['content_type'] && ! isset( $p['attachments'][0]['content_id'] ), 'file attachment' );
ok( 'logo' === ( $p['attachments'][1]['content_id'] ?? '' ), 'inline image has content_id' );

$t = new PHPMailer( true );
$t->setFrom( 'a@example.ch' ); $t->addAddress( 'b@example.com' ); $t->Subject = 's'; $t->Body = 'plain'; $t->preSend();
$p = mlab_smtp_resend_payload( $t );
ok( 'plain' === $p['text'] && ! isset( $p['html'] ) && ! isset( $p['cc'] ) && ! isset( $p['attachments'] ), 'plain text mail has only text' );

$h = new PHPMailer( true );
$h->setFrom( 'a@example.ch' ); $h->addAddress( 'b@example.com' ); $h->Subject = 's'; $h->isHTML( true ); $h->Body = '<b>x</b>'; $h->preSend();
$p = mlab_smtp_resend_payload( $h );
ok( '<b>x</b>' === $p['html'] && ! isset( $p['text'] ), 'html-only mail has html and no text (Resend derives it)' );

$many = new PHPMailer( true );
$many->setFrom( 'a@example.ch' ); $many->Subject = 's'; $many->Body = 'x';
for ( $i = 0; $i < 51; $i++ ) { $many->addAddress( "u$i@example.com" ); }
ok( is_wp_error( mlab_smtp_resend_payload( $many ) ), '51 recipients rejected before the request' );

$big = new PHPMailer( true );
$big->setFrom( 'a@example.ch' ); $big->addAddress( 'b@example.com' ); $big->Subject = 's'; $big->Body = 'x';
$big->addStringAttachment( str_repeat( 'a', 31 * MB_IN_BYTES ), 'big.bin' );
$e = mlab_smtp_resend_payload( $big );
ok( is_wp_error( $e ) && str_contains( $e->get_error_message(), 'zu gross' ), '31 MB raw (>40 MB base64) rejected: ' . ( is_wp_error( $e ) ? $e->get_error_message() : '' ) );

$f = tempnam( sys_get_temp_dir(), 'att' ); $fh = fopen( $f, 'w' ); ftruncate( $fh, 31 * MB_IN_BYTES ); fclose( $fh );
$bf = new PHPMailer( true );
$bf->setFrom( 'a@example.ch' ); $bf->addAddress( 'b@example.com' ); $bf->Subject = 's'; $bf->Body = 'x'; $bf->addAttachment( $f );
$before = memory_get_peak_usage();
$e = mlab_smtp_resend_payload( $bf );
ok( is_wp_error( $e ) && ( memory_get_peak_usage() - $before ) < 5 * MB_IN_BYTES, 'oversized file is rejected from its size without being read into memory' );
unlink( $f );

// ---- 3. Sending: success, idempotency, errors.
reset_state();
$GLOBALS['mock']['POST /emails'] = array( 200, array( 'id' => 'abc' ) );
$m->preSend();
ok( true === mlab_smtp_send_resend( $m ), 'send ok' );
$call = $GLOBALS['calls'][0];
ok( 'Bearer ' . MLAB_SMTP_RESEND_API_KEY === $call[2]['headers']['Authorization'], 'bearer header' );
ok( str_starts_with( $call[2]['headers']['Idempotency-Key'], 'mlab-' ) && strlen( $call[2]['headers']['Idempotency-Key'] ) <= 256, 'idempotency key' );
ok( 'mlab-smtp/1.3.0' === $call[2]['headers']['User-Agent'], 'generic user agent' );
$key1 = $call[2]['headers']['Idempotency-Key'];
mlab_smtp_send_resend( $m );
ok( $GLOBALS['calls'][1][2]['headers']['Idempotency-Key'] === $key1, 'same message gives same idempotency key' );

$errs = array(
	array( 403, 'validation_error', 'The `example.ch` domain is not verified. Please, add and verify your domain.', 'nicht verifiziert' ),
	array( 403, 'validation_error', 'You can only send testing emails to your own email address (x@y.ch).', 'resend.dev' ),
	array( 400, 'validation_error', 'API key is invalid', 'kennt den API-Key nicht' ),
	array( 429, 'daily_quota_exceeded', 'You have exceeded your daily email sending quota.', 'Kontingent' ),
	array( 429, 'rate_limit_exceeded', 'Too many requests.', '10 Anfragen' ),
	array( 503, 'service_unavailable', 'API is temporarily unavailable', 'vorübergehend gestört' ),
	array( 422, 'missing_required_field', 'Missing `to` field.', 'ungültig' ),
	array( 403, 'restricted_api_key', 'API key is not active', 'nicht aktiv' ),
);
foreach ( $errs as $e ) {
	$GLOBALS['mock']['POST /emails'] = array( $e[0], array( 'statusCode' => $e[0], 'name' => $e[1], 'message' => $e[2] ) );
	$r = mlab_smtp_send_resend( $m );
	$hint = is_wp_error( $r ) ? mlab_smtp_explain_error( $r->get_error_message() ) : '';
	ok( is_wp_error( $r ) && str_contains( $hint, $e[3] ), "error {$e[0]} {$e[1]} -> hint contains «{$e[3]}»" . ( $hint ? '' : ' [no hint; msg=' . ( is_wp_error( $r ) ? $r->get_error_message() : 'ok' ) . ']' ) );
}
unset( $GLOBALS['mock']['POST /emails'] );
$r = mlab_smtp_send_resend( $m );
ok( is_wp_error( $r ) && str_contains( mlab_smtp_explain_error( $r->get_error_message() ), 'nicht erreichbar' ), 'network error -> api unreachable hint, not mail-server hint' );

// ---- 4. Key check and scopes.
reset_state();
$GLOBALS['mock']['GET /domains'] = array( 200, array( 'object' => 'list', 'data' => array(
	array( 'id' => 'd1', 'name' => 'Example.ch', 'status' => 'verified' ),
	array( 'id' => 'd2', 'name' => 'neu.example.ch', 'status' => 'not_started' ),
	array( 'id' => 'd3', 'name' => 'alt.example.ch', 'status' => 'failed' ),
	array( 'id' => 'd4', 'name' => 'teil.example.ch', 'status' => 'partially_verified' ),
) ) );
ok( true === mlab_smtp_resend_check(), 'full key check ok' );
ok( str_contains( mlab_smtp_resend_success_text(), 'Verbunden' ), 'full key success text' );

reset_state();
$GLOBALS['mock']['GET /domains'] = array( 401, array( 'statusCode' => 401, 'name' => 'restricted_api_key', 'message' => 'This API key is restricted to only send emails.' ) );
ok( true === mlab_smtp_resend_check(), 'sending-only key counts as valid' );
ok( str_contains( mlab_smtp_resend_success_text(), 'nur Mails senden' ), 'sending key success text' );
$st = mlab_smtp_resend_domain_state( 'example.ch' );
ok( 'sending' === $st['state'] && ! mlab_smtp_resend_domain_blocks( $st ), 'sending scope never blocks' );
ok( 'info' === mlab_smtp_resend_state_text( $st )['level'], 'sending scope text is info' );

reset_state();
$GLOBALS['mock']['GET /domains'] = array( 400, array( 'statusCode' => 400, 'name' => 'validation_error', 'message' => 'API key is invalid' ) );
$r = mlab_smtp_resend_check();
ok( is_wp_error( $r ) && str_contains( mlab_smtp_explain_error( $r->get_error_message() ), 'kennt den API-Key nicht' ), 'invalid key -> clear error' );

reset_state();
$GLOBALS['mock']['GET /domains'] = array( 403, array( 'statusCode' => 403, 'name' => 'restricted_api_key', 'message' => 'API key is not active' ) );
ok( is_wp_error( mlab_smtp_resend_check() ), '403 restricted (not active) is an error, not a sending key' );

// ---- 5. Domain states.
reset_state();
$GLOBALS['mock']['GET /domains'] = array( 200, array( 'data' => array(
	array( 'id' => 'd1', 'name' => 'Example.ch', 'status' => 'verified' ),
	array( 'id' => 'd2', 'name' => 'neu.example.ch', 'status' => 'not_started' ),
	array( 'id' => 'd3', 'name' => 'alt.example.ch', 'status' => 'temporary_failure' ),
	array( 'id' => 'd4', 'name' => 'teil.example.ch', 'status' => 'partially_verified' ),
	array( 'id' => 'd5', 'name' => 'pf.example.ch', 'status' => 'partially_failed' ),
) ) );
$GLOBALS['mock']['GET /domains/d2'] = array( 200, array( 'records' => array(
	array( 'record' => 'SPF', 'name' => 'send', 'type' => 'MX', 'value' => 'feedback-smtp.eu-west-1.amazonses.com', 'status' => 'not_started', 'priority' => 10 ),
	array( 'record' => 'SPF', 'name' => 'send', 'type' => 'TXT', 'value' => '"v=spf1 include:amazonses.com ~all"', 'status' => 'not_started' ),
	array( 'record' => 'DKIM', 'name' => 'resend._domainkey', 'type' => 'TXT', 'value' => 'p=MIG', 'status' => 'verified' ),
) ) );
$GLOBALS['mock']['GET /domains/d3'] = array( 500, array( 'statusCode' => 500, 'name' => 'application_error', 'message' => 'boom' ) );
$GLOBALS['mock']['GET /domains/d5'] = array( 200, array( 'records' => array() ) );
$cases = array( 'example.ch' => 'verified', 'EXAMPLE.CH' => 'verified', 'neu.example.ch' => 'pending', 'alt.example.ch' => 'failed', 'teil.example.ch' => 'verified', 'pf.example.ch' => 'partial', 'fremd.ch' => 'missing', 'resend.dev' => 'testing', '' => 'none' );
foreach ( $cases as $domain => $want ) {
	$st = mlab_smtp_resend_domain_state( $domain );
	ok( $want === $st['state'], "domain '$domain' -> $want (got {$st['state']})" );
}
$n = count( $GLOBALS['calls'] );
$st = mlab_smtp_resend_domain_state( 'neu.example.ch' );
ok( array() === $st['records'] && 'pending' === $st['state'] && count( $GLOBALS['calls'] ) === $n, 'records are not fetched unless asked for (overview stays cheap)' );
$st = mlab_smtp_resend_domain_state( 'neu.example.ch', false, true );
ok( 2 === count( $st['records'] ) && 'MX' === $st['records'][0]['type'] && '10' === $st['records'][0]['priority'], 'only open records, verified DKIM dropped' );
ok( mlab_smtp_resend_domain_blocks( $st ), 'pending blocks' );
$st = mlab_smtp_resend_domain_state( 'alt.example.ch', false, true );
ok( 'failed' === $st['state'] && '' !== $st['error'] && array() === $st['records'], 'records lookup error is kept, state still failed' );
$st = mlab_smtp_resend_domain_state( 'fremd.ch' );
ok( mlab_smtp_resend_domain_blocks( $st ) && str_contains( mlab_smtp_resend_state_text( $st )['message'], 'example.ch' ), 'missing blocks and names verified domains' );
ok( ! mlab_smtp_resend_domain_blocks( mlab_smtp_resend_domain_state( 'pf.example.ch' ) ), 'partial does not block' );
ok( 'noreply@example.ch' === mlab_smtp_resend_suggest_sender(), 'suggests first verified domain' );

reset_state();
$st = mlab_smtp_resend_domain_state( 'example.ch' );
ok( 'error' === $st['state'] && ! mlab_smtp_resend_domain_blocks( $st ), 'lookup failure shows warning but never blocks' );
$n = count( $GLOBALS['calls'] );
mlab_smtp_resend_domain_state( 'example.ch' );
ok( count( $GLOBALS['calls'] ) === $n, 'failed lookup is cached (no request hammering)' );

ok( null === mlab_smtp_resend_sender_block( false ) , 'sender block returns null when lookup fails' );

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit( $fail ? 1 : 0 );
