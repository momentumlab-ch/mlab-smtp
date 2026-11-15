<?php
// Hilfen für die Engine-Tests: sauberer Zustand, Anbieter-Antworten per Callback, Versand ohne Netz.
require_once __DIR__ . '/store-helpers.php';

const ENG_KEY    = 're_main_key_1234567890';
const ENG_FB_KEY = 're_fallback_key_1234567890';

/** Setzt Einstellungen, Tabellen, Mocks und Request-Zustand zurück. */
function mlab_e_reset( array $settings = array() ): void {
	if ( function_exists( 'mlab_smtp_staging_put' ) ) {
		mlab_smtp_staging_put( array( 'mode' => 'off' ) );
	}
	delete_option( MLAB_SMTP_OPTION );
	delete_option( 'mlab_smtp_engine_pauses' );
	delete_option( 'mlab_smtp_state' );
	delete_option( 'mlab_smtp_engine_last_run' );
	mlab_t_reset_store();
	mlab_http_reset();
	$GLOBALS['mlab_e_filters'] = $GLOBALS['mlab_e_filters'] ?? array();
	foreach ( $GLOBALS['mlab_e_filters'] as $cb ) {
		remove_filter( 'pre_http_request', $cb, 11 );
	}
	$GLOBALS['mlab_e_filters'] = array();
	foreach ( array( 'bypass', 'force', 'fallback_only', 'fallback_test', 'quiet' ) as $flag ) {
		mlab_smtp_flag( $flag, false );
	}
	mlab_smtp_log_active_message( 0 );
	mlab_smtp_engine_final( array() );
	mlab_smtp_engine_context( array() );
	mlab_smtp_engine_random( static fn(): float => 0.5 );
	add_filter( 'mlab_smtp_engine_spawn', '__return_false' );
	mlab_smtp_store(
		array_merge(
			array(
				'provider'       => 'resend',
				'setup_done'     => 1,
				'from_email'     => 'info@example.test',
				'from_name'      => 'Firma',
				'resend_api_key' => ENG_KEY,
				'fb_provider'    => 'resend',
				'fb_resend_api_key' => ENG_FB_KEY,
			),
			$settings
		)
	);
}

/** Fallback über ein zweites Resend-Konto einschalten. */
function mlab_e_with_fallback( array $extra = array() ): void {
	mlab_smtp_store( array_merge( array( 'fallback_enabled' => 1 ), $extra ) );
}

/**
 * Antwort per Callback für URLs, die auf das Muster passen. Der Callback bekommt (url, args, Anzahl der Aufrufe)
 * und liefert array(code, body, headers) oder ein WP_Error.
 */
function mlab_e_reply( string $pattern, callable $cb ): void {
	$count = 0;
	$hook  = static function ( $pre, $args, $url ) use ( $pattern, $cb, &$count ) {
		if ( ! preg_match( $pattern, $url ) ) {
			return $pre; // Not ours: keep what the plain mock returned.
		}
		$out = $cb( $url, $args, ++$count );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		return array(
			'headers'  => $out['headers'] ?? array(),
			'body'     => is_string( $out['body'] ?? '' ) ? ( $out['body'] ?? '' ) : wp_json_encode( $out['body'] ),
			'response' => array(
				'code'    => $out['code'] ?? 200,
				'message' => 'Mock',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $hook, 11, 3 ); // After the plain mock, which has recorded the request already.
	$GLOBALS['mlab_e_filters'][] = $hook;
}

/** Resend-Anfragen (POST /emails) der Aufzeichnung. */
function mlab_e_resend_requests(): array {
	return array_values( array_filter( mlab_http_requests(), static fn( array $r ): bool => 'POST' === $r['method'] && str_contains( $r['url'], 'api.resend.com/emails' ) ) );
}

/** Header einer aufgezeichneten Anfrage (Name kleingeschrieben). */
function mlab_e_header( array $request, string $name ): string {
	foreach ( (array) ( $request['args']['headers'] ?? array() ) as $key => $value ) {
		if ( strtolower( (string) $key ) === strtolower( $name ) ) {
			return (string) $value;
		}
	}
	return '';
}

/** Zeile der Nachricht (nach uuid oder letzte). */
function mlab_e_last_row(): ?array {
	global $wpdb;
	$id = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . mlab_smtp_table( 'messages' ) );
	return $id ? mlab_smtp_store_get( $id ) : null;
}

/** Zustand eines Anbieter-Callbacks: Zeitüberschreitung nach dem Senden. */
function mlab_e_timeout(): WP_Error {
	return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received' );
}

/** Eine Nachricht für den Worker: Zeile und Nutzlast direkt anlegen (wie nach wp_mail() im Modus queue). */
function mlab_e_queue_message( array $extra = array(), array $snapshot_extra = array() ): int {
	$uuid = wp_generate_uuid4();
	$id   = mlab_smtp_store_create( array_merge( array(
		'uuid'         => $uuid,
		'mode'         => 'queued',
		'status'       => 'queued',
		'provider'     => 'resend',
		'recipients'   => array( 'user@example.test' ),
		'subject'      => 'Betreff',
		'max_attempts' => 6,
	), $extra ) );
	$snap = array_merge( array(
		'v' => 1, 'from' => 'info@example.test', 'from_name' => 'Firma', 'sender' => '',
		'to' => array( array( 'user@example.test', '' ) ), 'cc' => array(), 'bcc' => array(), 'reply_to' => array(),
		'subject' => 'Betreff', 'body' => 'Text', 'alt_body' => '', 'html' => false, 'charset' => 'UTF-8', 'encoding' => '8bit',
		'priority' => null, 'headers' => array(), 'attachments' => array(), 'resend' => 0, 'content_type' => 'text/plain',
		'message_id' => '<mlab-' . str_replace( '-', '', $uuid ) . '@example.test>',
	), $snapshot_extra );
	mlab_smtp_store_set_payload( $id, $snap );
	return $id;
}
