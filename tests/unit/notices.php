<?php
// Test of the notice capture with the real WP_HTML_Tag_Processor from WordPress core.
error_reporting( E_ALL );
define( 'MLAB_SMTP_SCREEN', 'toplevel_page_mlab-smtp' ); define( 'ABSPATH', '/x/' );
$core = dirname( __DIR__ ) . '/env/wordpress/wp-includes/';
if ( ! is_file( $core . 'html-api/class-wp-html-tag-processor.php' ) ) { echo "SKIP notices: WordPress-Core fehlt (tests/env/setup.sh ausfuehren)\n"; exit( 0 ); }
function __( $s ) { return $s; } function _n( $a, $b, $n ) { return 1 === $n ? $a : $b; } function esc_html( $s ) { return htmlspecialchars( (string) $s ); } function esc_attr( $s ) { return esc_html( $s ); }
function esc_html__( $s ) { return esc_html( $s ); } function add_action( $h, $cb, $p = 10 ) { $GLOBALS['hooks'][ $h ][ $p ][] = $cb; }
function do_action( $h ) { $p = $GLOBALS['hooks'][ $h ] ?? array(); ksort( $p ); foreach ( $p as $cbs ) { foreach ( $cbs as $cb ) { $cb(); } } }
function wp_has_noncharacters() { return false; } function wp_kses_uri_attributes() { return array(); } function is_utf8_string() { return true; } function wp_kses_normalize_entities( $s ) { return $s; }
foreach ( array( 'html-api/class-wp-html-attribute-token.php', 'html-api/class-wp-html-span.php', 'html-api/class-wp-html-text-replacement.php', 'html-api/class-wp-html-decoder.php', 'html-api/class-wp-html-tag-processor.php' ) as $f ) { @require_once $core . $f; }
require dirname( __DIR__, 2 ) . '/inc/admin/foreign-notices.php';
$fail = 0; function ok( $c, $l ) { global $fail; echo ( $c ? 'PASS ' : 'FAIL ' ) . $l . "\n"; if ( ! $c ) { $fail++; } }

// A page request: our capture is registered, other plugins print notices in between.
mlab_smtp_capture_foreign_notices();
add_action( 'admin_notices', function () { echo '<div class="notice notice-error is-dismissible"><p>Plugin A: Lizenz abgelaufen</p></div>'; } );
add_action( 'admin_notices', function () { echo '<div class="updated"><p>Plugin B: gespeichert</p></div><div class="error"><p>Plugin B: Fehler</p></div>'; }, 5 );
add_action( 'all_admin_notices', function () { echo '<div class="notice notice-warning"><p>Plugin C</p></div>'; }, 20 );
add_action( 'admin_notices', function () { ob_start(); echo '<div class="notice"><p>Plugin D mit offenem Puffer</p></div>'; } ); // never closed
ob_start();
echo 'HEAD|';
do_action( 'admin_notices' ); do_action( 'all_admin_notices' );
echo '|PAGE|';
mlab_smtp_render_foreign_notices();
$out = ob_get_clean();
[ $head, $rest ] = explode( '|PAGE|', $out, 2 );
ok( 'HEAD|' === $head, 'nothing of other plugins is printed in the page header (they would land inside the layout)' );
ok( str_contains( $rest, 'Plugin A' ) && str_contains( $rest, 'Plugin B: gespeichert' ) && str_contains( $rest, 'Plugin B: Fehler' ) && str_contains( $rest, 'Plugin C' ) && str_contains( $rest, 'Plugin D' ), 'all notices survive, in the bar below the page' );
ok( str_contains( $rest, '<aside class="mlab-foreign"' ) && str_contains( $rest, '<details>' ) && ! str_contains( $rest, '<details open' ), 'bar is collapsed' );
ok( 5 === preg_match_all( '/class="[^"]*\binline\b/', $rest ), 'every notice got the class "inline" (core JS then leaves it in place)' );
ok( str_contains( $rest, '<span class="mlab-foreign-count">5</span>' ), 'count in the summary: 5' );
ok( str_contains( $rest, '2 Fehler' ), 'error badge counts notice-error and error' );
ok( str_contains( $rest, 'is-dismissible' ), 'dismissible stays dismissible' );

// No notices: no bar at all.
$r = new ReflectionFunction( 'mlab_smtp_foreign_buffer' );
ok( true, 'buffer function present' );
$out = (function () { ob_start(); $prep = mlab_smtp_prepare_foreign_notices( '<p>Text ohne Hinweis</p>' ); ob_end_clean(); return $prep; })();
ok( 0 === $out['count'] && '<p>Text ohne Hinweis</p>' === $out['html'], 'plain markup is passed through unchanged, count 0' );
$out = mlab_smtp_prepare_foreign_notices( '<div class="notice"><div class="notice-inner">x</div></div><form class="error-box"></form>' );
ok( 1 === $out['count'], 'only whole notices are counted, not errorish class names' );
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n"; exit( $fail ? 1 : 0 );
