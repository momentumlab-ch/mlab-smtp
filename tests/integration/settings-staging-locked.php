<?php
// Staging per Konstante festgelegt: Feld gesperrt, Hinweis, keine Bestätigung.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/store-helpers.php';
require dirname( __DIR__ ) . '/lib/settings-helpers.php';

delete_option( MLAB_SMTP_STAGING_OPTION );
define( 'MLAB_SMTP_STAGING', 'block' );
st_admin();
$html = st_render();
assert_true( preg_match( '/<select id="mlab-smtp-staging_mode" name="staging_mode" disabled>/', $html ) === 1, 'mode select disabled' );
assert_true( str_contains( $html, 'Gesetzt in wp-config.php' ) && str_contains( $html, 'MLAB_SMTP_STAGING' ), 'lock note' );
assert_true( preg_match( '/<option value="block" selected=\'selected\'>Blockieren/', $html ) === 1, 'constant mode preselected' );
assert_true( ! str_contains( $html, 'Das ist die produktive Website' ), 'no confirm button under a constant' );
// Ein gesperrtes Feld wird nicht gesendet: der gespeicherte Modus bleibt.
assert_same( array(), mlab_smtp_save_security_forms( array( 'staging_form' => '1', 'staging_test_email' => 'x@example.ch' ) ), 'save without mode field works' );
assert_same( 'auto', mlab_smtp_staging_get()['mode'], 'stored mode unchanged' );
// Aufräumen: die Testdatenbank wird von anderen Tests geteilt.
delete_option( MLAB_SMTP_STAGING_OPTION );
delete_option( MLAB_SMTP_ALERTS_OPTION );
delete_option( MLAB_SMTP_ALERTS_STATE );
delete_option( MLAB_SMTP_OPTION );

t_finish();
