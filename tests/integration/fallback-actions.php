<?php
// Backup lifecycle: authorisation, preservation of the primary SMTP route and verified deletion of backup data.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/store-helpers.php';
class FA_Redirect extends RuntimeException {}
class FA_Die extends RuntimeException {}
add_filter( 'wp_redirect', static function ( $url ) { throw new FA_Redirect( $url ); } );
add_filter( 'wp_die_handler', static function () { return static function () { throw new FA_Die( 'denied' ); }; } );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
delete_option( MLAB_SMTP_OPTION );
delete_option( MLAB_SMTP_READINESS_OPTION );
mlab_smtp_store( array( 'provider' => 'smtp', 'smtp_host' => 'primary.example.test', 'smtp_user' => 'primary@example.test', 'smtp_pass' => 'primary-secret', 'from_email' => 'primary@example.test', 'fallback_enabled' => 1, 'fb_provider' => 'smtp', 'fb_smtp_host' => 'backup.example.test', 'fb_smtp_user' => 'backup@example.test', 'fb_smtp_pass' => 'backup-secret', 'fb_resend_webhook_secret' => 'unused-backup-signing-secret' ) );
mlab_smtp_readiness_record( 'primary', 'test', true, 'primary@example.test' );
mlab_smtp_readiness_record( 'fallback', 'test', true, 'backup@example.test' );
$primary = array_filter( (array) get_option( MLAB_SMTP_OPTION ), static fn( $key ) => ! str_starts_with( $key, 'fb_' ) && 'fallback_enabled' !== $key, ARRAY_FILTER_USE_KEY );
$primary_readiness = mlab_smtp_readiness_get()['primary'];
$message = mlab_t_msg( array( 'status' => 'accepted' ) );
assert_same( true, mlab_smtp_manage_fallback( false ), 'backup can be disabled' );
assert_same( 0, (int) mlab_smtp_get( 'fallback_enabled' ), 'disable switches the backup off' );
assert_same( 'backup-secret', mlab_smtp_get( 'fb_smtp_pass' ), 'disable retains backup credentials' );
assert_true( isset( mlab_smtp_readiness_get()['fallback'] ), 'disable retains readiness' );
$block = static fn( $new, $old ) => $old;
add_filter( 'pre_update_option_' . MLAB_SMTP_OPTION, $block, 10, 2 );
assert_true( is_wp_error( mlab_smtp_manage_fallback( true ) ), 'a failed options write is not reported as removal' );
remove_filter( 'pre_update_option_' . MLAB_SMTP_OPTION, $block, 10 );
assert_same( 'backup-secret', mlab_smtp_get( 'fb_smtp_pass' ), 'failed removal keeps the original credentials' );
add_filter( 'pre_update_option_' . MLAB_SMTP_READINESS_OPTION, $block, 10, 2 );
assert_true( is_wp_error( mlab_smtp_manage_fallback( true ) ), 'a failed readiness cleanup reports a retryable error' );
remove_filter( 'pre_update_option_' . MLAB_SMTP_READINESS_OPTION, $block, 10 );
assert_same( true, mlab_smtp_manage_fallback( true ), 'removal can be retried after partial cleanup' );
$raw = (array) get_option( MLAB_SMTP_OPTION );
assert_same( $primary, $raw, 'primary SMTP settings and encrypted credentials are unchanged' );
assert_true( ! isset( mlab_smtp_readiness_get()['fallback'] ), 'backup readiness is removed' );
assert_same( $primary_readiness, mlab_smtp_readiness_get()['primary'], 'primary readiness is unchanged' );
assert_true( null !== mlab_smtp_store_get( $message ), 'delivery history is retained' );
assert_same( true, mlab_smtp_manage_fallback( true ), 'removal is idempotent' );

foreach ( array( 'disable', 'remove' ) as $action ) {
	$_REQUEST = $_POST = array();
	$denied = false;
	try { call_user_func( 'mlab_smtp_handle_fallback_' . $action ); } catch ( FA_Die $e ) { $denied = true; }
	assert_true( $denied, "$action requires its own nonce" );
	$subscriber = wp_insert_user( array( 'user_login' => 'fa_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	wp_set_current_user( $subscriber );
	$_REQUEST = $_POST = array( '_wpnonce' => wp_create_nonce( 'mlab_smtp_fallback_' . $action ) );
	$denied = false;
	try { call_user_func( 'mlab_smtp_handle_fallback_' . $action ); } catch ( FA_Die $e ) { $denied = true; }
	assert_true( $denied, "$action requires management permission even with a nonce" );
	wp_set_current_user( $admins[0]->ID );
	$_REQUEST = $_POST = array( '_wpnonce' => wp_create_nonce( 'mlab_smtp_fallback_' . $action ) );
	$url = '';
	try { call_user_func( 'mlab_smtp_handle_fallback_' . $action ); } catch ( FA_Redirect $e ) { $url = $e->getMessage(); }
	assert_contains( 'feedback=fallback', $url, "$action returns to local backup feedback" );
}
define( 'MLAB_SMTP_FB_SMTP_HOST', 'locked.example.test' );
assert_true( is_wp_error( mlab_smtp_manage_fallback( true ) ), 'constant-controlled backup credentials cannot be falsely reported as deleted' );
define( 'MLAB_SMTP_FALLBACK_ENABLED', 1 );
assert_true( is_wp_error( mlab_smtp_manage_fallback( false ) ), 'a constant-controlled backup cannot be falsely reported as disabled' );
mlab_t_reset_store();
t_finish();
