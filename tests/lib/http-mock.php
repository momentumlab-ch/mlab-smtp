<?php
// Mock der WordPress-HTTP-API über den Filter pre_http_request.
// Nutzung: mlab_http_mock( '#api\.resend\.com/emails#', 200, array( 'id' => 'x' ) );
$GLOBALS['mlab_http_mocks']    = array();
$GLOBALS['mlab_http_requests'] = array();

/** Registriert eine Antwort für URLs, die auf das Regex-Muster passen. $body: Array (wird JSON) oder String. */
function mlab_http_mock( string $pattern, int $code = 200, $body = array(), ?string $method = null ): void {
	array_unshift(
		$GLOBALS['mlab_http_mocks'],
		array( $pattern, $code, is_string( $body ) ? $body : wp_json_encode( $body ), $method )
	);
}

function mlab_http_requests(): array {
	return $GLOBALS['mlab_http_requests'];
}

function mlab_http_reset(): void {
	$GLOBALS['mlab_http_mocks']    = array();
	$GLOBALS['mlab_http_requests'] = array();
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$GLOBALS['mlab_http_requests'][] = array(
			'url'    => $url,
			'method' => strtoupper( $args['method'] ?? 'GET' ),
			'args'   => $args,
		);
		foreach ( $GLOBALS['mlab_http_mocks'] as $m ) {
			if ( preg_match( $m[0], $url ) && ( null === $m[3] || strtoupper( $m[3] ) === strtoupper( $args['method'] ?? 'GET' ) ) ) {
				return array(
					'headers'  => array(),
					'body'     => $m[2],
					'response' => array(
						'code'    => $m[1],
						'message' => 'Mock',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		}
		return new WP_Error( 'http_request_failed', 'Kein Mock für ' . $url );
	},
	10,
	3
);
