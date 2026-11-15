<?php
error_reporting( E_ALL );
define( 'ABSPATH', '/x/' ); define( 'MLAB_SMTP_SCREEN', 'toplevel_page_mlab-smtp' ); define( 'MLAB_SMTP_FILE', '/x/m.php' );
function add_action() {} function add_filter() {} function __( $s ) { return $s; } function plugin_basename() { return 'x'; }
function add_menu_page() { $GLOBALS['menu'] = func_get_args(); } function admin_url( $p = '' ) { return 'https://s/wp-admin/' . $p; }
function add_query_arg( $a, $u ) { return $u . '?' . http_build_query( $a ); }
function sanitize_text_field( $s ) { return $s; } function wp_unslash( $s ) { return $s; }
function wp_safe_redirect( $u ) { $GLOBALS['redirect'] = $u; throw new Exception( 'exit' ); }
$m = file_get_contents( dirname( __DIR__, 2 ) . '/inc/admin/page.php' ); // load only the functions we test
foreach ( array( 'components', 'handlers', 'rest', 'overview', 'wizard', 'resend-view', 'log-view', 'settings-view', 'foreign-notices' ) as $f ) {}
$src = preg_replace( '/foreach \( array\( .components.*?unset\( \$mlab_smtp_admin_file \);/s', '', $m );
eval( '?>' . $src );
$fail = 0; function ok( $c, $l ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $l . "\n"; if ( ! $c ) { $fail++; } }
mlab_smtp_add_menu();
$a = $GLOBALS['menu'];
ok( 'mlab-smtp' === $a[3] && 'manage_options' === $a[2] && 'E-Mail-Versand' === $a[0], 'top-level menu: slug, capability, title' );
ok( str_starts_with( $a[5], 'data:image/svg+xml;base64,' ) && str_contains( base64_decode( substr( $a[5], 26 ) ), '<svg' ), 'icon is an inline SVG' );
ok( 76 === $a[6], 'position 76 (below Tools, above Settings)' );
ok( 'https://s/wp-admin/admin.php?page=mlab-smtp&view=wizard&step=2' === mlab_smtp_page_url( array( 'view' => 'wizard', 'step' => 2 ) ), 'page URL points to admin.php' );
$GLOBALS['pagenow'] = 'options-general.php'; $_GET = array( 'page' => 'mlab-smtp', 'view' => 'log' );
try { mlab_smtp_redirect_old_url(); } catch ( Exception $e ) {}
ok( 'https://s/wp-admin/admin.php?page=mlab-smtp&view=log' === ( $GLOBALS['redirect'] ?? '' ), 'old Settings URL redirects and keeps the view' );
unset( $GLOBALS['redirect'] ); $GLOBALS['pagenow'] = 'options-general.php'; $_GET = array( 'page' => 'other' );
mlab_smtp_redirect_old_url();
ok( ! isset( $GLOBALS['redirect'] ), 'other Settings pages are not touched' );
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n"; exit( $fail ? 1 : 0 );
