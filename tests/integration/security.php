<?php
// Sicherheitsbausteine gegen echtes WordPress: Maskierung, sichere URLs (SSRF), Verschlüsselung, Ausgabestellen.
require dirname( __DIR__ ) . '/bootstrap-wp.php';

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
delete_option( MLAB_SMTP_OPTION );

// ---- 1. Maskierung bekannter Secrets (gespeichert) und typischer Formate.
$key  = 're_abcdefghijklmnop1234';
$pass = 'S3cr3t-Passw0rt!';
assert_true( mlab_smtp_store( array( 'provider' => 'resend', 'resend_api_key' => $key, 'smtp_pass' => $pass ) ), 'secrets stored for the test' );
$text = "Fehler: Authorization Bearer {$key} abgelehnt; Passwort {$pass} falsch";
$out  = mlab_smtp_redact( $text );
assert_true( ! str_contains( $out, $key ) && ! str_contains( $out, $pass ), 'stored secrets are removed from any text' );

$cases = array(
	'Bearer abcdef1234567890'                                 => 'abcdef1234567890',
	'key re_AAAAAAAAAAAAAAAAAA'                               => 're_AAAAAAAAAAAAAAAAAA',
	'secret whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'          => 'MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw',
	'jwt eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.c2lnbmF0dXJl' => 'eyJhbGciOiJSUzI1NiJ9',
	'refresh 1//0gAbCdEfGhIjKlMnOpQrStUvWxYz'                 => '0gAbCdEfGhIjKlMnOpQrStUvWxYz',
	'smtp://user:geheim123@mail.example.test:587'             => 'geheim123',
	'client_secret=abcd1234efgh5678'                          => 'abcd1234efgh5678',
	'{"access_token":"ya29.a0AfH6SMBxxxxxxxx","expires":1}'   => 'ya29.a0AfH6SMBxxxxxxxx',
	'token abcdEFGH1234ijklMNOP5678qrstUVWX9012yzab'          => 'abcdEFGH1234ijklMNOP5678qrstUVWX9012yzab',
);
foreach ( $cases as $input => $secret ) {
	assert_true( ! str_contains( mlab_smtp_redact( $input ), $secret ), 'redacted: ' . substr( $input, 0, 40 ) );
}
foreach ( array( 'Connection timed out after 10001 milliseconds', 'Der Anbieter hat mit Fehler 403 geantwortet: domain is not verified', 'Message 123e4567-e89b-12d3-a456-426614174000 accepted', 'smtp.example.test:587' ) as $plain ) {
	assert_same( $plain, mlab_smtp_redact( $plain ), 'ordinary text stays unchanged: ' . substr( $plain, 0, 30 ) );
}
assert_same( '', mlab_smtp_redact( '' ), 'empty text stays empty' );
assert_same( 'a***@e***.ch', mlab_smtp_mask_email( 'anna@example.ch' ), 'e-mail is shortened' );
assert_same( 'kein-mail', mlab_smtp_mask_email( 'kein-mail' ), 'non-address is left alone' );
assert_same( 'Fehler bei a***@e***.ch', mlab_smtp_redact( 'Fehler bei anna@example.ch', true ), 'e-mail addresses in a text are shortened on request' );

// ---- 2. Interne Adressen.
foreach ( array( '127.0.0.1', '10.1.2.3', '192.168.0.5', '172.16.9.9', '169.254.169.254', '100.64.1.1', '0.0.0.0', '::1', 'fd00::1', 'fe80::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', 'kein-ip' ) as $ip ) {
	assert_true( mlab_smtp_ip_is_internal( $ip ), "internal: {$ip}" );
}
foreach ( array( '93.184.216.34', '8.8.8.8', '2606:2800:220:1:248:1893:25c8:1946' ) as $ip ) {
	assert_true( ! mlab_smtp_ip_is_internal( $ip ), "public: {$ip}" );
}

// ---- 3. Sichere URLs (DNS über den Filter simuliert).
add_filter( 'mlab_smtp_resolve_host', static function ( $pre, $host ) {
	$map = array( 'hook.example.test' => array( '93.184.216.34' ), 'intern.example.test' => array( '10.0.0.7' ), 'gemischt.example.test' => array( '93.184.216.34', '169.254.169.254' ) );
	return $map[ $host ] ?? ( 'gateway.example.test' === $host ? array( '10.9.9.9' ) : null );
}, 10, 2 );
assert_same( 'https://hook.example.test/a', mlab_smtp_safe_url( ' https://hook.example.test/a ' ), 'public https URL is accepted' );
foreach ( array( 'http://hook.example.test/a' => 'http', 'ftp://hook.example.test' => 'ftp', 'https://u:p@hook.example.test/' => 'credentials', 'https://hook.example.test:22/' => 'port', 'https://intern.example.test/' => 'internal host', 'https://gemischt.example.test/' => 'one internal address of several', 'https://127.0.0.1/' => 'loopback literal', 'https://[::1]/' => 'IPv6 loopback', 'https://169.254.169.254/latest' => 'metadata address', 'https://nicht-vorhanden.example.test/' => 'does not resolve', 'nur-text' => 'not a URL', '' => 'empty' ) as $url => $why ) {
	assert_true( is_wp_error( mlab_smtp_safe_url( $url ) ), "rejected: {$why}" );
}
add_filter( 'mlab_smtp_allowed_url_hosts', static fn() => array( 'gateway.example.test' ) );
assert_same( 'https://gateway.example.test/h', mlab_smtp_safe_url( 'https://gateway.example.test/h' ), 'explicitly allowed internal host is accepted' );
mlab_http_reset();
$res = mlab_smtp_safe_post( 'https://intern.example.test/hook', '{}' );
assert_true( is_wp_error( $res ) && 0 === count( mlab_http_requests() ), 'safe_post to an internal host makes no request' );
mlab_http_mock( '#hook\.example\.test#', 200, array( 'ok' => true ) );
$res = mlab_smtp_safe_post( 'https://hook.example.test/hook', '{"a":1}' );
assert_true( ! is_wp_error( $res ) && 1 === count( mlab_http_requests() ), 'safe_post to a public host sends one request' );

// ---- 4. Verschlüsselung: Format, Manipulation, Schlüsselwechsel.
$enc = mlab_smtp_encrypt( 'geheim-wert' );
assert_true( str_starts_with( $enc, 'enc2:' ) && ! str_contains( $enc, 'geheim-wert' ), 'new format enc2 with a key id' );
assert_same( 'geheim-wert', mlab_smtp_decrypt( $enc ), 'round trip' );
assert_same( '', mlab_smtp_encrypt( '' ), 'empty stays empty' );
assert_same( 'klartext', mlab_smtp_decrypt( 'klartext' ), 'value without prefix is returned unchanged' );
$parts = explode( ':', $enc, 3 );
$tampered = 'enc2:' . $parts[1] . ':' . base64_encode( substr( base64_decode( $parts[2] ), 0, -1 ) . 'X' );
assert_same( '', mlab_smtp_decrypt( $tampered ), 'tampered ciphertext does not decrypt' );
assert_same( 'damaged', mlab_smtp_cipher_problem( $tampered ), 'tampering with the right key id is reported as damaged' );
$foreign = 'enc2:deadbeef:' . $parts[2];
assert_same( 'key_changed', mlab_smtp_cipher_problem( $foreign ), 'unknown key id is reported as key changed' );
assert_same( '', mlab_smtp_cipher_problem( $enc ), 'readable value has no problem' );
// Altes Format (enc:, mit dem Salt-Schlüssel) bleibt lesbar.
$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
$legacy = 'enc:' . base64_encode( $nonce . sodium_crypto_secretbox( 'alt-wert', $nonce, mlab_smtp_salt_key() ) );
assert_same( 'alt-wert', mlab_smtp_decrypt( $legacy ), 'legacy enc: format is still readable' );
// Unlesbares Secret wird erkannt und nicht still ersetzt.
update_option( MLAB_SMTP_OPTION, array_merge( (array) get_option( MLAB_SMTP_OPTION ), array( 'smtp_pass' => $foreign ) ) );
assert_true( mlab_smtp_secret_unreadable( 'smtp_pass' ), 'secret of a lost key is marked unreadable' );
assert_same( '', (string) mlab_smtp_get( 'smtp_pass' ), 'unreadable secret reads as empty, no key is regenerated' );
assert_same( $foreign, get_option( MLAB_SMTP_OPTION )['smtp_pass'], 'the stored value is left untouched' );
mlab_smtp_store( array( 'smtp_pass' => $pass ) );

// ---- 5. Ausgabestellen enthalten keine Secrets.
mlab_smtp_record( false, "Fehler 401: Bearer {$key} und Passwort {$pass}", false );
assert_true( ! str_contains( (string) mlab_smtp_state_get()['last_error'], $key ) && ! str_contains( (string) mlab_smtp_state_get()['last_error'], $pass ), 'state: last error is redacted' );
$texts = mlab_smtp_ui_error_texts( "x-api-key {$key} / {$pass}" );
assert_true( ! str_contains( $texts['message'] . $texts['detail'], $key ) && ! str_contains( $texts['message'] . $texts['detail'], $pass ), 'UI error texts are redacted' );
$result = mlab_smtp_test_result( false, "Fehler {$key}", "CLIENT -> SERVER: {$pass}", 'resend' );
assert_true( ! str_contains( wp_json_encode( $result ), $key ) && ! str_contains( wp_json_encode( $result ), $pass ), 'test mail result (error, hint, debug) is redacted' );

do_action( 'rest_api_init' );
mlab_http_reset();
mlab_http_mock( '#api\.resend\.com#', 401, array( 'statusCode' => 401, 'name' => 'validation_error', 'message' => "API key {$key} is invalid" ) );
$json = '';
foreach ( array( '/mlab-smtp/v1/check' => array(), '/mlab-smtp/v1/save' => array( 'group' => 'resend', 'fields' => array( 'resend_api_key' => 're_neuerschluessel123456789' ) ), '/mlab-smtp/v1/test' => array( 'to' => 'x@example.test' ) ) as $route => $params ) {
	$req = new WP_REST_Request( 'POST', $route );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( $params ) );
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	$json .= wp_json_encode( rest_do_request( $req )->get_data() );
}
assert_true( ! str_contains( $json, $key ) && ! str_contains( $json, $pass ) && ! str_contains( $json, 're_neuerschluessel123456789' ), 'REST responses never contain secrets (stored or just submitted)' );

// ---- 6. Settings-Seite druckt Secrets nie in Formularfelder.
ob_start();
mlab_smtp_ui_field( 'smtp_pass', 'Passwort', array( 'type' => 'password' ) );
mlab_smtp_ui_field( 'resend_api_key', 'Key', array( 'type' => 'password' ) );
$html = ob_get_clean();
assert_true( ! str_contains( $html, $pass ) && ! str_contains( $html, $key ), 'form fields never contain stored secrets' );

t_finish();
