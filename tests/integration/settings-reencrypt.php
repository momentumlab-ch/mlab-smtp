<?php
// Neu verschlüsseln: lesbare Secrets mit aktuellem Schlüssel, unlesbare unberührt, kein neuer Schlüssel, idempotent, Handler.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/store-helpers.php';
require dirname( __DIR__ ) . '/lib/settings-helpers.php';

delete_option( MLAB_SMTP_OPTION );
delete_option( MLAB_SMTP_ALERTS_OPTION );
mlab_smtp_store( array( 'provider' => 'resend', 'setup_done' => 1, 'from_email' => 'info@example.ch', 'resend_api_key' => 're_main_key_1234567890', 'smtp_pass' => 'smtp-passwort-xyz-987654', 'fb_smtp_pass' => 'fb-passwort-abcdef-1234' ) );
mlab_smtp_alerts_save( array( 'url' => 'https://hook.example.test/a', 'secret' => 'alert-signing-secret-777', 'events' => array() ) );

// Vorbereiten: ein Secret im alten Format (ohne Kennung), eines unlesbar (fremde Kennung), eines beschädigt.
$opts                   = get_option( MLAB_SMTP_OPTION );
$nonce                  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
$opts['resend_api_key'] = 'enc:' . base64_encode( $nonce . sodium_crypto_secretbox( 're_main_key_1234567890', $nonce, mlab_smtp_salt_key() ) );
$opts['smtp_pass']      = 'enc2:deadbeef:' . base64_encode( random_bytes( 40 ) );
$opts['fb_smtp_pass']   = 'enc2:' . mlab_smtp_key_id( mlab_smtp_key() ) . ':' . base64_encode( random_bytes( 40 ) );
update_option( MLAB_SMTP_OPTION, $opts, false );
$before_unreadable = array( $opts['smtp_pass'], $opts['fb_smtp_pass'] );

$status = array_column( mlab_smtp_secret_inventory(), 'status', 'id' );
assert_same( 'old_key', $status['resend_api_key'], 'legacy format needs re-encryption' );
assert_same( 'key_changed', $status['smtp_pass'], 'foreign key id' );
assert_same( 'damaged', $status['fb_smtp_pass'], 'damaged with current key id' );
assert_same( 'ok', $status['alert_secret'], 'alert secret current' );

$result = mlab_smtp_reencrypt_secrets();
assert_same( 1, $result['reencrypted'], 'one re-encrypted' );
assert_same( 1, $result['current'], 'one already current' );
assert_same( 2, $result['unreadable'], 'two unreadable counted' );
assert_true( in_array( 'smtp_pass', $result['ids'], true ) && in_array( 'fb_smtp_pass', $result['ids'], true ), 'unreadable ids reported' );
$after = get_option( MLAB_SMTP_OPTION );
assert_true( str_starts_with( $after['resend_api_key'], 'enc2:' . mlab_smtp_key_id( mlab_smtp_key() ) . ':' ), 'stored with current key id' );
assert_same( 're_main_key_1234567890', mlab_smtp_get( 'resend_api_key' ), 'value survives' );
assert_same( $before_unreadable, array( $after['smtp_pass'], $after['fb_smtp_pass'] ), 'unreadable untouched' );

$snapshot = get_option( MLAB_SMTP_OPTION );
$again    = mlab_smtp_reencrypt_secrets();
assert_same( 0, $again['reencrypted'], 'idempotent' );
assert_same( $snapshot, get_option( MLAB_SMTP_OPTION ), 'nothing rewritten on second run' );

// Schlüssel der Konstanten: alte Salt-Secrets werden lesbar neu verschlüsselt, ohne dass ein Schlüssel erzeugt wird.
assert_true( false === get_option( 'mlab_smtp_key' ) && false === get_option( 'mlab_smtp_generated_key' ), 'no key option created' );

// Handler.
st_admin();
assert_same( 'die:403', st_call( 'mlab_smtp_handle_reencrypt', null ), 'no nonce rejected' );
wp_set_current_user( 0 );
assert_same( 'die:403', st_call( 'mlab_smtp_handle_reencrypt', 'mlab_smtp_reencrypt' ), 'anonymous rejected' );
$sub = wp_insert_user( array( 'user_login' => 'st_re_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $sub );
assert_same( 'die:403', st_call( 'mlab_smtp_handle_reencrypt', 'mlab_smtp_reencrypt' ), 'subscriber rejected' );
st_admin();
assert_true( str_starts_with( st_call( 'mlab_smtp_handle_reencrypt', 'mlab_smtp_reencrypt' ), 'redirect:' ), 'admin redirects back' );
$flash = get_transient( 'mlab_smtp_flash_' . get_current_user_id() );
assert_true( is_array( $flash ) && 'warning' === $flash['type'] && str_contains( $flash['message'], '2 sind nicht lesbar' ), 'flash reports unreadable count' );

// Seite zeigt unlesbare mit Grund und Link.
$html = st_render();
assert_true( str_contains( $html, 'Der Schlüssel hat sich geändert.' ) && str_contains( $html, 'Die gespeicherten Daten sind beschädigt.' ) && str_contains( $html, 'Bitte neu eingeben.' ), 'reasons shown' );
assert_true( str_contains( $html, 'SMTP: Passwort' ) && str_contains( $html, 'step=2' ), 'label and wizard link' );
assert_true( str_contains( $html, '2 lesbar, 2 nicht lesbar' ), 'counts on page' );
assert_true( str_contains( $html, 'MLAB_SMTP_KEY' ) && str_contains( $html, 'upgrade-and-recovery.md' ), 'key help' );

// Aufräumen: die Testdatenbank wird von anderen Tests geteilt.
delete_option( MLAB_SMTP_STAGING_OPTION );
delete_option( MLAB_SMTP_ALERTS_OPTION );
delete_option( MLAB_SMTP_ALERTS_STATE );
delete_option( MLAB_SMTP_OPTION );

t_finish();
