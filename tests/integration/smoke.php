<?php
// Smoke-Test gegen echtes WordPress (SQLite): Plugin aktiv, Optionen, REST, Nonce, wp_mail.
require dirname( __DIR__ ) . '/bootstrap-wp.php';

// 1. Plugin aktiv.
assert_true( is_plugin_active( 'mlab-smtp/mlab-smtp.php' ), 'plugin mlab-smtp is active' );
assert_true( function_exists( 'mlab_smtp_get' ) && defined( 'MLAB_SMTP_VERSION' ), 'plugin functions are loaded' );

// 2. Optionen inkl. verschlüsseltem Secret.
delete_option( MLAB_SMTP_OPTION );
assert_true( mlab_smtp_store( array( 'provider' => 'resend', 'setup_done' => 1, 'from_email' => 'noreply@example.ch', 'resend_api_key' => 're_test_1234567890abcdef' ) ), 'store() succeeds' );
assert_same( 'resend', mlab_smtp_get( 'provider' ), 'provider round-trips through get_option' );
assert_same( 're_test_1234567890abcdef', mlab_smtp_get( 'resend_api_key' ), 'secret decrypts to the original' );
$raw = get_option( MLAB_SMTP_OPTION );
assert_true( is_array( $raw ) && '' !== $raw['resend_api_key'] && 're_test_1234567890abcdef' !== $raw['resend_api_key'], 'secret is stored encrypted, not as plain text' );

// 3. REST-Route /mlab-smtp/v1/check: anonym verboten, Admin erlaubt.
do_action( 'rest_api_init' );
wp_set_current_user( 0 );
$res = rest_do_request( new WP_REST_Request( 'POST', '/mlab-smtp/v1/check' ) );
assert_true( in_array( $res->get_status(), array( 401, 403 ), true ), 'REST /check denies an anonymous request (HTTP ' . $res->get_status() . ')' );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
mlab_http_mock( '#api\.resend\.com#', 200, array( 'data' => array() ) );
$res = rest_do_request( new WP_REST_Request( 'POST', '/mlab-smtp/v1/check' ) );
assert_same( 200, $res->get_status(), 'REST /check allows an administrator' );

// 4. admin-post ohne Nonce wird abgelehnt.
$_POST    = array();
$_REQUEST = array();
$rejected = false;
add_filter( 'wp_die_handler', static function () {
	return static function () {
		throw new RuntimeException( 'wp_die' );
	};
} );
try {
	mlab_smtp_handle_test();
} catch ( Throwable $e ) {
	$rejected = true;
}
assert_true( $rejected, 'admin-post action without nonce is rejected' );

// 5. wp_mail() mit gemockter Resend-Antwort.
mlab_http_reset();
mlab_http_mock( '#api\.resend\.com/emails#', 200, array( 'id' => 'mock-id-1' ) );
$sent = wp_mail( 'to@example.ch', 'Betreff', 'Text' );
assert_true( true === $sent, 'wp_mail() returns true with a mocked Resend response' );
$urls = array_column( mlab_http_requests(), 'url' );
assert_true( (bool) preg_grep( '#api\.resend\.com/emails#', $urls ), 'a request to the Resend API was recorded' );

delete_option( MLAB_SMTP_OPTION );
t_finish();
