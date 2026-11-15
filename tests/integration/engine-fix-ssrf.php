<?php
// SSRF-Härtung: eingebettete IPv4-Adressen, DNS-Pinning, Alarm-URL im Netzwerk.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/store-helpers.php';

// (b) Eingebettete IPv4-Adressen werden nach den IPv4-Regeln beurteilt.
foreach ( array( '::10.0.0.1', '::127.0.0.1', '::192.168.1.1', '64:ff9b::10.0.0.1', '64:ff9b::7f00:1', '2002:a00:1::1', '2002:7f00:1::', '2002:c0a8:101::1', 'fec0::1', 'feff::1' ) as $ip ) {
	assert_true( mlab_smtp_ip_is_internal( $ip ), "internal: {$ip}" );
}
foreach ( array( '64:ff9b::8.8.8.8', '2002:808:808::1', '2606:2800:220:1:248:1893:25c8:1946' ) as $ip ) {
	assert_true( ! mlab_smtp_ip_is_internal( $ip ), "public: {$ip}" );
}

// (a) DNS-Pinning: die geprüfte Adresse wird für die Anfrage festgeschrieben.
mlab_http_reset();
mlab_http_mock( '#hook\.example\.test#', 200, array( 'ok' => true ) );
$calls = 0;
$dns   = static function ( $pre, $host ) use ( &$calls ) {
	if ( 'hook.example.test' !== $host ) {
		return $pre;
	}
	return 1 === ++$calls ? array( '93.184.216.34' ) : array( '10.1.1.1' ); // Rebinding nach der Prüfung.
};
add_filter( 'mlab_smtp_resolve_host', $dns, 10, 2 );
$seen = '';
$spy  = static function ( $pre, $args, $url ) use ( &$seen ) {
	if ( str_contains( $url, 'hook.example.test' ) ) {
		$ch = curl_init();
		do_action( 'http_api_curl', $ch, $args, $url );
		$seen = mlab_smtp_safe_pin_last();
	}
	return $pre;
};
add_filter( 'pre_http_request', $spy, 5, 3 );
$res = mlab_smtp_safe_post( 'https://hook.example.test/a', '{}' );
assert_same( 'hook.example.test:443:93.184.216.34', $seen, 'pin: the request is pinned to the checked address' );
assert_true( ! has_action( 'http_api_curl' ), 'pin: hook removed after the request' );
assert_true( ! is_wp_error( $res ), 'pin: request went out' );

// Ohne Pinning (kein cURL): eine zweite Auflösung direkt vor der Anfrage muss gleich sein.
$calls = 0;
mlab_http_reset();
mlab_http_mock( '#hook\.example\.test#', 200, array( 'ok' => true ) );
add_filter( 'mlab_smtp_can_pin', '__return_false' );
$res = mlab_smtp_safe_post( 'https://hook.example.test/a', '{}' );
assert_true( is_wp_error( $res ), 'no pin: a changed second resolution is refused' );
assert_same( 0, count( mlab_http_requests() ), 'no pin: no request' );
$calls = 0;
add_filter( 'mlab_smtp_resolve_host', $same = static fn( $pre, $host ) => 'hook.example.test' === $host ? array( '93.184.216.34' ) : $pre, 20, 2 );
$res = mlab_smtp_safe_post( 'https://hook.example.test/a', '{}' );
assert_true( ! is_wp_error( $res ), 'no pin: equal resolutions pass' );
remove_filter( 'mlab_smtp_resolve_host', $same, 20 );
remove_filter( 'mlab_smtp_can_pin', '__return_false' );
remove_filter( 'pre_http_request', $spy, 5 );
remove_filter( 'mlab_smtp_resolve_host', $dns, 10 );

// (c) Multisite: die Alarm-URL ändert nur, wer Netzwerkoptionen verwalten darf.
add_filter( 'mlab_smtp_resolve_host', static fn( $pre, $host ) => 'hook.example.test' === $host ? array( '93.184.216.34' ) : $pre, 30, 2 );
delete_option( MLAB_SMTP_ALERTS_OPTION );
add_filter( 'mlab_smtp_is_multisite', '__return_true' );
add_filter( 'mlab_smtp_can_change_host', '__return_false' );
$errors = mlab_smtp_alerts_save( array( 'url' => 'https://hook.example.test/alarm' ) );
assert_true( ! empty( $errors ), 'multisite: a site admin cannot set the alert url' );
assert_same( '', mlab_smtp_alerts_get()['url'], 'multisite: nothing stored' );
remove_filter( 'mlab_smtp_can_change_host', '__return_false' );
add_filter( 'mlab_smtp_can_change_host', '__return_true' );
assert_same( array(), mlab_smtp_alerts_save( array( 'url' => 'https://hook.example.test/alarm' ) ), 'multisite: a network admin can' );
remove_filter( 'mlab_smtp_can_change_host', '__return_true' );
add_filter( 'mlab_smtp_can_change_host', '__return_false' );
assert_same( array(), mlab_smtp_alerts_save( array( 'url' => 'https://hook.example.test/alarm', 'events' => array( 'queue_stalled' => 1 ) ) ), 'multisite: saving other fields with the same url stays possible' );
remove_filter( 'mlab_smtp_can_change_host', '__return_false' );
remove_filter( 'mlab_smtp_is_multisite', '__return_true' );
