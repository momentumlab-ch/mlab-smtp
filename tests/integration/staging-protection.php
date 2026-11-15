<?php
// Staging-Schutz: block, redirect, off, Erkennung (auto), Konstante, Bestätigung, Hinweis.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/store-helpers.php';

class SP_Redirect extends RuntimeException {}
class SP_Die extends RuntimeException {}

add_filter( 'wp_redirect', static function ( $location ) {
	throw new SP_Redirect( (string) $location );
} );
add_filter( 'wp_die_handler', static function () {
	return static function ( $message, $title = '', $args = array() ) {
		throw new SP_Die( (string) ( is_array( $args ) ? ( $args['response'] ?? 0 ) : 0 ) );
	};
} );
$GLOBALS['sp_env'] = 'production';
add_filter( 'mlab_smtp_environment_type', static fn() => $GLOBALS['sp_env'] );

function sp_reset( array $staging = array() ): void {
	mlab_t_reset_store();
	delete_option( MLAB_SMTP_OPTION );
	delete_option( MLAB_SMTP_STAGING_OPTION );
	delete_option( MLAB_SMTP_STATE_OPTION );
	mlab_http_reset();
	$GLOBALS['sp_env'] = 'production';
	mlab_smtp_store( array( 'provider' => 'resend', 'setup_done' => 1, 'from_email' => 'info@example.ch', 'resend_api_key' => 're_main_key_1234567890' ) );
	mlab_http_mock( '#api\.resend\.com/emails#', 200, array( 'id' => 'mail-1' ) );
	mlab_smtp_staging_init();
	if ( $staging ) {
		update_option( MLAB_SMTP_STAGING_OPTION, array_merge( mlab_smtp_staging_get(), $staging ), false );
	}
}
function sp_post( string $fn, array $post, ?string $action ): string {
	$_POST = $_REQUEST = $post;
	if ( $action ) {
		$_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = wp_create_nonce( $action );
	}
	try {
		$fn();
	} catch ( SP_Redirect $e ) {
		return 'redirect';
	} catch ( SP_Die $e ) {
		return 'die:' . $e->getMessage();
	}
	return 'none';
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );

// First run stores the fingerprint; production, unchanged: no protection.
sp_reset();
assert_same( mlab_smtp_staging_fingerprint(), mlab_smtp_staging_get()['fingerprint'], 'first run stores the fingerprint' );
$st = mlab_smtp_staging_status();
assert_same( 'off', $st['effective'], 'auto + production + same url: not restricted' );
assert_same( 'auto', $st['mode'], 'default mode is auto' );
assert_same( '', mlab_smtp_staging_banner(), 'no banner when not restricted' );

// Detection matrix.
update_option( MLAB_SMTP_STAGING_OPTION, array_merge( mlab_smtp_staging_get(), array( 'fingerprint' => hash( 'sha256', 'https://old.example' ) ) ), false );
$st = mlab_smtp_staging_status();
assert_same( 'block', $st['effective'], 'url changed => block' );
assert_same( 'url_changed', $st['reason'], 'reason url_changed' );
assert_true( str_contains( $st['text'], 'Kopie' ), 'German explanation' );
sp_reset();
$GLOBALS['sp_env'] = 'staging';
assert_same( 'block', mlab_smtp_staging_status()['effective'], 'environment type staging => block' );
foreach ( array( 'development', 'local' ) as $env ) {
	$GLOBALS['sp_env'] = $env;
	assert_same( 'block', mlab_smtp_staging_status()['effective'], "environment $env => block" );
}
$GLOBALS['sp_env'] = 'staging';
mlab_smtp_staging_confirm_production();
assert_same( 'off', mlab_smtp_staging_status()['effective'], 'confirmed once: not blocked any more' );
$GLOBALS['sp_env'] = 'local';
assert_same( 'block', mlab_smtp_staging_status()['effective'], 'another environment type needs a new confirmation' );
sp_reset( array( 'env_hash' => 'other-abspath-or-db' ) );
$st = mlab_smtp_staging_status();
assert_same( 'off', $st['effective'], 'only ABSPATH/DB changed: not a block' );
assert_true( '' !== $st['warning'], 'only ABSPATH/DB changed: warning' );
assert_true( str_contains( mlab_smtp_staging_banner(), 'notice-warning' ), 'warning banner' );

// Block: no HTTP, wp_mail false, wp_mail_failed fired, row blocked, no second failed row, no failure state.
sp_reset( array( 'mode' => 'block' ) );
$failed = array();
add_action( 'wp_mail_failed', static function ( $e ) use ( &$failed ) {
	$failed[] = $e;
} );
$result = wp_mail( 'kunde@example.test', 'Geheimer Betreff', 'Text' );
assert_same( false, $result, 'block: wp_mail returns false' );
assert_same( 0, count( mlab_http_requests() ), 'block: no HTTP request reaches a provider' );
assert_same( 1, count( $failed ), 'block: wp_mail_failed fired once' );
assert_same( 'mlab_smtp_blocked', $failed[0]->get_error_code(), 'block: error code' );
$counts = mlab_smtp_store_counts();
assert_same( 1, $counts['blocked'], 'block: one row with status blocked' );
assert_same( 0, $counts['failed'], 'block: no extra failed row' );
assert_same( 0, (int) mlab_smtp_state_get()['failures_since'], 'block: no delivery failure counted' );
assert_same( 'block', mlab_smtp_staging_status()['effective'], 'block: stays effective' );

// Off.
sp_reset( array( 'mode' => 'off' ) );
$GLOBALS['sp_env'] = 'staging';
assert_same( 'off', mlab_smtp_staging_status()['effective'], 'off: never restricted' );
assert_same( 'off', mlab_smtp_staging_status()['mode'], 'off: mode off' );
assert_same( null, mlab_smtp_staging_pre_wp_mail( null, array( 'to' => 'kunde@example.test' ) ), 'off: pre_wp_mail does not intercept' );
assert_same( 'Hallo', apply_filters( 'wp_mail', array( 'to' => 'k@example.test', 'subject' => 'Hallo', 'message' => 'T' ) )['subject'], 'off: wp_mail args untouched' );

// Redirect.
sp_reset();
assert_true( ! empty( mlab_smtp_staging_save( array( 'mode' => 'redirect', 'test_email' => 'nope' ) ) ), 'invalid test address refused' );
assert_same( 'auto', mlab_smtp_staging_get()['mode'], 'refused input is not stored' );
assert_same( array(), mlab_smtp_staging_save( array( 'mode' => 'redirect', 'test_email' => 'test@example.ch' ) ), 'valid redirect saved' );
$atts = apply_filters( 'wp_mail', array( 'to' => array( 'kunde@example.test', 'x@example.test' ), 'subject' => 'Hallo', 'message' => 'Text', 'headers' => "Cc: cc@example.test\nBcc: bcc@example.test\nReply-To: r@example.test", 'attachments' => array() ) );
assert_same( 'test@example.ch', $atts['to'], 'redirect: recipient replaced' );
assert_true( ! str_contains( $atts['headers'], 'Cc' ) && ! str_contains( $atts['headers'], 'Bcc' ) && str_contains( $atts['headers'], 'Reply-To' ), 'redirect: cc/bcc removed from string headers' );
assert_same( '[Staging] Hallo', $atts['subject'], 'redirect: subject prefix' );
assert_true( str_contains( $atts['message'], 'To: k***@e***.test' ) && str_contains( $atts['message'], 'Cc: c***@e***.test' ) && str_contains( $atts['message'], 'Bcc: b***@e***.test' ) && ! str_contains( $atts['message'], 'kunde@example.test' ), 'redirect: footer lists the original recipients only masked' );
$atts = apply_filters( 'wp_mail', array( 'to' => 'a@example.test', 'subject' => 'S', 'message' => 'M', 'headers' => array( 'CC: c@example.test', 'bcc: b@example.test', 'Content-Type: text/html' ), 'attachments' => array() ) );
assert_same( array( 'Content-Type: text/html' ), $atts['headers'], 'redirect: cc/bcc removed from array headers' );
assert_true( str_contains( $atts['message'], '<hr>' ), 'redirect: html footer for html mail' );
mlab_http_reset();
mlab_http_mock( '#api\.resend\.com/emails#', 200, array( 'id' => 'mail-1' ) );
wp_mail( 'kunde@example.test', 'Hallo', 'Text', array( 'Cc: cc@example.test' ) );
$bodies = implode( ' ', array_map( static fn( $r ) => (string) ( $r['args']['body'] ?? '' ), mlab_http_requests() ) );
assert_true( '' !== $bodies && ! str_contains( $bodies, 'kunde@example.test' ) && ! str_contains( $bodies, 'cc@example.test' ), 'redirect: the provider never sees the original recipients' );
assert_true( str_contains( $bodies, 'k***@e***.test' ), 'redirect: the footer lists them only masked' );
assert_true( str_contains( $bodies, 'test@example.ch' ), 'redirect: provider gets the test address' );
sp_reset( array( 'mode' => 'redirect', 'test_email' => '' ) );
assert_same( 'block', mlab_smtp_staging_status()['effective'], 'redirect without test address falls back to block' );

// Confirm handler: nonce and capability.
sp_reset( array( 'mode' => 'auto', 'fingerprint' => hash( 'sha256', 'https://old.example' ) ) );
wp_set_current_user( 0 );
assert_same( 'die:403', sp_post( 'mlab_smtp_staging_handle_confirm', array(), 'mlab_smtp_staging_confirm' ), 'anonymous rejected' );
$sub = wp_insert_user( array( 'user_login' => 'sp_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $sub );
assert_same( 'die:403', sp_post( 'mlab_smtp_staging_handle_confirm', array(), 'mlab_smtp_staging_confirm' ), 'subscriber rejected' );
wp_set_current_user( $admins[0]->ID );
assert_same( 'die:403', sp_post( 'mlab_smtp_staging_handle_confirm', array(), null ), 'admin without nonce rejected' );
assert_same( 'url_changed', mlab_smtp_staging_status()['reason'], 'nothing confirmed so far' );
assert_same( 'redirect', sp_post( 'mlab_smtp_staging_handle_confirm', array(), 'mlab_smtp_staging_confirm' ), 'admin with nonce confirms and is sent back' );
assert_same( 'none', mlab_smtp_staging_status()['reason'], 'fingerprint updated by confirm' );
assert_same( 'die:403', sp_post( 'mlab_smtp_staging_handle_save', array( 'mode' => 'off' ), null ), 'save needs a nonce' );
assert_same( 'redirect', sp_post( 'mlab_smtp_staging_handle_save', array( 'mode' => 'block' ), 'mlab_smtp_staging_save' ), 'save with nonce' );
assert_same( 'block', mlab_smtp_staging_get()['mode'], 'save handler stored the mode' );

// Notice on a non-plugin admin screen.
sp_reset( array( 'mode' => 'block' ) );
set_current_screen( 'dashboard' );
ob_start();
do_action( 'admin_notices' );
$html = (string) ob_get_clean();
assert_true( str_contains( $html, 'mlab-smtp-staging-banner' ) && str_contains( $html, 'Das ist die produktive Website' ), 'notice with confirm button on the dashboard' );
assert_true( str_contains( $html, 'mlab_smtp_staging_confirm' ) && str_contains( $html, 'view=settings' ), 'confirm form and settings link' );
wp_set_current_user( $sub );
ob_start();
do_action( 'admin_notices' );
assert_same( '', trim( (string) ob_get_clean() ), 'no notice for users without manage_options' );

// Constant override beats the setting. Last on purpose: a constant cannot be undefined for the rest of the process.
sp_reset( array( 'mode' => 'block' ) );
define( 'MLAB_SMTP_STAGING', 'off' );
$st = mlab_smtp_staging_status();
assert_same( 'off', $st['effective'], 'constant off beats setting block' );
assert_same( 'constant', $st['reason'], 'reason constant' );


t_finish();
