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
 * Stores one verified event and applies its status effect. «Stored» and «processed» are different states: a repeated
 * delivery is only answered as a duplicate when the event was processed. An event that is stored but whose status
 * could not be applied (database error) answers 500 and is processed again on the next delivery; the status
 * application is idempotent, so repeated or parallel deliveries are safe.
 *
 * @param string $provider resend or resend-fallback.
 * @param string $event_id svix-id.
 * @param array  $payload  Decoded body.
 * @return WP_REST_Response|WP_Error
 */
function mlab_smtp_webhook_process( string $provider, string $event_id, array $payload ) {
	$row = mlab_smtp_webhook_event_row( $provider, $event_id );
	if ( $row && null !== $row['processed_at'] ) {
		return new WP_REST_Response( array( 'status' => 'duplicate' ), 200 );
	}
	$type = (string) $payload['type'];
	try {
		if ( ! $row ) {
			$row = mlab_smtp_webhook_store( $provider, $event_id, $type, $payload );
		}
		if ( ! $row ) {
			return mlab_smtp_webhook_error( 'mlab_smtp_webhook_failed', 500 );
		}
		$message = $row['message_id'] ? (int) $row['message_id'] : null;
		if ( ! $message ) {
			$info    = mlab_smtp_webhook_summary( $payload );
			$message = mlab_smtp_webhook_match( $info['email_id'], $info['mlab_id'], $provider );
			if ( $message ) {
				mlab_smtp_webhook_attach( (int) $row['id'], $message );
			}
		}
		/**
		 * Fires while an event is processed. An exception makes the delivery fail so Resend repeats it.
		 *
		 * @param string   $type    Event type.
		 * @param int|null $message Message id, null if not matched yet.
		 * @param string   $provider resend or resend-fallback.
		 */
		do_action( 'mlab_smtp_webhook_event', $type, $message, $provider );
		$result = mlab_smtp_webhook_apply( $type, $message );
	} catch ( Throwable $e ) {
		return mlab_smtp_webhook_error( 'mlab_smtp_webhook_failed', 500 );
	}
	if ( 'error' === $result ) {
		return mlab_smtp_webhook_error( 'mlab_smtp_webhook_failed', 500 );
	}
	if ( ! in_array( $result, array( 'deferred', 'unmatched' ), true ) ) {
		mlab_smtp_webhook_mark_processed( (int) $row['id'] );
	}
	return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
}

/**
 * Stores a new event. A parallel delivery of the same event that was faster is not an error: its row is used.
 *
 * @param string $provider resend or resend-fallback.
 * @param string $event_id svix-id.
 * @param string $type     Event type.
 * @param array  $payload  Decoded body.
 * @return array|null Event row (id, message_id, processed_at), null when it could not be stored.
 */
function mlab_smtp_webhook_store( string $provider, string $event_id, string $type, array $payload ): ?array {
	$info = mlab_smtp_webhook_summary( $payload );
	mlab_smtp_store_add_event(
		array(
			'provider'    => $provider,
			'event_id'    => $event_id,
			'type'        => $type,
			'occurred_at' => mlab_smtp_webhook_time( (string) ( $payload['created_at'] ?? '' ) ),
			'message_id'  => mlab_smtp_webhook_match( $info['email_id'], $info['mlab_id'], $provider ),
			'detail'      => wp_json_encode( $info ),
		)
	);
	return mlab_smtp_webhook_event_row( $provider, $event_id );
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
 * @param string $provider Webhook endpoint (resend = primary route, resend-fallback = fallback route), empty for no route check.
 * @return int|null Message id.
 */
function mlab_smtp_webhook_match( string $email_id, string $mlab_id, string $provider = '' ): ?int {
	$row = null;
	if ( 32 === strlen( $mlab_id ) ) {
		$uuid = substr( $mlab_id, 0, 8 ) . '-' . substr( $mlab_id, 8, 4 ) . '-' . substr( $mlab_id, 12, 4 ) . '-' . substr( $mlab_id, 16, 4 ) . '-' . substr( $mlab_id, 20 );
		$row  = mlab_smtp_store_find( $uuid, 'uuid' );
	}
	if ( ! $row && '' !== $email_id ) {
		$row = mlab_smtp_store_find( $email_id, 'provider_msg_id' );
	}
	if ( $row && '' !== $provider && ! mlab_smtp_webhook_route_fits( $row, $provider ) ) {
		return null; // The event was signed for the other account: it must not change this message.
	}
	return $row ? (int) $row['id'] : null;
}

/**
 * True when the message was sent through the route that belongs to the webhook endpoint with provider resend.
 * Rows without attempt rows (older ones) are judged by their fallback flag.
 *
 * @param array  $row      Message row.
 * @param string $provider resend (primary route) or resend-fallback (fallback route).
 */
function mlab_smtp_webhook_route_fits( array $row, string $provider ): bool {
	$route    = 'resend-fallback' === $provider ? 'fallback' : 'primary';
	$attempts = mlab_smtp_store_attempts( (int) $row['id'] );
	if ( ! $attempts ) {
		return ( 'fallback' === $route ) === ! empty( $row['fallback'] );
	}
	foreach ( $attempts as $attempt ) {
		if ( $route === $attempt['route'] && 'resend' === $attempt['provider'] ) {
			return true;
		}
	}
	return false;
}

/**
 * Status effect of an event. The store never downgrades; delivery_delayed only stays recorded.
 *
 * @param string   $type    Event type.
 * @param int|null $message Message id.
 * @return string changed, unchanged (nothing to do), deferred (message is processing), unmatched or error.
 */
function mlab_smtp_webhook_apply( string $type, ?int $message ): string {
	$status = mlab_smtp_webhook_types()[ $type ] ?? null;
	if ( ! $status ) {
		return 'unchanged';
	}
	return $message ? mlab_smtp_store_apply_event_result( $message, $status ) : 'unmatched';
}

/**
 * Stored row of a delivery.
 *
 * @param string $provider Provider.
 * @param string $event_id Event id.
 * @return array{id:int, message_id:?int, processed_at:?int}|null Null when it is not stored.
 */
function mlab_smtp_webhook_event_row( string $provider, string $event_id ): ?array {
	$row = mlab_smtp_store_run(
		static function () use ( $provider, $event_id ) {
			global $wpdb;
			return $wpdb->get_row( $wpdb->prepare( 'SELECT id, message_id, processed_at FROM %i WHERE provider = %s AND event_id = %s', mlab_smtp_table( 'events' ), $provider, $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
		},
		null
	);
	if ( ! is_array( $row ) ) {
		return null;
	}
	return array(
		'id'           => (int) $row['id'],
		'message_id'   => null === $row['message_id'] ? null : (int) $row['message_id'],
		'processed_at' => null === $row['processed_at'] ? null : (int) $row['processed_at'],
	);
}

/**
 * Marks one event as processed.
 *
 * @param int $event_id Event row id.
 */
function mlab_smtp_webhook_mark_processed( int $event_id ): bool {
	return false !== mlab_smtp_store_run(
		static function () use ( $event_id ) {
			global $wpdb;
			return $wpdb->query( $wpdb->prepare( 'UPDATE %i SET processed_at = %d WHERE id = %d AND processed_at IS NULL', mlab_smtp_table( 'events' ), mlab_smtp_store_now(), $event_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
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
			return $wpdb->get_results( $wpdb->prepare( "SELECT id, provider, type, detail FROM %i WHERE message_id IS NULL AND provider IN ('resend','resend-fallback') ORDER BY occurred_at ASC, id ASC LIMIT 200", mlab_smtp_table( 'events' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
		},
		array()
	);
	$count = 0;
	foreach ( (array) $rows as $row ) {
		$info    = json_decode( (string) $row['detail'], true );
		$message = is_array( $info ) ? mlab_smtp_webhook_match( (string) ( $info['email_id'] ?? '' ), (string) ( $info['mlab_id'] ?? '' ), (string) $row['provider'] ) : null;
		if ( $message && mlab_smtp_webhook_attach( (int) $row['id'], $message ) ) {
			if ( ! in_array( mlab_smtp_webhook_apply( (string) $row['type'], $message ), array( 'error', 'deferred' ), true ) ) {
				mlab_smtp_webhook_mark_processed( (int) $row['id'] );
			}
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
			return $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE message_id IS NULL AND provider IN ('resend','resend-fallback') AND received_at < %d", mlab_smtp_table( 'events' ), mlab_smtp_store_now() - MLAB_SMTP_WEBHOOK_ORPHAN_TTL ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
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
			return $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(received_at) FROM %i WHERE provider = %s', mlab_smtp_table( 'events' ), $provider ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
		},
		0
	);
}
