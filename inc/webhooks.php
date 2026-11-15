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
	try {
		$row = $row ?? mlab_smtp_webhook_store( $provider, $event_id, (string) $payload['type'], $payload );
		if ( ! $row ) {
			return mlab_smtp_webhook_error( 'mlab_smtp_webhook_failed', 500 );
		}
		/**
		 * Fires while an event is processed. An exception makes the delivery fail so Resend repeats it. A delivery
		 * that fails after this point is processed again, so the hook can run more than once for the same event:
		 * handlers must be idempotent (at least once, not exactly once).
		 *
		 * @param string   $type     Event type.
		 * @param int|null $message  Message id, null if not matched yet.
		 * @param string   $provider resend or resend-fallback.
		 */
		do_action( 'mlab_smtp_webhook_event', (string) $payload['type'], $row['message_id'], $provider );
		$result = mlab_smtp_webhook_settle( $row );
	} catch ( Throwable $e ) {
		return mlab_smtp_webhook_error( 'mlab_smtp_webhook_failed', 500 );
	}
	if ( 'error' === $result ) {
		return mlab_smtp_webhook_error( 'mlab_smtp_webhook_failed', 500 );
	}
	// Stored but not final (not matched yet, or the message is being sent): the background run takes it from here.
	return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
}

/**
 * Applies the status effect of a stored event and records the outcome. «processed» is set only after the effect is
 * applied or known to need no change; everything else keeps the event due for the background run, with a growing delay.
 *
 * @param array $event Event row: id, provider, type, message_id, detail, tries; optional hint (message id found by a batch match).
 * @return string changed, unchanged, deferred (message is being sent), unmatched or error.
 */
function mlab_smtp_webhook_settle( array $event ): string {
	$message = ! empty( $event['message_id'] ) ? (int) $event['message_id'] : null;
	if ( ! $message ) {
		$info    = json_decode( (string) ( $event['detail'] ?? '' ), true );
		$message = ! empty( $event['hint'] ) ? (int) $event['hint'] : ( is_array( $info ) ? mlab_smtp_webhook_match( (string) ( $info['email_id'] ?? '' ), (string) ( $info['mlab_id'] ?? '' ), (string) $event['provider'] ) : null );
		if ( $message && ! mlab_smtp_webhook_attach( (int) $event['id'], $message ) ) {
			$result = 'error';
		}
	}
	if ( ! isset( $result ) ) {
		$result = mlab_smtp_webhook_apply( (string) $event['type'], $message );
	}
	if ( in_array( $result, array( 'changed', 'unchanged' ), true ) && ! mlab_smtp_store_mark_events_processed( array( (int) $event['id'] ) ) ) {
		$result = 'error';
	}
	if ( ! in_array( $result, array( 'changed', 'unchanged' ), true ) ) {
		mlab_smtp_webhook_defer( array( (int) $event['id'] ), (int) ( $event['tries'] ?? 0 ) );
	}
	return $result;
}

/**
 * Delays the next background attempt for events: 1 minute, doubling, at most 6 hours.
 *
 * @param int[] $event_ids Event row ids (same number of attempts so far).
 * @param int   $tries     Attempts so far.
 */
function mlab_smtp_webhook_defer( array $event_ids, int $tries ): void {
	$event_ids = array_values( array_filter( array_map( 'intval', $event_ids ) ) );
	if ( ! $event_ids ) {
		return;
	}
	$delay = (int) min( 6 * HOUR_IN_SECONDS, MINUTE_IN_SECONDS * ( 2 ** min( 10, $tries ) ) );
	mlab_smtp_store_run(
		static function () use ( $event_ids, $delay ) {
			global $wpdb;
			$in = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );
			return $wpdb->query( $wpdb->prepare( "UPDATE %i SET retry_at = %d, tries = tries + 1 WHERE processed_at IS NULL AND id IN ($in)", array_merge( array( mlab_smtp_table( 'events' ), mlab_smtp_store_now() + $delay ), $event_ids ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Fixed table name, placeholders only.
		}
	);
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
			'ref_email'   => $info['email_id'],
			'ref_uuid'    => $info['mlab_id'],
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
 * @param array        $row      Message row.
 * @param string       $provider resend (primary route) or resend-fallback (fallback route).
 * @param array[]|null $attempts Attempts of the message (route, provider); read from the store when null.
 */
function mlab_smtp_webhook_route_fits( array $row, string $provider, ?array $attempts = null ): bool {
	$route    = 'resend-fallback' === $provider ? 'fallback' : 'primary';
	$attempts = $attempts ?? mlab_smtp_store_attempts( (int) $row['id'] );
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
			return $wpdb->get_row( $wpdb->prepare( 'SELECT id, provider, type, message_id, processed_at, tries, detail FROM %i WHERE provider = %s AND event_id = %s', mlab_smtp_table( 'events' ), $provider, $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
		},
		null
	);
	return is_array( $row ) ? mlab_smtp_webhook_normalize_row( $row ) : null;
}

/**
 * Typed event row.
 *
 * @param array $row Database row.
 * @return array{id:int, provider:string, type:string, message_id:?int, processed_at:?int, tries:int, detail:string}
 */
function mlab_smtp_webhook_normalize_row( array $row ): array {
	return array(
		'id'           => (int) $row['id'],
		'provider'     => (string) $row['provider'],
		'type'         => (string) $row['type'],
		'message_id'   => empty( $row['message_id'] ) ? null : (int) $row['message_id'],
		'processed_at' => null === ( $row['processed_at'] ?? null ) ? null : (int) $row['processed_at'],
		'tries'        => (int) ( $row['tries'] ?? 0 ),
		'detail'       => (string) ( $row['detail'] ?? '' ),
	);
}

/**
 * Background run over stored events that are not processed yet (not matched, matched but failed, or waiting for a
 * message that was being sent). Fair and bounded: events come in order of their next due time, an event that fails
 * goes to the back with a growing delay, so permanent orphans never crowd out later ones. No OFFSET paging.
 * Messages and attempts of the batch are read with one query each.
 *
 * @param int   $limit   Maximum number of events per run.
 * @param float $seconds Time budget in seconds.
 * @return array{checked:int, processed:int, matched:int}
 */
function mlab_smtp_webhook_reprocess( int $limit = 50, float $seconds = 4.0 ): array {
	$stats   = array(
		'checked'   => 0,
		'processed' => 0,
		'matched'   => 0,
	);
	$rows    = mlab_smtp_store_run(
		static function () use ( $limit ) {
			global $wpdb;
			return $wpdb->get_results( $wpdb->prepare( 'SELECT id, provider, type, message_id, processed_at, tries, detail FROM %i WHERE processed_at IS NULL AND retry_at <= %d ORDER BY retry_at ASC, id ASC LIMIT %d', mlab_smtp_table( 'events' ), mlab_smtp_store_now(), max( 1, $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
		},
		array()
	);
	$events  = array_map( 'mlab_smtp_webhook_normalize_row', (array) $rows );
	$matches = mlab_smtp_webhook_match_many( $events );
	$start   = microtime( true );
	$waiting = array(); // tries => event ids that still have no message: one update per group instead of one per event.
	foreach ( $events as $event ) {
		if ( microtime( true ) - $start > $seconds ) {
			break;
		}
		++$stats['checked'];
		$was_open = ! $event['message_id'];
		if ( $was_open && empty( $matches[ $event['id'] ] ) ) {
			$waiting[ $event['tries'] ][] = $event['id'];
			continue;
		}
		try {
			$event['hint'] = $matches[ $event['id'] ] ?? null;
			$result        = mlab_smtp_webhook_settle( $event );
		} catch ( Throwable $e ) {
			mlab_smtp_webhook_defer( array( $event['id'] ), $event['tries'] ); // One broken event does not stop the others.
			continue;
		}
		$stats['matched']   += $was_open ? 1 : 0;
		$stats['processed'] += in_array( $result, array( 'changed', 'unchanged' ), true ) ? 1 : 0;
	}
	foreach ( $waiting as $tries => $ids ) {
		mlab_smtp_webhook_defer( $ids, (int) $tries );
	}
	return $stats;
}

/**
 * Matches the unmatched events of a batch against messages with two queries (by uuid and by provider id) plus one for
 * the attempts, instead of several queries per event. An event signed for the other Resend account does not match.
 *
 * @param array[] $events Normalized event rows.
 * @return array<int,int> Event id => message id.
 */
function mlab_smtp_webhook_match_many( array $events ): array {
	$refs = array();
	foreach ( $events as $event ) {
		if ( $event['message_id'] ) {
			continue;
		}
		$info = json_decode( $event['detail'], true );
		if ( is_array( $info ) ) {
			$refs[ $event['id'] ] = array( (string) ( $info['mlab_id'] ?? '' ), (string) ( $info['email_id'] ?? '' ) );
		}
	}
	if ( ! $refs ) {
		return array();
	}
	$uuids  = array_values( array_unique( array_map( static fn( $r ) => 32 === strlen( $r[0] ) ? substr( $r[0], 0, 8 ) . '-' . substr( $r[0], 8, 4 ) . '-' . substr( $r[0], 12, 4 ) . '-' . substr( $r[0], 16, 4 ) . '-' . substr( $r[0], 20 ) : '', $refs ) ) );
	$emails = array_values( array_unique( array_column( $refs, 1 ) ) );
	$rows   = mlab_smtp_webhook_messages_by_refs( array_filter( $uuids ), array_filter( $emails ) );
	$att    = mlab_smtp_webhook_attempts_for( array_keys( $rows ) );
	$out    = array();
	foreach ( $refs as $event_id => $ref ) {
		$uuid = 32 === strlen( $ref[0] ) ? substr( $ref[0], 0, 8 ) . '-' . substr( $ref[0], 8, 4 ) . '-' . substr( $ref[0], 12, 4 ) . '-' . substr( $ref[0], 16, 4 ) . '-' . substr( $ref[0], 20 ) : '';
		$row  = null;
		foreach ( $rows as $candidate ) {
			if ( ( '' !== $uuid && $candidate['uuid'] === $uuid ) || ( '' === $uuid && '' !== $ref[1] && $candidate['provider_msg_id'] === $ref[1] ) ) {
				$row = $candidate;
				break;
			}
		}
		if ( ! $row && '' !== $ref[1] ) {
			foreach ( $rows as $candidate ) {
				if ( $candidate['provider_msg_id'] === $ref[1] ) {
					$row = $candidate;
					break;
				}
			}
		}
		$provider = '';
		foreach ( $events as $event ) {
			if ( $event['id'] === $event_id ) {
				$provider = $event['provider'];
			}
		}
		if ( $row && mlab_smtp_webhook_route_fits( $row, $provider, $att[ (int) $row['id'] ] ?? array() ) ) {
			$out[ $event_id ] = (int) $row['id'];
		}
	}
	return $out;
}

/**
 * Messages for a set of uuids and provider ids, one query.
 *
 * @param string[] $uuids  Message uuids.
 * @param string[] $emails Provider message ids.
 * @return array<int,array> Id => row.
 */
function mlab_smtp_webhook_messages_by_refs( array $uuids, array $emails ): array {
	if ( ! $uuids && ! $emails ) {
		return array();
	}
	$rows = mlab_smtp_store_run(
		static function () use ( $uuids, $emails ) {
			global $wpdb;
			$conditions = array();
			$args       = array( mlab_smtp_table( 'messages' ) );
			if ( $uuids ) {
				$conditions[] = 'uuid IN (' . implode( ',', array_fill( 0, count( $uuids ), '%s' ) ) . ')';
				$args         = array_merge( $args, array_values( $uuids ) );
			}
			if ( $emails ) {
				$conditions[] = 'provider_msg_id IN (' . implode( ',', array_fill( 0, count( $emails ), '%s' ) ) . ')';
				$args         = array_merge( $args, array_values( $emails ) );
			}
			return $wpdb->get_results( $wpdb->prepare( 'SELECT id, uuid, provider_msg_id, fallback FROM %i WHERE ' . implode( ' OR ', $conditions ), $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name, placeholders only.
		},
		array()
	);
	$out  = array();
	foreach ( (array) $rows as $row ) {
		$out[ (int) $row['id'] ] = $row;
	}
	return $out;
}

/**
 * Attempts (route, provider) of several messages, one query.
 *
 * @param int[] $message_ids Message ids.
 * @return array<int,array[]> Message id => attempts.
 */
function mlab_smtp_webhook_attempts_for( array $message_ids ): array {
	if ( ! $message_ids ) {
		return array();
	}
	$rows = mlab_smtp_store_run(
		static function () use ( $message_ids ) {
			global $wpdb;
			$in = implode( ',', array_fill( 0, count( $message_ids ), '%d' ) );
			return $wpdb->get_results( $wpdb->prepare( "SELECT message_id, route, provider FROM %i WHERE message_id IN ($in)", array_merge( array( mlab_smtp_table( 'attempts' ) ), array_map( 'intval', $message_ids ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name, placeholders only.
		},
		array()
	);
	$out  = array();
	foreach ( (array) $rows as $row ) {
		$out[ (int) $row['message_id'] ][] = $row;
	}
	return $out;
}

/**
 * Targeted run after a message got its provider id: only events that carry its uuid or provider id (indexed columns).
 * No scan of other events.
 *
 * @param int $message_id Message id.
 */
function mlab_smtp_webhook_rematch_message( int $message_id ): void {
	$message = mlab_smtp_store_get( $message_id );
	if ( ! $message ) {
		return;
	}
	$uuid32 = str_replace( '-', '', strtolower( (string) $message['uuid'] ) );
	$email  = (string) ( $message['provider_msg_id'] ?? '' );
	$rows   = mlab_smtp_store_run(
		static function () use ( $uuid32, $email ) {
			global $wpdb;
			return $wpdb->get_results( $wpdb->prepare( "SELECT id, provider, type, message_id, processed_at, tries, detail FROM %i WHERE processed_at IS NULL AND ( ref_uuid = %s OR ( %s <> '' AND ref_email = %s ) ) ORDER BY id ASC LIMIT 50", mlab_smtp_table( 'events' ), $uuid32, $email, $email ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
		},
		array()
	);
	foreach ( (array) $rows as $row ) {
		try {
			mlab_smtp_webhook_settle( mlab_smtp_webhook_normalize_row( $row ) );
		} catch ( Throwable $e ) {
			mlab_smtp_webhook_defer( array( (int) $row['id'] ), (int) $row['tries'] );
		}
	}
}

/**
 * Daily and background run: reprocesses due events and deletes orphans older than 7 days.
 *
 * @return int Number of events that got a message.
 */
function mlab_smtp_webhook_rematch(): int {
	$stats = mlab_smtp_webhook_reprocess();
	mlab_smtp_webhook_purge_orphans();
	return $stats['matched'];
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
