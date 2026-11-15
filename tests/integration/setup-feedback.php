<?php
// Setup feedback stays inside its active card, and SMTP hints exist without JavaScript.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/ui-helpers.php';
ui_admin();
ui_reset( array( 'provider' => 'smtp', 'smtp_host' => 'main.example.test', 'fb_smtp_secure' => 'ssl', 'fb_smtp_port' => 587 ) );
foreach ( array( 'fallback' => 3, 'wizard' => 2 ) as $view => $step ) {
	$_GET = array( 'step' => (string) $step, 'provider' => 'smtp' );
	mlab_smtp_flash( 'error', 'Connection <script>bad</script>', 'Technical <script>detail</script>' );
	ob_start();
	call_user_func( 'fallback' === $view ? 'mlab_smtp_render_fallback_flow' : 'mlab_smtp_render_wizard' );
	$html = ob_get_clean();
	assert_true( strpos( $html, 'data-mlab-step-feedback' ) > strpos( $html, 'mlab-card' ), "$view feedback is inside the active card" );
	assert_contains( 'role="alert"', $html, "$view errors are announced as alerts" );
	assert_true( ! str_contains( $html, 'notice-error' ), "$view feedback is not a relocatable WordPress notice" );
	assert_contains( '&lt;script&gt;bad&lt;/script&gt;', $html, "$view message is escaped" );
	assert_contains( '&lt;script&gt;detail&lt;/script&gt;', $html, "$view technical detail is escaped" );
	assert_same( false, get_transient( 'mlab_smtp_flash_' . get_current_user_id() ), "$view consumes its feedback once" );
}
ob_start();
mlab_smtp_render_fallback_smtp();
$html = ob_get_clean();
assert_contains( 'data-mlab-port-hint="fb_smtp_secure"', $html, 'backup port hint is present without JavaScript' );
assert_contains( 'Üblicher Port: 465.', $html, 'SSL recommends port 465 even when an existing port is mismatched' );
assert_contains( 'value="587"', $html, 'loading a form does not silently rewrite an existing port' );
mlab_smtp_flash( 'success', 'Saved' );
ob_start(); mlab_smtp_render_flash( true ); $html = ob_get_clean();
assert_contains( 'role="status"', $html, 'success feedback is a status rather than an alert' );
unset( $_GET['step'], $_GET['provider'] );
t_finish();
