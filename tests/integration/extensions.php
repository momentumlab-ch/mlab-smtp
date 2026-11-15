<?php
// Erweiterungspunkte für spätere Zusatzplugins: Anbieter, Berechtigung, Änderungsereignis. Keine Lizenzabfrage im Kernpfad.
require dirname( __DIR__ ) . '/bootstrap-wp.php';

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
mlab_smtp_staging_put( array( 'mode' => 'off' ) );

// ---- 1. Berechtigung
assert_same( 'manage_options', mlab_smtp_capability(), 'default capability' );
add_filter( 'mlab_smtp_capability', static fn() => array( 'kein', 'String' ), 10 );
assert_same( 'manage_options', mlab_smtp_capability(), 'an invalid filter result falls back to manage_options' );
remove_all_filters( 'mlab_smtp_capability' );
add_filter( 'mlab_smtp_capability', static fn() => 'mlab_manage_mail' );
assert_same( 'mlab_manage_mail', mlab_smtp_capability(), 'filter narrows the capability' );
do_action( 'rest_api_init' );
$res = rest_do_request( new WP_REST_Request( 'POST', '/mlab-smtp/v1/check' ) );
assert_true( in_array( $res->get_status(), array( 401, 403 ), true ), 'an administrator without the custom capability is refused by REST' );
$user = wp_get_current_user();
$user->add_cap( 'mlab_manage_mail' );
mlab_http_mock( '#api\.resend\.com#', 200, array( 'data' => array() ) );
$res = rest_do_request( new WP_REST_Request( 'POST', '/mlab-smtp/v1/check' ) );
assert_same( 200, $res->get_status(), 'a user with the custom capability is allowed by REST' );
$user->remove_cap( 'mlab_manage_mail' );
assert_true( ! current_user_can( mlab_smtp_capability() ), 'capability removed again' );
remove_all_filters( 'mlab_smtp_capability' );

// ---- 2. Anbieter ergänzen
function ext_check() { return true; }
function ext_send( $mailer ) { return true; }
$def = array( 'api' => true, 'required' => array( 'ext_key' ), 'group' => array( 'ext_key' ), 'secrets' => array(), 'credentials' => array(), 'token' => null, 'check' => 'ext_check', 'send' => 'ext_send', 'spf' => '', 'health' => array(), 'sender_block' => null, 'fb_required' => null, 'fb_check' => null, 'fb_send' => null );
add_filter( 'mlab_smtp_providers', static function () use ( $def ) {
	return array(
		'extprov' => $def,
		'smtp'    => array_merge( $def, array( 'api' => true ) ), // darf den eingebauten Weg nicht ersetzen
		'halb'    => array( 'api' => true ),                       // unvollständig
		''        => $def,
	);
} );
$defs = mlab_smtp_provider_defs( true );
assert_true( isset( $defs['extprov'] ), 'a complete provider from the filter is added' );
assert_true( ! isset( $defs['halb'] ) && ! isset( $defs[''] ), 'incomplete and nameless providers are ignored' );
assert_true( false === $defs['smtp']['api'], 'a built-in provider cannot be replaced' );
assert_true( in_array( 'extprov', mlab_smtp_choices()['provider'], true ), 'the new provider is a valid choice' );
assert_same( array( 'ext_key' ), mlab_smtp_required_keys( 'extprov' ), 'required keys come from the registry' );
assert_true( ! in_array( 'extprov', mlab_smtp_choices()['fb_provider'], true ), 'not usable as backup route without fb fields' );
assert_same( array( 'm365', 'google', 'resend', 'smtp' ), array_slice( array_keys( $defs ), 0, 4 ), 'built-in order is kept' );
// UI-Daten
add_filter( 'mlab_smtp_ui_providers', static fn() => array( 'extprov' => array( 'name' => 'Zusatz', 'color' => '#123456', 'letter' => 'Z', 'card' => array( 'text' => 't', 'bullets' => array() ), 'connect' => 'ext_check', 'success' => 'ok', 'sender' => array( 'fixed' => true ), 'halb' => 1 ), 'kaputt' => array( 'name' => 'x' ) ) );
$ui = mlab_smtp_ui_providers( true );
assert_true( isset( $ui['extprov'] ) && ! isset( $ui['kaputt'] ), 'complete UI data is added, incomplete ignored' );
assert_true( $ui['extprov']['sender']['fixed'] && array_key_exists( 'help', $ui['extprov']['sender'] ), 'sender defaults are filled in' );
remove_all_filters( 'mlab_smtp_providers' );
remove_all_filters( 'mlab_smtp_ui_providers' );
mlab_smtp_provider_defs( true );
mlab_smtp_ui_providers( true );

// ---- 3. Änderungsereignis: nur Namen, nie Werte
$seen = array();
add_action( 'mlab_smtp_settings_changed', static function ( $names, $user_id ) use ( &$seen ) {
	$seen[] = array( $names, $user_id );
}, 10, 2 );
delete_option( MLAB_SMTP_OPTION );
mlab_smtp_store( array( 'from_name' => 'Firma', 'resend_api_key' => 're_geheimgeheim12345' ) );
assert_same( 1, count( $seen ), 'one event for one save' );
assert_same( array( 'from_name', 'resend_api_key' ), $seen[0][0], 'event lists the names of the changed settings' );
assert_true( ! str_contains( wp_json_encode( $seen ), 'geheim' ) && ! str_contains( wp_json_encode( $seen ), 'Firma' ), 'the event never contains values' );
assert_same( $admins[0]->ID, $seen[0][1], 'event carries the user id' );
mlab_smtp_store( array( 'from_name' => 'Firma', 'resend_api_key' => 're_geheimgeheim12345' ) );
assert_same( 1, count( $seen ), 'saving identical values fires nothing' );
mlab_smtp_store( array( 'from_name' => 'Neu' ) );
assert_same( array( 'from_name' ), $seen[1][0] ?? array(), 'only the changed setting is named' );

// ---- 4. Kein Lizenz-Haken im Kernpfad: Versand läuft ohne jede Zusatzerweiterung.
mlab_smtp_store( array( 'provider' => 'resend', 'setup_done' => 1, 'from_email' => 'noreply@beispiel.ch' ) );
mlab_http_reset();
mlab_http_mock( '#api\.resend\.com/emails#', 200, array( 'id' => 'ext-1' ) );
assert_true( true === wp_mail( 'user@example.test', 'Betreff', 'Text' ), 'sending works with no extension and no licence code' );
assert_true( 0 === preg_match( '/licen[cs]e|lizenz/i', (string) file_get_contents( dirname( __DIR__, 2 ) . '/inc/engine-send.php' ) . (string) file_get_contents( dirname( __DIR__, 2 ) . '/inc/engine-worker.php' ) . (string) file_get_contents( dirname( __DIR__, 2 ) . '/inc/class-mlab-smtp-mailer.php' ) ), 'the send path does not mention a licence' );
t_finish();
