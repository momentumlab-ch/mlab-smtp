<?php
// Alarm-Webhook: https, SSRF, Signatur, Drosselung, Ereignisse, Inhalt, Testknopf, Fehler.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/store-helpers.php';

class AL_Redirect extends RuntimeException {}
class AL_Die extends RuntimeException {}
add_filter( 'wp_redirect', static function ( $location ) {
	throw new AL_Redirect( (string) $location );
} );
add_filter( 'wp_die_handler', static function () {
	return static function ( $message, $title = '', $args = array() ) {
		throw new AL_Die( (string) ( is_array( $args ) ? ( $args['response'] ?? 0 ) : 0 ) );
	};
} );
add_filter( 'mlab_smtp_resolve_host', static function ( $pre, $host ) {
	return array(
		'hook.example.test'     => array( '93.184.216.34' ),
		'internal.example.test' => array( '10.0.0.5' ),
	)[ $host ] ?? $pre;
}, 10, 2 );

const AL_URL = 'https://hook.example.test/alarm';

function al_reset(): void {
	delete_option( MLAB_SMTP_ALERTS_OPTION );
	delete_option( MLAB_SMTP_ALERTS_STATE );
	delete_option( MLAB_SMTP_OPTION );
	mlab_http_reset();
	mlab_http_mock( '#hook\.example\.test#', 200, array( 'ok' => true ) );
}
function al_save( array $extra = array() ): array {
	return mlab_smtp_alerts_save( array_merge( array( 'url' => AL_URL, 'events' => array( 'primary_failing' => 1, 'fallback_active' => 1, 'queue_stalled' => 1, 'final_failures' => 1 ) ), $extra ) );
}
function al_hook_requests(): array {
	return array_values( array_filter( mlab_http_requests(), static fn( $r ) => str_contains( $r['url'], 'hook.example.test' ) ) );
}
function al_post( string $fn, array $post, ?string $action ): string {
	$_POST = $_REQUEST = $post;
	if ( $action ) {
		$_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = wp_create_nonce( $action );
	}
	try {
		$fn();
	} catch ( AL_Redirect $e ) {
		return 'redirect';
	} catch ( AL_Die $e ) {
		return 'die:' . $e->getMessage();
	}
	return 'none';
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );

// Validation at save time.
al_reset();
assert_true( ! empty( mlab_smtp_alerts_save( array( 'url' => 'http://hook.example.test/alarm' ) ) ), 'http refused' );
assert_true( ! empty( mlab_smtp_alerts_save( array( 'url' => 'https://internal.example.test/alarm' ) ) ), 'host resolving to a private address refused' );
assert_true( ! empty( mlab_smtp_alerts_save( array( 'url' => 'https://127.0.0.1/alarm' ) ) ), 'loopback refused' );
assert_true( ! empty( mlab_smtp_alerts_save( array( 'url' => 'https://169.254.169.254/latest' ) ) ), 'metadata address refused' );
assert_same( '', mlab_smtp_alerts_get()['url'], 'nothing stored after refusals' );
assert_same( array(), al_save(), 'valid https url saved' );
assert_same( AL_URL, mlab_smtp_alerts_get()['url'], 'url stored' );

// Send time check: the host starts resolving to an internal address after saving.
add_filter( 'mlab_smtp_resolve_host', $rebind = static fn( $pre, $host ) => 'hook.example.test' === $host ? array( '10.1.1.1' ) : $pre, 20, 2 );
assert_same( 'failed', mlab_smtp_alert_dispatch( 'primary_failing', array( 'text' => 'x' ) ), 'dispatch re-checks the url at send time' );
assert_same( 0, count( al_hook_requests() ), 'no request went to the internal address' );
remove_filter( 'mlab_smtp_resolve_host', $rebind, 20 );
al_reset();
al_save();

// Signature with a known answer, timestamp header, JSON body.
al_save( array( 'secret' => 'sig-secret-value-123456' ) );
assert_true( mlab_smtp_alerts_get()['has_secret'] && ! isset( mlab_smtp_alerts_get()['secret'] ), 'secret reported as flag only' );
$raw = (string) get_option( MLAB_SMTP_ALERTS_OPTION )['secret'];
assert_true( '' !== $raw && ! str_contains( $raw, 'sig-secret-value' ) && mlab_smtp_is_encrypted( $raw ), 'secret stored encrypted' );
assert_same( 'sent', mlab_smtp_alert_dispatch( 'primary_failing', array( 'count' => 3, 'text' => 'Fehler' ) ), 'dispatch sent' );
$req = al_hook_requests()[0];
$hdr = $req['args']['headers'];
$exp = 'sha256=' . hash_hmac( 'sha256', (string) $req['args']['body'], 'sig-secret-value-123456' );
assert_same( $exp, $hdr['X-Mlab-Signature'], 'X-Mlab-Signature matches HMAC-SHA256 of the body' );
assert_true( ctype_digit( (string) $hdr['X-Mlab-Timestamp'] ), 'timestamp header' );
$payload = json_decode( (string) $req['args']['body'], true );
assert_same( 'primary_failing', $payload['event'], 'payload event' );
assert_same( 3, $payload['counts']['affected'], 'payload counts' );
assert_same( (string) wp_parse_url( home_url(), PHP_URL_HOST ), $payload['site'], 'payload site host' );
assert_same( 0, (int) $req['args']['redirection'], 'no redirects followed' );

// No signature without secret.
al_reset();
al_save();
mlab_smtp_alert_dispatch( 'final_failures', array( 'count' => 1 ) );
assert_true( ! isset( al_hook_requests()[0]['args']['headers']['X-Mlab-Signature'] ), 'no signature header without secret' );

// Throttle per event type.
al_reset();
al_save();
assert_same( 'sent', mlab_smtp_alert_dispatch( 'primary_failing', array() ), 'first alert sent' );
assert_same( 'throttled', mlab_smtp_alert_dispatch( 'primary_failing', array() ), 'second alert of the same type throttled' );
assert_same( 'sent', mlab_smtp_alert_dispatch( 'queue_stalled', array() ), 'other type not throttled' );
add_filter( 'mlab_smtp_alert_throttle', static fn() => 0 );
assert_same( 'sent', mlab_smtp_alert_dispatch( 'primary_failing', array() ), 'filter lowers the throttle' );
remove_all_filters( 'mlab_smtp_alert_throttle' );

// Toggles.
al_reset();
al_save( array( 'events' => array( 'primary_failing' => 1 ) ) );
assert_same( 'disabled', mlab_smtp_alert_dispatch( 'queue_stalled', array() ), 'switched-off event not sent' );
assert_same( 0, count( al_hook_requests() ), 'no request for a switched-off event' );
mlab_smtp_alerts_save( array( 'url' => '' ) );
assert_same( 'off', mlab_smtp_alert_dispatch( 'primary_failing', array() ), 'no url: nothing sent' );

// Payload content: no recipient, subject, secret.
al_reset();
mlab_smtp_store( array( 'provider' => 'resend', 'setup_done' => 1, 'from_email' => 'info@example.ch', 'resend_api_key' => 're_main_key_1234567890' ) );
mlab_t_reset_store();
al_save( array( 'secret' => 'sig-secret-value-123456' ) );
mlab_t_msg( array( 'recipients' => array( 'kunde@example.test' ), 'subject' => 'Streng geheimer Betreff', 'status' => 'failed' ) );
do_action( 'mlab_smtp_failure', 'Resend sagte nein fuer kunde@example.test mit re_main_key_1234567890', false );
$body = (string) al_hook_requests()[0]['args']['body'];
foreach ( array( 'kunde@example.test', 'Streng geheimer Betreff', 're_main_key_1234567890', 'sig-secret-value-123456' ) as $needle ) {
	assert_true( ! str_contains( $body, $needle ), "payload does not contain $needle" );
}

// Event wiring: failure action, fallback_active, message_failed, status_changed.
al_reset();
al_save();
add_filter( 'mlab_smtp_alert_throttle', static fn() => 0 );
do_action( 'mlab_smtp_failure', 'Fehler', true );
$events = array_map( static fn( $r ) => json_decode( (string) $r['args']['body'], true )['event'], al_hook_requests() );
assert_same( array( 'primary_failing', 'fallback_active' ), $events, 'failure with fallback raises both events' );
mlab_http_reset();
mlab_http_mock( '#hook\.example\.test#', 200, array() );
do_action( 'mlab_smtp_message_failed', 5 );
do_action( 'mlab_smtp_message_status_changed', 5, 'delivered' );
do_action( 'mlab_smtp_message_status_changed', 5, 'failed' );
$events = array_map( static fn( $r ) => json_decode( (string) $r['args']['body'], true )['event'], al_hook_requests() );
assert_same( array( 'final_failures', 'final_failures' ), $events, 'message_failed and status failed raise final_failures, delivered does not' );
remove_all_filters( 'mlab_smtp_alert_throttle' );

// Alert is independent of the mail route: provider answers 500, alert still goes out.
al_reset();
al_save();
mlab_http_mock( '#api\.resend\.com#', 500, array( 'message' => 'down' ) );
assert_same( 'sent', mlab_smtp_alert_dispatch( 'primary_failing', array() ), 'alert sent while the mail provider answers 500' );

// Test button.
al_reset();
al_save();
$res = mlab_smtp_alert_test();
assert_true( $res['ok'] && 200 === $res['code'] && str_contains( $res['message'], 'zugestellt' ), 'test ok message' );
assert_true( str_contains( (string) al_hook_requests()[0]['args']['body'], '"event":"test"' ) && str_contains( (string) al_hook_requests()[0]['args']['body'], 'TEST' ), 'test event clearly marked' );
mlab_http_reset();
mlab_http_mock( '#hook\.example\.test#', 500, 'boom' );
$res = mlab_smtp_alert_test();
assert_true( ! $res['ok'] && 500 === $res['code'] && str_contains( $res['message'], 'HTTP 500' ), 'test 500 message' );
mlab_http_reset();
mlab_smtp_alerts_save( array( 'url' => '' ) );
$res = mlab_smtp_alert_test();
assert_true( ! $res['ok'] && str_contains( $res['message'], 'keine Adresse' ), 'test without url message' );

// A failing alert does not throw and is remembered.
al_reset();
al_save();
mlab_http_reset(); // no mock: WP_Error from the transport
assert_same( 'failed', mlab_smtp_alert_dispatch( 'primary_failing', array( 'text' => 'x' ) ), 'transport error: failed, no exception' );
$last = mlab_smtp_alerts_last();
assert_true( ! $last['ok'] && 'primary_failing' === $last['event'] && $last['time'] > 0 && '' !== $last['message'], 'failure remembered for the UI' );
mlab_http_mock( '#hook\.example\.test#', 502, 'bad gateway' );
mlab_smtp_alert_dispatch( 'queue_stalled', array() );
assert_same( 502, mlab_smtp_alerts_last()['code'], 'http code remembered' );
$sent = al_hook_requests();

// Handlers: nonce and capability.
al_reset();
al_save();
mlab_http_mock( '#hook\.example\.test#', 200, array() );
wp_set_current_user( 0 );
assert_same( 'die:403', al_post( 'mlab_smtp_alert_handle_test', array(), 'mlab_smtp_alert_test' ), 'anonymous test rejected' );
$sub = wp_insert_user( array( 'user_login' => 'al_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $sub );
assert_same( 'die:403', al_post( 'mlab_smtp_alert_handle_test', array(), 'mlab_smtp_alert_test' ), 'subscriber test rejected' );
wp_set_current_user( $admins[0]->ID );
assert_same( 'die:403', al_post( 'mlab_smtp_alert_handle_test', array(), null ), 'no nonce rejected' );
assert_same( 0, count( al_hook_requests() ), 'rejected requests sent nothing' );
assert_same( 'redirect', al_post( 'mlab_smtp_alert_handle_test', array(), 'mlab_smtp_alert_test' ), 'admin test works' );
assert_same( 1, count( al_hook_requests() ), 'test sent through the handler' );
assert_same( 'die:403', al_post( 'mlab_smtp_alert_handle_save', array( 'url' => 'https://hook.example.test/x' ), null ), 'save needs nonce' );
assert_same( 'redirect', al_post( 'mlab_smtp_alert_handle_save', array( 'url' => 'https://hook.example.test/x', 'events' => array( 'queue_stalled' => '1' ) ), 'mlab_smtp_alert_save' ), 'save with nonce' );
assert_same( true, mlab_smtp_alerts_get()['events']['queue_stalled'] && ! mlab_smtp_alerts_get()['events']['primary_failing'], 'handler stored the toggles' );

t_finish();
