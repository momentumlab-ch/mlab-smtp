<?php
// Supportbericht: keine Secrets, maskierte Adressen, kein Betreff, Download mit Nonce und Berechtigung, Konstanten nur als Namen.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/store-helpers.php';

class SR_Die extends RuntimeException {}
add_filter( 'wp_die_handler', static function () {
	return static function ( $message, $title = '', $args = array() ) {
		throw new SR_Die( (string) ( is_array( $args ) ? ( $args['response'] ?? 0 ) : 0 ) );
	};
} );
add_filter( 'mlab_smtp_resolve_host', static fn( $pre, $host ) => 'hook.example.test' === $host ? array( '93.184.216.34' ) : $pre, 10, 2 );

define( 'MLAB_SMTP_KEY', 'konstanten-wert-darf-nie-erscheinen-0123456789' );
$secrets = array(
	'resend_api_key'        => 're_main_key_1234567890',
	'resend_webhook_secret' => 'whsec_' . base64_encode( str_repeat( 'a', 24 ) ),
	'm365_client_secret'    => 'm365-secret-abcdef123456',
	'smtp_pass'             => 'smtp-passwort-xyz-987654',
);
delete_option( MLAB_SMTP_OPTION );
mlab_t_reset_store();
mlab_smtp_store( array_merge( array( 'provider' => 'm365', 'setup_done' => 1, 'from_email' => 'info@example.ch', 'm365_tenant' => 't', 'm365_client_id' => 'c' ), $secrets ) );
assert_same( array(), mlab_smtp_alerts_save( array( 'url' => 'https://hook.example.test/a', 'secret' => 'alert-signing-secret-777', 'events' => array( 'primary_failing' => 1 ) ) ), 'alert settings stored' );
mlab_t_msg( array( 'recipients' => array( 'kunde.privat@beispiel-firma.ch' ), 'subject' => 'Rechnung 4711 Frau Muster', 'status' => 'failed', 'last_error' => 'Server sagte nein zu kunde.privat@beispiel-firma.ch mit Schluessel re_main_key_1234567890' ) );

$report = mlab_smtp_support_report();
foreach ( array_merge( array_values( $secrets ), array( 'alert-signing-secret-777', 'konstanten-wert-darf-nie-erscheinen-0123456789' ) ) as $secret ) {
	assert_true( ! str_contains( $report, $secret ), 'report does not contain a stored secret' );
}
assert_true( ! str_contains( $report, 'kunde.privat@beispiel-firma.ch' ), 'address not in clear text' );
assert_true( str_contains( $report, mlab_smtp_mask_email( 'kunde.privat@beispiel-firma.ch' ) ), 'address masked' );
assert_true( ! str_contains( $report, 'Rechnung 4711' ) && ! str_contains( $report, 'Frau Muster' ), 'subject absent' );
assert_true( str_contains( $report, 'Betreff: 25 Zeichen' ), 'subject length only' );
foreach ( array( 'Plugin: ' . MLAB_SMTP_VERSION, 'WordPress: ', 'PHP: ' . PHP_VERSION, 'Umgebungstyp:', 'Multisite: nein', 'Hauptweg: m365', 'Ersatzweg: aus', 'Schema-Version:', 'DISABLE_WP_CRON:', 'failed: 1', 'Letzte 20 Protokolleinträge', 'Gesetzte Konstanten' ) as $part ) {
	assert_true( str_contains( $report, $part ), "report contains $part" );
}
assert_true( str_contains( $report, 'MLAB_SMTP_KEY' ), 'constant listed by name' );
assert_true( str_contains( $report, 'Alarm-Webhook eingetragen: ja' ), 'yes/no settings' );

// Extension point.
add_filter( 'mlab_smtp_support_report_sections', static function ( $sections ) {
	$sections['Pro'] = array( 'Zusatz: ok', 'Schluessel re_main_key_1234567890' );
	return $sections;
} );
$report = mlab_smtp_support_report();
assert_true( str_contains( $report, 'Zusatz: ok' ), 'section added by filter' );
assert_true( ! str_contains( $report, 're_main_key_1234567890' ), 'final redaction also covers added sections' );
remove_all_filters( 'mlab_smtp_support_report_sections' );

// Download handler.
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
function sr_call( ?string $nonce_action ): string {
	$_REQUEST = $_POST = array();
	if ( $nonce_action ) {
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'] = wp_create_nonce( $nonce_action );
	}
	try {
		mlab_smtp_support_report_download();
	} catch ( SR_Die $e ) {
		return 'die:' . $e->getMessage();
	}
	return 'none';
}
wp_set_current_user( 0 );
assert_same( 'die:403', sr_call( 'mlab_smtp_support_report' ), 'anonymous rejected' );
$sub = wp_insert_user( array( 'user_login' => 'sr_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $sub );
assert_same( 'die:403', sr_call( 'mlab_smtp_support_report' ), 'subscriber rejected' );
wp_set_current_user( $admins[0]->ID );
assert_same( 'die:403', sr_call( null ), 'admin without nonce rejected' );
assert_true( has_action( 'admin_post_mlab_smtp_support_report', 'mlab_smtp_support_report_download' ) !== false, 'download handler registered' );
$src = (string) file_get_contents( MLAB_SMTP_DIR . 'inc/support-report.php' );
assert_true( str_contains( $src, 'Content-Disposition: attachment' ) && ! str_contains( $src, 'file_put_contents' ), 'attachment header and nothing written to disk' );

t_finish();
