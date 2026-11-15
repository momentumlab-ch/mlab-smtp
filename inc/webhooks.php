<?php
/**
 * Resend-Webhooks (Svix-Signatur): Zustellbestätigung für die Versandwege «Resend» (Haupt- und Ersatz-Konto).
 *
 * Zwei Routen mit getrennten Secrets. Die Berechtigung ist offen, die Echtheit entscheidet allein die Signatur über den rohen Body.
 * Jede Lieferung wird über svix-id nur einmal verarbeitet. Schlägt die Verarbeitung fehl, wird die Deduplizierungszeile entfernt
 * und mit 500 geantwortet, damit Resend erneut zustellt. Es gibt kein Tracking von Öffnen und Klicks.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_WEBHOOK_MAX_BYTES  = 262144;
const MLAB_SMTP_WEBHOOK_TOLERANCE  = 300;
const MLAB_SMTP_WEBHOOK_ORPHAN_TTL = 7 * DAY_IN_SECONDS;

add_action( 'rest_api_init', 'mlab_smtp_webhook_register' );
add_action( 'mlab_smtp_daily', 'mlab_smtp_webhook_rematch' );

/**
 * Routes: Pfad => Anbieter-Kennung in den Ereignissen und Schlüssel des Secrets.
 *
 * @return array<string, array{provider: string, secret: string}>
 */
function mlab_smtp_webhook_routes(): array {
	return array(
		'resend'          => array(
			'provider' => 'resend',
			'secret'   => 'resend_webhook_secret',
		),
		'resend-fallback' => array(
			'provider' => 'resend-fallback',
			'secret'   => 'fb_resend_webhook_secret',
		),
	);
}

/**
 * Event types of Resend that change a status (type => message status, null = only recorded).
 *
 * @return array<string, string|null>
 */
function mlab_smtp_webhook_types(): array {
	return array(
		'email.sent'             => 'accepted',
		'email.delivered'        => 'delivered',
		'email.delivery_delayed' => null,
		'email.bounced'          => 'bounced',
		'email.complained'       => 'complained',
		'email.failed'           => 'failed',
	);
}

/**
 * Public URL of a webhook route.
 *
 * @param string $route resend or resend-fallback.
 */
function mlab_smtp_webhook_url( string $route ): string {
	return rest_url( 'mlab-smtp/v1/webhooks/' . $route );
}

/**
 * Registers both routes (POST only, signature instead of a login).
 */
function mlab_smtp_webhook_register(): void {
	foreach ( array_keys( mlab_smtp_webhook_routes() ) as $route ) {
		register_rest_route(
			'mlab-smtp/v1',
			'/webhooks/' . $route,
			array(
				'methods'             => 'POST',
				'callback'            => static function ( WP_REST_Request $request ) use ( $route ) {
					return mlab_smtp_webhook_handle( $request, $route );
				},
				'permission_callback' => '__return_true',
			)
		);
	}
}

/**
 * Error answer with a generic text.
 *
 * @param string $code   Code.
 * @param int    $status HTTP status.
 */
function mlab_smtp_webhook_error( string $code, int $status ): WP_Error {
	$texts = array(
		'mlab_smtp_webhook_not_configured' => 'Not configured.',
		'mlab_smtp_webhook_too_large'      => 'Payload too large.',
		'mlab_smtp_webhook_unauthorized'   => 'Invalid signature.',
		'mlab_smtp_webhook_bad_request'    => 'Invalid payload.',
		'mlab_smtp_webhook_failed'         => 'Processing failed.',
	);
	return new WP_Error( $code, $texts[ $code ] ?? 'Error.', array( 'status' => $status ) );
}

/**
 * Handles one delivery.
 *
 * @param WP_REST_Request $request Request.
 * @param string          $route   resend or resend-fallback.
 * @return WP_REST_Response|WP_Error
 */
function mlab_smtp_webhook_handle( WP_REST_Request $request, string $route ) {
	$def    = mlab_smtp_webhook_routes()[ $route ] ?? null;
	$secret = $def ? mlab_smtp_webhook_secret_bytes( $def['secret'] ) : '';
	if ( '' === $secret ) {
		return mlab_smtp_webhook_error( 'mlab_smtp_webhook_not_configured', 404 );
	}
	$body = (string) $request->get_body();
	if ( (int) $request->get_header( 'content-length' ) > MLAB_SMTP_WEBHOOK_MAX_BYTES || strlen( $body ) > MLAB_SMTP_WEBHOOK_MAX_BYTES ) {
		return mlab_smtp_webhook_error( 'mlab_smtp_webhook_too_large', 413 );
	}
	$id = (string) $request->get_header( 'svix-id' );
	if ( ! mlab_smtp_webhook_verify( $secret, $id, (string) $request->get_header( 'svix-timestamp' ), (string) $request->get_header( 'svix-signature' ), $body ) ) {
		return mlab_smtp_webhook_error( 'mlab_smtp_webhook_unauthorized', 401 );
	}
	$payload = json_decode( $body, true );
	if ( ! is_array( $payload ) || ! is_string( $payload['type'] ?? null ) ) {
		return mlab_smtp_webhook_error( 'mlab_smtp_webhook_bad_request', 400 );
	}
	if ( ! array_key_exists( $payload['type'], mlab_smtp_webhook_types() ) ) {
		return new WP_REST_Response( array( 'status' => 'ignored' ), 200 );
	}
	return mlab_smtp_webhook_process( $def['provider'], substr( $id, 0, 191 ), $payload );
}

/**
 * Decoded signing secret (bytes), empty when none is stored or it cannot be read.
 *
 * @param string $key Setting key.
 */
function mlab_smtp_webhook_secret_bytes( string $key ): string {
	$secret = (string) mlab_smtp_get( $key );
	if ( str_starts_with( $secret, 'whsec_' ) ) {
		$secret = substr( $secret, 6 );
	}
	$bytes = '' === $secret ? false : base64_decode( $secret, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Svix secret is Base64.
	return is_string( $bytes ) ? $bytes : '';
}

/**
 * Checks the Svix signature over the raw body.
 *
 * @param string $secret    Decoded secret.
 * @param string $id        Header svix-id.
 * @param string $timestamp Header svix-timestamp.
 * @param string $header    Header svix-signature (space separated, "v1,<base64>").
 * @param string $body      Raw body.
 * @param int    $now       Time (default now).
 */
function mlab_smtp_webhook_verify( string $secret, string $id, string $timestamp, string $header, string $body, int $now = 0 ): bool {
	if ( '' === $id || '' === $header || ! ctype_digit( $timestamp ) || strlen( $timestamp ) > 12 ) {
		return false;
	}
	$now = $now > 0 ? $now : time();
	if ( abs( $now - (int) $timestamp ) > MLAB_SMTP_WEBHOOK_TOLERANCE ) {
		return false;
	}
	$expected = base64_encode( hash_hmac( 'sha256', $id . '.' . $timestamp . '.' . $body, $secret, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Svix signature is Base64.
	$match    = false;
	foreach ( array_slice( preg_split( '/\s+/', trim( $header ) ), 0, 10 ) as $candidate ) {
		$parts = explode( ',', $candidate, 2 );
		if ( 2 === count( $parts ) && 'v1' === $parts[0] && hash_equals( $expected, $parts[1] ) ) {
			$match = true;
		}
	}
	return $match;
}

/**
 * Stores and applies one verified event exactly once.
 *
 * @param string $provider resend or resend-fallback.
 * @param string $event_id svix-id.
 * @param array  $payload  Decoded body.
 * @return WP_REST_Response|WP_Error
 */
function mlab_smtp_webhook_process( string $provider, string $event_id, array $payload ) {
	if ( mlab_smtp_webhook_event_exists( $provider, $event_id ) ) {
		return new WP_REST_Response( array( 'status' => 'duplicate' ), 200 );
	}
	$type = (string) $payload['type'];
	$info = mlab_smtp_webhook_summary( $payload );
	try {
		$message = mlab_smtp_webhook_match( $info['email_id'], $info['mlab_id'] );
		$stored  = mlab_smtp_store_add_event(
			array(
				'provider'    => $provider,
				'event_id'    => $event_id,
				'type'        => $type,
				'occurred_at' => mlab_smtp_webhook_time( (string) ( $payload['created_at'] ?? '' ) ),
				'message_id'  => $message,
				'detail'      => wp_json_encode( $info ),
			)
		);
		if ( ! $stored ) {
			return mlab_smtp_webhook_error( 'mlab_smtp_webhook_failed', 500 );
		}
	} catch ( Throwable $e ) {
		return mlab_smtp_webhook_error( 'mlab_smtp_webhook_failed', 500 );
	}
	try {
		/**
		 * Fires while an event is processed. An exception makes the delivery fail so Resend repeats it.
		 *
		 * @param string   $type    Event type.
		 * @param int|null $message Message id, null if not matched yet.
		 * @param string   $provider resend or resend-fallback.
		 */
		do_action( 'mlab_smtp_webhook_event', $type, $message, $provider );
		mlab_smtp_webhook_apply( $type, $message );
	} catch ( Throwable $e ) {
		mlab_smtp_store_delete_event( $provider, $event_id );
		return mlab_smtp_webhook_error( 'mlab_smtp_webhook_failed', 500 );
	}
	return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
}

/**
 * Unix time of an ISO 8601 text, now when it cannot be read.
 *
 * @param string $text Timestamp.
 */
function mlab_smtp_webhook_time( string $text ): int {
	$time = strtotime( $text );
	return false === $time ? time() : $time;
}

/**
 * Minimal, masked details of an event: ids for the later matching and a short reason.
 *
 * @param array $payload Decoded body.
 * @return array{email_id: string, mlab_id: string, info: string}
 */
function mlab_smtp_webhook_summary( array $payload ): array {
	$data   = is_array( $payload['data'] ?? null ) ? $payload['data'] : array();
	$tags   = is_array( $data['tags'] ?? null ) ? $data['tags'] : array();
	$bounce = is_array( $data['bounce'] ?? null ) ? $data['bounce'] : array();
	$failed = is_array( $data['failed'] ?? null ) ? $data['failed'] : array();
	$parts  = array(
		(string) ( $bounce['type'] ?? '' ),
		(string) ( $bounce['subType'] ?? '' ),
		(string) ( $bounce['message'] ?? '' ),
		(string) ( $failed['reason'] ?? '' ),
	);
	$info   = trim( implode( ' ', array_filter( array_map( 'strval', $parts ) ) ) );
	return array(
		'email_id' => preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $data['email_id'] ?? '' ) ),
		'mlab_id'  => strtolower( preg_replace( '/[^A-Fa-f0-9]/', '', (string) ( $tags['mlab_id'] ?? '' ) ) ),
		'info'     => mlab_smtp_store_text( mlab_smtp_redact( $info, true ), 140 ),
	);
}

/**
 * Message of an event: first by the tag mlab_id (uuid without dashes), then by the provider message id.
 *
 * @param string $email_id Resend email id.
 * @param string $mlab_id  UUID without dashes.
 * @return int|null Message id.
 */
function mlab_smtp_webhook_match( string $email_id, string $mlab_id ): ?int {
	$row = null;
	if ( 32 === strlen( $mlab_id ) ) {
		$uuid = substr( $mlab_id, 0, 8 ) . '-' . substr( $mlab_id, 8, 4 ) . '-' . substr( $mlab_id, 12, 4 ) . '-' . substr( $mlab_id, 16, 4 ) . '-' . substr( $mlab_id, 20 );
		$row  = mlab_smtp_store_find( $uuid, 'uuid' );
	}
	if ( ! $row && '' !== $email_id ) {
		$row = mlab_smtp_store_find( $email_id, 'provider_msg_id' );
	}
	return $row ? (int) $row['id'] : null;
}

/**
 * Status effect of an event. The store never downgrades; delivery_delayed only stays recorded.
 *
 * @param string   $type    Event type.
 * @param int|null $message Message id.
 */
function mlab_smtp_webhook_apply( string $type, ?int $message ): void {
	$status = mlab_smtp_webhook_types()[ $type ] ?? null;
	if ( $message && $status ) {
		mlab_smtp_store_apply_event_status( $message, $status );
	}
}

/**
 * True when the delivery was already stored.
 *
 * @param string $provider Provider.
 * @param string $event_id Event id.
 */
function mlab_smtp_webhook_event_exists( string $provider, string $event_id ): bool {
	return (bool) mlab_smtp_store_run(
		static function () use ( $provider, $event_id ) {
			global $wpdb;
			return $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . mlab_smtp_table( 'events' ) . ' WHERE provider = %s AND event_id = %s', $provider, $event_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
		}
	);
}

/**
 * Matches events that arrived before the provider message id was stored and applies them. Also deletes orphans
 * older than 7 days. Called when a provider_msg_id is saved and by the daily job.
 *
 * @return int Number of events matched.
 */
function mlab_smtp_webhook_rematch(): int {
	$rows  = mlab_smtp_store_run(
		static function () {
			global $wpdb;
			return $wpdb->get_results( 'SELECT id, type, detail FROM ' . mlab_smtp_table( 'events' ) . " WHERE message_id IS NULL AND provider IN ('resend','resend-fallback') ORDER BY occurred_at ASC, id ASC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
		},
		array()
	);
	$count = 0;
	foreach ( (array) $rows as $row ) {
		$info    = json_decode( (string) $row['detail'], true );
		$message = is_array( $info ) ? mlab_smtp_webhook_match( (string) ( $info['email_id'] ?? '' ), (string) ( $info['mlab_id'] ?? '' ) ) : null;
		if ( $message && mlab_smtp_webhook_attach( (int) $row['id'], $message ) ) {
			mlab_smtp_webhook_apply( (string) $row['type'], $message );
			++$count;
		}
	}
	mlab_smtp_webhook_purge_orphans();
	return $count;
}

/**
 * Links an orphan event to its message.
 *
 * @param int $event_id   Event row id.
 * @param int $message_id Message id.
 */
function mlab_smtp_webhook_attach( int $event_id, int $message_id ): bool {
	return (bool) mlab_smtp_store_run(
		static function () use ( $event_id, $message_id ) {
			global $wpdb;
			return $wpdb->update( mlab_smtp_table( 'events' ), array( 'message_id' => $message_id ), array( 'id' => $event_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fixed table name.
		}
	);
}

/**
 * Deletes events that stayed without a message for more than 7 days.
 */
function mlab_smtp_webhook_purge_orphans(): void {
	mlab_smtp_store_run(
		static function () {
			global $wpdb;
			return $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . mlab_smtp_table( 'events' ) . " WHERE message_id IS NULL AND provider IN ('resend','resend-fallback') AND received_at < %d", mlab_smtp_store_now() - MLAB_SMTP_WEBHOOK_ORPHAN_TTL ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
		}
	);
}

/**
 * Time of the last event received on a route.
 *
 * @param string $route resend or resend-fallback.
 * @return int Unix time, 0 when none.
 */
function mlab_smtp_webhook_last_event( string $route ): int {
	$provider = mlab_smtp_webhook_routes()[ $route ]['provider'] ?? '';
	return (int) mlab_smtp_store_run(
		static function () use ( $provider ) {
			global $wpdb;
			return $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(received_at) FROM ' . mlab_smtp_table( 'events' ) . ' WHERE provider = %s', $provider ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
		},
		0
	);
}
