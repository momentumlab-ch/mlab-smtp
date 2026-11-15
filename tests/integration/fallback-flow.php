<?php
// Ersatzweg-Ablauf: Testmail nur über den Ersatzweg, Berechtigung und Nonce, Key-Trennung, Sending-only, SMTP-Pfad.
require dirname( __DIR__ ) . '/bootstrap-wp.php';

const FF_MAIN_KEY = 're_main_key_1234567890';
const FF_FB_KEY   = 're_fallback_key_1234567890';

class FF_Redirect extends RuntimeException {}
class FF_Die extends RuntimeException {}

add_filter( 'wp_redirect', static function ( $location ) {
	throw new FF_Redirect( (string) $location );
} );
add_filter( 'wp_die_handler', static function () {
	return static function ( $message, $title = '', $args = array() ) {
		throw new FF_Die( (string) ( is_array( $args ) ? ( $args['response'] ?? 0 ) : 0 ) );
	};
} );

function ff_reset( array $extra = array() ): void {
	delete_option( MLAB_SMTP_OPTION );
	delete_option( MLAB_SMTP_READINESS_OPTION );
	mlab_http_reset();
	mlab_smtp_store( array_merge( array( 'provider' => 'm365', 'setup_done' => 1, 'from_email' => 'info@example.ch', 'from_name' => 'Firma', 'm365_tenant' => 't', 'm365_client_id' => 'c', 'm365_client_secret' => 'secret-1234567890', 'fallback_enabled' => 0, 'fb_provider' => 'resend', 'fb_resend_api_key' => FF_FB_KEY, 'resend_api_key' => FF_MAIN_KEY ), $extra ) );
	delete_transient( 'mlab_smtp_flash_' . get_current_user_id() );
}

function ff_post( array $post, ?string $nonce_action = 'mlab_smtp_fallback' ): string {
	$_POST    = $post;
	$_REQUEST = $post;
	if ( $nonce_action ) {
		$_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = wp_create_nonce( $nonce_action );
	}
	try {
		mlab_smtp_handle_fallback();
	} catch ( FF_Redirect $e ) {
		return $e->getMessage();
	} catch ( FF_Die $e ) {
		return 'die:' . $e->getMessage();
	}
	return 'none';
}

function ff_urls( array $requests ): array {
	return array_map( static fn( array $r ): string => $r['method'] . ' ' . $r['url'], $requests );
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );

// 1. Berechtigung und Nonce: jede Anfrage ohne beides wird abgewiesen, nichts wird gespeichert.
ff_reset();
assert_same( 'die:403', ff_post( array( 'step' => 7, 'do' => 'activate' ), null ), 'a request without nonce is rejected' );
assert_same( 0, (int) mlab_smtp_get( 'fallback_enabled' ), 'nothing was changed without a nonce' );
assert_same( 'die:403', ff_post( array( 'step' => 1, 'provider' => 'smtp' ), 'wrong_action' ), 'a request with a foreign nonce is rejected' );
$subscriber = wp_insert_user( array( 'user_login' => 'ff_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $subscriber );
assert_same( 'die:403', ff_post( array( 'step' => 7, 'do' => 'activate' ) ), 'a user without manage_options is rejected even with a valid nonce' );
assert_same( 0, (int) mlab_smtp_get( 'fallback_enabled' ), 'a subscriber cannot switch the fallback on' );
wp_set_current_user( $admins[0]->ID );

// 2. Anbieterwahl: Resend ist als Ersatzweg nicht wählbar, wenn es der Hauptweg ist; M365 nie.
ff_reset( array( 'provider' => 'resend' ) );
assert_true( ! isset( mlab_smtp_fallback_options()['resend'] ) && isset( mlab_smtp_fallback_options()['smtp'] ), 'Resend is not offered as fallback when it is the main route' );
assert_true( ! isset( mlab_smtp_fallback_options()['m365'] ) && ! isset( mlab_smtp_fallback_options()['google'] ), 'Microsoft 365 and Google are never fallback options' );
ff_post( array( 'step' => 1, 'provider' => 'resend' ) );
assert_true( str_contains( (string) get_transient( 'mlab_smtp_flash_' . get_current_user_id() )['message'], 'nicht möglich' ), 'choosing Resend as fallback while it is main is refused' );
ff_reset();
assert_same( mlab_smtp_fallback_url( 2 ), ff_post( array( 'step' => 1, 'provider' => 'smtp' ) ), 'step 1 leads to step 2' );
assert_same( 'smtp', mlab_smtp_get( 'fb_provider' ), 'the provider of the fallback is stored' );
assert_same( 0, (int) mlab_smtp_get( 'fallback_enabled' ), 'choosing a provider does not switch the fallback on' );

// 3. Testmail ausschliesslich über den Ersatzweg (Hauptweg Microsoft 365 wird nie angefragt).
ff_reset();
mlab_http_mock( '#api\.resend\.com/emails#', 200, array( 'id' => 'mail-1' ) );
mlab_http_mock( '#login\.microsoftonline\.com|graph\.microsoft\.com#', 200, array( 'access_token' => 'T', 'expires_in' => 3600 ) );
$result = mlab_smtp_run_fallback_test( 'person@example.com' );
$urls   = ff_urls( mlab_http_requests() );
assert_true( $result['sent'], 'the test mail through the fallback is sent' );
assert_same( 1, count( $urls ), 'exactly one HTTP request was made' );
assert_true( str_contains( $urls[0], 'api.resend.com/emails' ), 'the request went to Resend' );
assert_same( 0, count( array_filter( $urls, static fn( string $u ): bool => str_contains( $u, 'microsoft' ) ) ), 'the main route (Microsoft 365) was never called' );
$sent_auth = mlab_http_requests()[0]['args']['headers']['Authorization'] ?? '';
assert_same( 'Bearer ' . FF_FB_KEY, $sent_auth, 'the test mail used the key of the fallback, not the main key' );
assert_same( 0, (int) mlab_smtp_get( 'fallback_enabled' ), 'the fallback stays switched off during the test' );
assert_true( mlab_smtp_route_state( 'fallback' )['getestet'], 'a successful test mail stores the test state' );
assert_true( ! mlab_smtp_route_state( 'primary' )['getestet'], 'and does not mark the main route as tested' );
assert_same( 'Firma <info@example.ch>', json_decode( mlab_http_requests()[0]['args']['body'], true )['from'] ?? '', 'the fallback sends with the stored sender' );

// 4. Fehler der Testmail nennen den Ersatzweg und stützen sich auf den Anbieter des Ersatzwegs.
ff_reset();
mlab_http_mock( '#api\.resend\.com/emails#', 403, array( 'name' => 'validation_error', 'message' => 'The example.ch domain is not verified.' ) );
$result = mlab_smtp_run_fallback_test( 'person@example.com' );
assert_true( ! $result['sent'], 'a rejected test mail is a failure' );
assert_true( str_starts_with( $result['hint'], 'Ersatzweg: ' ) && str_contains( $result['hint'], 'nicht verifiziert' ), 'the explanation names the route and the Resend domain problem' );
assert_true( ! mlab_smtp_route_state( 'fallback' )['getestet'], 'a failed test does not count' );
assert_true( ! str_contains( wp_json_encode( $result ), FF_FB_KEY ), 'the result never contains the API key' );

// 5. Key-Trennung: Domain-Status mit dem Key der jeweiligen Route.
ff_reset( array( 'provider' => 'resend' ) );
mlab_http_mock( '#api\.resend\.com/domains#', 200, array( 'data' => array( array( 'id' => 'd1', 'name' => 'example.ch', 'status' => 'verified' ) ) ) );
mlab_smtp_with_resend_key( 'fb_resend_api_key', static fn() => mlab_smtp_resend_domain_state( 'example.ch', true ) );
$fb_auth = mlab_http_requests()[0]['args']['headers']['Authorization'] ?? '';
mlab_http_reset();
mlab_http_mock( '#api\.resend\.com/domains#', 200, array( 'data' => array() ) );
mlab_smtp_resend_domain_state( 'example.ch', true );
$main_auth = mlab_http_requests()[0]['args']['headers']['Authorization'] ?? '';
assert_same( 'Bearer ' . FF_FB_KEY, $fb_auth, 'the domain status of the fallback uses the fallback key' );
assert_same( 'Bearer ' . FF_MAIN_KEY, $main_auth, 'the domain status of the main route uses the main key' );
assert_same( 'resend_api_key', mlab_smtp_resend_key_name(), 'the key context is restored after the call' );
assert_true( mlab_smtp_with_resend_key( 'fb_resend_api_key', static fn() => mlab_smtp_resend_cache_key() ) !== mlab_smtp_resend_cache_key(), 'the domain cache is separate per route' );
mlab_http_reset();
mlab_http_mock( '#api\.resend\.com/domains#', 200, array( 'data' => array() ) );
mlab_smtp_check_connection( 'fallback' );
assert_same( 'Bearer ' . FF_FB_KEY, mlab_http_requests()[0]['args']['headers']['Authorization'] ?? '', 'the connection check of the fallback uses the fallback key' );
assert_true( mlab_smtp_route_state( 'fallback' )['eingetragen'], 'fallback filled in' );

// 6. Resend mit Key «Sending access»: Prüfung gültig, Domain-Status nicht lesbar, klare Erklärung.
ff_reset();
mlab_http_mock( '#api\.resend\.com/domains#', 401, array( 'name' => 'restricted_api_key', 'message' => 'This API key is restricted to only send emails' ) );
assert_same( 'sending', mlab_smtp_fallback_resend_scope(), 'a restricted key is recognised' );
mlab_smtp_flash_fallback_check();
$flash = get_transient( 'mlab_smtp_flash_' . get_current_user_id() );
assert_same( 'success', $flash['type'], 'a sending-only key passes the check' );
assert_contains( 'Sending access', $flash['message'], 'the check explains the sending-only key' );
ob_start();
mlab_smtp_render_fb_domain();
$html = ob_get_clean();
assert_contains( 'Domain-Status nicht lesbar', $html, 'step 5 explains that the domain status cannot be read' );
assert_contains( 'Testmail', $html, 'and points to the test mail' );
assert_true( mlab_smtp_route_state( 'fallback' )['geprueft'], 'the check is stored as passed' );

// 7. SMTP-Pfad: Prüfung scheitert (kein Server), Meldung nennt den Ersatzweg; Haupt- und Ersatzweg bleiben getrennt.
ff_reset( array( 'fb_provider' => 'smtp', 'fb_smtp_host' => 'smtp.invalid', 'fb_smtp_port' => 587 ) );
$check = mlab_smtp_run_check( 'fallback' );
assert_true( ! $check['ok'] && str_starts_with( $check['message'], 'Ersatzweg: ' ), 'a failing SMTP check names the route' );
assert_true( ! mlab_smtp_route_state( 'fallback' )['geprueft'], 'a failed SMTP check does not count' );
mlab_http_reset();
$result = mlab_smtp_run_fallback_test( 'person@example.com' );
assert_true( ! $result['sent'] && str_starts_with( $result['error'], 'Ersatzweg:' ), 'the SMTP test mail failure names the Ersatzweg' );
assert_same( 0, count( mlab_http_requests() ), 'the SMTP test mail made no call to the main route' );
mlab_smtp_readiness_record( 'fallback', 'check', true );
assert_true( mlab_smtp_route_state( 'fallback' )['geprueft'], 'a stubbed passed SMTP check counts as geprueft' );
mlab_smtp_store( array( 'fb_smtp_host' => 'smtp2.invalid' ) );
assert_true( mlab_smtp_route_state( 'fallback' )['veraltet'], 'a host change makes the SMTP check stale' );
ob_start();
mlab_smtp_render_fb_domain();
assert_contains( 'Der Mailserver entscheidet', (string) ob_get_clean(), 'step 5 for SMTP says that the server decides' );

// 8. Aktivieren: nur mit aktuellem Test; setzt fallback_enabled=1.
ff_reset();
assert_same( mlab_smtp_fallback_url( 7 ), ff_post( array( 'step' => 7, 'do' => 'activate' ) ), 'activating redirects back to the summary' );
assert_same( 0, (int) mlab_smtp_get( 'fallback_enabled' ), 'activating without a test does nothing' );
mlab_smtp_readiness_record( 'fallback', 'check', true );
mlab_smtp_readiness_record( 'fallback', 'test', true, 'a@example.com' );
ff_post( array( 'step' => 7, 'do' => 'activate' ) );
assert_same( 1, (int) mlab_smtp_get( 'fallback_enabled' ), 'a tested fallback can be activated' );
assert_same( 'ok', mlab_smtp_fallback_tested_answer()['level'], 'the overview answers: tested' );

// 9. Schritte als Seiten: aria-current, ohne JavaScript, Absender sichtbar.
ff_reset();
$_GET['step'] = '4';
ob_start();
mlab_smtp_render_fallback_flow();
$html = ob_get_clean();
assert_contains( 'aria-current="step"', $html, 'the stepper marks the current step' );
assert_contains( 'info@example.ch', $html, 'step 4 shows the sender actually used' );
assert_contains( 'example.ch', $html, 'step 4 shows the domain' );
assert_true( 7 === substr_count( $html, '<li class="' ) || 7 === preg_match_all( '/<li class="[^"]*"[^>]*><span class="mlab-step-label"/', $html ), 'seven steps are listed' );
assert_true( ! str_contains( $html, 'data-mlab-check' ), 'the flow does not depend on JavaScript markers' );
$_GET['step'] = '3';
ob_start();
mlab_smtp_render_fallback_flow();
$html = ob_get_clean();
assert_contains( 'name="_wpnonce"', $html, 'a step form carries a nonce' );
assert_contains( 'value="mlab_smtp_fallback"', $html, 'a step form posts to the fallback handler' );
unset( $_GET['step'] );

// 10. Each Resend console link belongs to its numbered prerequisite, not the guide footer.
ff_reset();
ob_start();
mlab_smtp_render_fb_prerequisites();
$html = ob_get_clean();
preg_match_all( '/<li>.*?<\/li>/s', $html, $items );
assert_true( str_contains( $items[0][0] ?? '', MLAB_SMTP_RESEND_DOMAINS ), 'the domain console link is inside the domain instruction' );
assert_true( ! str_contains( $items[0][0] ?? '', MLAB_SMTP_RESEND_KEYS ), 'the API-key link is not attached to the domain instruction' );
assert_true( str_contains( $items[0][1] ?? '', MLAB_SMTP_RESEND_KEYS ), 'the API-key console link is inside the API-key instruction' );
assert_same( 1, substr_count( $html, MLAB_SMTP_RESEND_DOMAINS ), 'the domain link is not repeated below the guide' );
assert_same( 1, substr_count( $html, MLAB_SMTP_RESEND_KEYS ), 'the API-key link is not repeated below the guide' );

// 11. An untested route cannot be skipped and the summary has an actionable way forward or out.
mlab_smtp_readiness_record( 'fallback', 'check', true );
ob_start();
mlab_smtp_render_fb_test();
$html = ob_get_clean();
assert_true( ! str_contains( $html, esc_url( mlab_smtp_fallback_url( 7 ) ) ), 'step 6 does not offer a summary link before a successful test' );
ob_start();
mlab_smtp_render_fb_summary();
$html = ob_get_clean();
assert_true( ! str_contains( $html, 'value="activate"' ), 'an untested summary does not offer a dead activation button' );
assert_true( str_contains( $html, 'class="button button-primary" href="' . esc_url( mlab_smtp_fallback_url( 6 ) ) ), 'the primary action returns directly to the missing test' );
assert_contains( esc_url( mlab_smtp_page_url( array( 'view' => 'settings' ) ) ), $html, 'settings remain accessible without activating the backup' );
ob_start();
mlab_smtp_render_fb_stepper( 7 );
$html = ob_get_clean();
assert_same( 5, substr_count( $html, 'class="is-done"' ), 'an untested test-mail step is not marked complete' );
mlab_smtp_readiness_record( 'fallback', 'test', true, 'user@example.test' );
ob_start();
mlab_smtp_render_fb_test();
$html = ob_get_clean();
assert_contains( esc_url( mlab_smtp_fallback_url( 7 ) ), $html, 'a successfully tested route can continue to the summary' );
ob_start();
mlab_smtp_render_fb_summary();
$html = ob_get_clean();
assert_contains( 'value="activate"', $html, 'a current successful test exposes the activation action' );
assert_true( ! str_contains( $html, ' disabled' ), 'the activation action is enabled after a successful test' );
mlab_smtp_store( array( 'fb_resend_api_key' => 're_changed_backup_1234567890' ) );
ob_start();
mlab_smtp_render_fb_summary();
$html = ob_get_clean();
assert_true( ! str_contains( $html, 'value="activate"' ), 'changed credentials require testing again' );

delete_option( MLAB_SMTP_OPTION );
delete_option( MLAB_SMTP_READINESS_OPTION );
t_finish();
