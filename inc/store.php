<?php
/**
 * Zugriff auf die Tabellen: Nachrichten anlegen, Versuche, Status, Warteschlange (Claim, Lease), Listen, Bereinigung, Nutzlast.
 *
 * Alle Werte laufen über $wpdb->prepare() oder die Insert/Update-Helfer von $wpdb. Spaltennamen kommen nur aus festen Listen.
 * Ist das Schema nicht bereit oder schlägt eine Abfrage fehl, liefern die Funktionen false, null oder leere Werte und werfen nie.
 *
 * @package mlab-smtp
 */

// Fixed table and column names are interpolated, every value is prepared. Schema calls are uncached by nature.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_STORE_COLUMNS = 'id, uuid, status, mode, route, provider, via, fallback, provider_msg_id, recipients, subject, attempts, max_attempts, next_attempt_at, lock_token, locked_until, error_class, last_error, (payload IS NOT NULL) AS has_payload, payload_expires_at, created_at, updated_at, completed_at, legacy_key';

/**
 * Runs a database operation quietly: no database error output, never throws, the fallback on failure.
 *
 * @param callable $operation Operation.
 * @param mixed    $fallback Value returned when the schema is not ready or the operation fails.
 * @return mixed
 */
function mlab_smtp_store_run( callable $operation, $fallback = false ) {
	global $wpdb;
	if ( ! mlab_smtp_db_ready() ) {
		return $fallback;
	}
	$previous = $wpdb->suppress_errors( true );
	try {
		$result = $operation();
	} catch ( Throwable $e ) {
		$result = $fallback;
	}
	$wpdb->suppress_errors( $previous );
	return $result;
}

/**
 * Statuses that only delivery events set (Anbieter-Ereignisse).
 *
 * @return string[]
 */
function mlab_smtp_store_event_statuses(): array {
	return array( 'bounced', 'complained' );
}

/**
 * Statuses that end the processing of a message.
 *
 * @return string[]
 */
function mlab_smtp_store_final_statuses(): array {
	return array( 'accepted', 'delivered', 'failed', 'unclear', 'partial', 'blocked', 'bounced', 'complained' );
}

/**
 * Rank of a status for delivery events (a lower rank is replaced by a higher one, never the other way round).
 *
 * @param string $status Status.
 * @return int|null Null when events must not touch this status.
 */
function mlab_smtp_store_rank( string $status ): ?int {
	$ranks = array(
		'processing' => 0,
		'unclear'    => 0,
		'partial'    => 0,
		'accepted'   => 1,
		'delivered'  => 2,
		'bounced'    => 3,
		'complained' => 3,
		'failed'     => 3,
	);
	return $ranks[ $status ] ?? null;
}

/**
 * Current time. A single place so tests can pass their own value to the functions that take one.
 */
function mlab_smtp_store_now(): int {
	return time();
}

/**
 * Cuts a text to a length (characters).
 *
 * @param string $text Text.
 * @param int    $max  Maximum length.
 */
function mlab_smtp_store_cut( string $text, int $max ): string {
	return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
}

/**
 * Masked, shortened free text for storage (subject, error).
 *
 * @param mixed $text Text.
 * @param int   $max  Maximum length.
 */
function mlab_smtp_store_text( $text, int $max = 1000 ): string {
	return mlab_smtp_store_cut( mlab_smtp_redact( sanitize_text_field( (string) $text ) ), $max );
}

/**
 * Per-site random key for the recipient index. Created once, not derived from the salts.
 */
function mlab_smtp_store_hmac_key(): string {
	$key = get_option( 'mlab_smtp_hmac_key', '' );
	if ( is_string( $key ) && strlen( $key ) >= 32 ) {
		return $key;
	}
	add_option( 'mlab_smtp_hmac_key', bin2hex( random_bytes( 32 ) ), '', false );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'mlab_smtp_hmac_key', 'options' );
	return (string) get_option( 'mlab_smtp_hmac_key', '' );
}

/**
 * HMAC-SHA256 of a lowercased, trimmed address.
 *
 * @param string $email Address.
 */
function mlab_smtp_store_hmac( string $email ): string {
	return hash_hmac( 'sha256', strtolower( trim( $email ) ), mlab_smtp_store_hmac_key() );
}

/**
 * Plain addresses from a list or a comma separated string (also "Name <a@b>").
 *
 * @param string|array $recipients Recipients.
 * @return string[] Unique, valid, lowercased.
 */
function mlab_smtp_store_addresses( $recipients ): array {
	$found = array();
	$items = is_array( $recipients ) ? $recipients : explode( ',', (string) $recipients );
	foreach ( $items as $item ) {
		$item  = (string) ( is_array( $item ) ? ( $item[0] ?? '' ) : $item );
		$email = preg_match( '/<([^>]+)>/', $item, $match ) ? $match[1] : $item;
		$email = strtolower( trim( $email ) );
		if ( is_email( $email ) ) {
			$found[ $email ] = $email;
		}
	}
	return array_values( $found );
}

/**
 * Typed SET clause from a field map. Only known columns, values prepared.
 *
 * @param array $fields Column => value (null writes NULL).
 */
function mlab_smtp_store_set_sql( array $fields ): string {
	global $wpdb;
	$ints  = array( 'fallback', 'attempts', 'max_attempts', 'next_attempt_at', 'locked_until', 'payload_expires_at', 'created_at', 'updated_at', 'completed_at' );
	$texts = array( 'uuid', 'status', 'mode', 'route', 'provider', 'via', 'provider_msg_id', 'recipients', 'subject', 'lock_token', 'error_class', 'last_error', 'payload', 'legacy_key' );
	$parts = array();
	foreach ( $fields as $column => $value ) {
		if ( ! in_array( $column, array_merge( $ints, $texts ), true ) ) {
			continue;
		}
		if ( null === $value ) {
			$parts[] = "`{$column}` = NULL";
		} else {
			$parts[] = $wpdb->prepare( in_array( $column, $ints, true ) ? "`{$column}` = %d" : "`{$column}` = %s", $value );
		}
	}
	return implode( ', ', $parts );
}

/**
 * Compare-and-set update of one message: runs only when the prepared WHERE condition still holds.
 *
 * @param int    $id    Message id.
 * @param array  $set   Fields to write.
 * @param string $where Additional prepared condition ("AND ...") or empty.
 * @param int    $now   Time for updated_at.
 * @return bool True when exactly one row changed.
 */
function mlab_smtp_store_cas( int $id, array $set, string $where = '', int $now = 0 ): bool {
	global $wpdb;
	$set['updated_at'] = $now > 0 ? $now : mlab_smtp_store_now();
	$sql               = $wpdb->prepare( 'UPDATE %i SET ' . mlab_smtp_store_set_sql( $set ) . ' WHERE id = %d', mlab_smtp_table( 'messages' ), $id ) . ' ' . $where;
	return 1 === $wpdb->query( $sql ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- $sql is prepared above; the SET list consists of allow-listed columns with prepared values.
}

/**
 * Creates a message and its recipient index.
 *
 * @param array $data route, provider, recipients (list or string), subject, mode (sync|queued), status, via, fallback,
 *                    max_attempts, next_attempt_at, lease (seconds, sets lock_token and locked_until), uuid, legacy_key, created_at, completed_at, last_error,
 *                    payload (encoded text from mlab_smtp_store_payload_encode(), stored with the row), payload_expires_at.
 * @return int|false Message id, false when the schema is not ready or the insert fails.
 */
function mlab_smtp_store_create( array $data ) {
	$now = mlab_smtp_store_now();
	$row = array(
		'uuid'            => (string) ( $data['uuid'] ?? wp_generate_uuid4() ),
		'status'          => (string) ( $data['status'] ?? ( empty( $data['lease'] ) ? 'queued' : 'processing' ) ),
		'mode'            => 'queued' === ( $data['mode'] ?? 'sync' ) ? 'queued' : 'sync',
		'route'           => sanitize_key( (string) ( $data['route'] ?? '' ) ),
		'provider'        => sanitize_key( (string) ( $data['provider'] ?? '' ) ),
		'via'             => mlab_smtp_store_text( $data['via'] ?? '', 64 ),
		'fallback'        => empty( $data['fallback'] ) ? 0 : 1,
		'recipients'      => mlab_smtp_store_cut( sanitize_text_field( implode( ', ', (array) ( $data['recipients'] ?? array() ) ) ), 2000 ),
		'subject'         => mlab_smtp_store_text( $data['subject'] ?? '', 500 ),
		'max_attempts'    => (int) ( $data['max_attempts'] ?? 6 ),
		'next_attempt_at' => (int) ( $data['next_attempt_at'] ?? $now ),
		'last_error'      => mlab_smtp_store_text( $data['last_error'] ?? '', 2000 ),
		'created_at'      => (int) ( $data['created_at'] ?? $now ),
		'updated_at'      => $now,
	);
	if ( in_array( $row['status'], mlab_smtp_store_final_statuses(), true ) ) {
		$row['completed_at'] = (int) ( $data['completed_at'] ?? $row['created_at'] );
	}
	if ( ! empty( $data['lease'] ) ) {
		$row['lock_token']   = wp_generate_password( 32, false );
		$row['locked_until'] = $now + (int) $data['lease'];
	}
	if ( isset( $data['legacy_key'] ) ) {
		$row['legacy_key'] = (string) $data['legacy_key'];
	}
	if ( isset( $data['payload'] ) && '' !== $data['payload'] ) {
		$row['payload'] = (string) $data['payload']; // Written with the row: a worker never sees a queued message without its payload.
		if ( ! empty( $data['payload_expires_at'] ) ) {
			$row['payload_expires_at'] = (int) $data['payload_expires_at'];
		}
	}
	$id = mlab_smtp_store_run(
		static function () use ( $row ) {
			global $wpdb;
			return false === $wpdb->insert( mlab_smtp_table( 'messages' ), $row ) ? false : (int) $wpdb->insert_id;
		}
	);
	if ( $id ) {
		mlab_smtp_store_add_recipients( (int) $id, (array) ( $data['recipients'] ?? array() ) );
	}
	return $id ? (int) $id : false;
}

/**
 * Writes the HMAC index rows of a message.
 *
 * @param int          $id         Message id.
 * @param string|array $recipients Recipients.
 */
function mlab_smtp_store_add_recipients( int $id, $recipients ): void {
	foreach ( mlab_smtp_store_addresses( $recipients ) as $email ) {
		mlab_smtp_store_run(
			static function () use ( $id, $email ) {
				global $wpdb;
				return $wpdb->insert(
					mlab_smtp_table( 'recipients' ),
					array(
						'message_id' => $id,
						'hmac'       => mlab_smtp_store_hmac( $email ),
					)
				);
			}
		);
	}
}

/**
 * Starts an attempt (call this before the network access, with request_sent_at set right before the request).
 *
 * @param int   $message_id Message id.
 * @param array $fields     route, provider, started_at, request_sent_at, idem_key, mail_message_id.
 * @return int|false Attempt id.
 */
function mlab_smtp_store_add_attempt( int $message_id, array $fields = array() ) {
	$now = mlab_smtp_store_now();
	$row = array(
		'message_id'      => $message_id,
		'route'           => sanitize_key( (string) ( $fields['route'] ?? '' ) ),
		'provider'        => sanitize_key( (string) ( $fields['provider'] ?? '' ) ),
		'started_at'      => (int) ( $fields['started_at'] ?? $now ),
		'error_text'      => '',
		'idem_key'        => mlab_smtp_store_cut( (string) ( $fields['idem_key'] ?? '' ), 64 ),
		'conn_hash'       => mlab_smtp_store_cut( (string) ( $fields['conn_hash'] ?? '' ), 64 ),
		'mail_message_id' => mlab_smtp_store_cut( (string) ( $fields['mail_message_id'] ?? '' ), 255 ),
	);
	if ( isset( $fields['request_sent_at'] ) ) {
		$row['request_sent_at'] = (int) $fields['request_sent_at'];
	}
	$id = mlab_smtp_store_run(
		static function () use ( $row, $message_id ) {
			global $wpdb;
			if ( false === $wpdb->insert( mlab_smtp_table( 'attempts' ), $row ) ) {
				return false;
			}
			$attempt = (int) $wpdb->insert_id;
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET attempts = attempts + 1 WHERE id = %d', mlab_smtp_table( 'messages' ), $message_id ) );
			return $attempt;
		}
	);
	return $id ? (int) $id : false;
}

/**
 * Marks that the request is about to leave (from here on a lost worker means "unclear").
 *
 * @param int $attempt_id Attempt id.
 * @param int $now        Time (default now).
 */
function mlab_smtp_store_mark_request_sent( int $attempt_id, int $now = 0 ): bool {
	return (bool) mlab_smtp_store_run(
		static function () use ( $attempt_id, $now ) {
			global $wpdb;
			return false !== $wpdb->update(
				mlab_smtp_table( 'attempts' ),
				array( 'request_sent_at' => $now > 0 ? $now : mlab_smtp_store_now() ),
				array( 'id' => $attempt_id )
			);
		}
	);
}

/**
 * Finishes an attempt with its outcome.
 *
 * @param int   $attempt_id Attempt id.
 * @param array $fields     outcome (accepted|temporary|permanent|unknown), http_status, error_code, error_text, provider_msg_id, retry_after.
 */
function mlab_smtp_store_finish_attempt( int $attempt_id, array $fields ): bool {
	$row = array( 'finished_at' => mlab_smtp_store_now() );
	foreach ( array( 'outcome', 'error_code', 'provider_msg_id' ) as $key ) {
		if ( isset( $fields[ $key ] ) ) {
			$row[ $key ] = mlab_smtp_store_cut( sanitize_text_field( (string) $fields[ $key ] ), 191 );
		}
	}
	foreach ( array( 'http_status', 'retry_after' ) as $key ) {
		if ( isset( $fields[ $key ] ) ) {
			$row[ $key ] = max( 0, (int) $fields[ $key ] );
		}
	}
	if ( isset( $fields['error_text'] ) ) {
		$row['error_text'] = mlab_smtp_store_text( $fields['error_text'], 2000 );
	}
	return (bool) mlab_smtp_store_run(
		static function () use ( $attempt_id, $row ) {
			global $wpdb;
			return false !== $wpdb->update( mlab_smtp_table( 'attempts' ), $row, array( 'id' => $attempt_id ) );
		}
	);
}

/**
 * Attempts of a message, oldest first.
 *
 * @param int $message_id Message id.
 * @return array[]
 */
function mlab_smtp_store_attempts( int $message_id ): array {
	return (array) mlab_smtp_store_run(
		static function () use ( $message_id ) {
			global $wpdb;
			return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE message_id = %d ORDER BY id ASC', mlab_smtp_table( 'attempts' ), $message_id ), ARRAY_A );
		},
		array()
	);
}

/**
 * Fields written with a final or pending status: completion time, payload expiry, lock release.
 *
 * @param string $status New status.
 * @param array  $fields Additional fields (error text is masked).
 * @param int    $now    Time.
 */
function mlab_smtp_store_status_fields( string $status, array $fields, int $now ): array {
	$set = array( 'status' => $status );
	foreach ( array( 'provider_msg_id', 'error_class', 'via', 'route', 'provider', 'fallback', 'next_attempt_at', 'attempts' ) as $key ) {
		if ( array_key_exists( $key, $fields ) ) {
			$set[ $key ] = $fields[ $key ];
		}
	}
	if ( isset( $fields['last_error'] ) ) {
		$set['last_error'] = mlab_smtp_store_text( $fields['last_error'], 2000 );
	}
	if ( 'processing' !== $status ) {
		$set['lock_token']   = null;
		$set['locked_until'] = null;
	}
	if ( in_array( $status, mlab_smtp_store_final_statuses(), true ) ) {
		$set['completed_at'] = $now;
	}
	if ( in_array( $status, array( 'accepted', 'delivered', 'partial', 'blocked' ), true ) ) {
		$set['payload']            = null;
		$set['payload_expires_at'] = null;
	} elseif ( in_array( $status, array( 'failed', 'unclear' ), true ) ) {
		$days                      = min( 14, max( 1, (int) apply_filters( 'mlab_smtp_failed_payload_days', 7 ) ) );
		$set['payload_expires_at'] = $now + $days * DAY_IN_SECONDS;
	}
	return $set;
}

/**
 * Sets the status of a message unconditionally (for the request that owns the row, e.g. synchronous sending).
 * With $keep_events a status that only a provider event sets (delivered, bounced, complained) is never replaced by a lower rank;
 * the other fields (payload clearing, lock release) are still written. Pending events are applied afterwards.
 *
 * @param int    $id          Message id.
 * @param string $status      Status from mlab_smtp_db_statuses().
 * @param array  $fields      provider_msg_id, error_class, last_error, via, route, provider, fallback, next_attempt_at.
 * @param int    $now         Time (default now).
 * @param bool   $keep_events Never downgrade an event status.
 */
function mlab_smtp_store_set_status( int $id, string $status, array $fields = array(), int $now = 0, bool $keep_events = false ): bool {
	$now = $now > 0 ? $now : mlab_smtp_store_now();
	if ( ! in_array( $status, mlab_smtp_db_statuses(), true ) ) {
		return false;
	}
	$ok = (bool) mlab_smtp_store_run(
		static function () use ( $id, $status, $fields, $now, $keep_events ) {
			global $wpdb;
			$set      = mlab_smtp_store_status_fields( $status, $fields, $now );
			$where    = '';
			$expected = $status;
			if ( $keep_events ) {
				$current = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', mlab_smtp_table( 'messages' ), $id ) );
				if ( in_array( $current, array( 'delivered', 'bounced', 'complained' ), true ) && (int) mlab_smtp_store_rank( $current ) > (int) mlab_smtp_store_rank( $status ) ) {
					unset( $set['status'], $set['completed_at'] );
					$where    = $wpdb->prepare( 'AND status = %s', $current );
					$expected = $current;
				}
			}
			if ( mlab_smtp_store_cas( $id, $set, $where, $now ) ) {
				return true;
			}
			// MySQL reports 0 affected rows when nothing changed (same values within one second): that is no failure.
			$current = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', mlab_smtp_table( 'messages' ), $id ) );
			return null !== $current && $expected === (string) $current; // A failed re-select (null) is no success.
		}
	);
	if ( $ok ) {
		mlab_smtp_store_apply_pending_events( $id );
	}
	return $ok;
}

/**
 * Applies the strongest stored provider event to a message that just left «processing». Events that arrived while the
 * worker or the synchronous request owned the row were stored but did not change the status.
 *
 * @param int $id Message id.
 * @return bool True when the status changed.
 */
function mlab_smtp_store_apply_pending_events( int $id ): bool {
	if ( ! function_exists( 'mlab_smtp_webhook_types' ) ) {
		return false;
	}
	$types   = array_filter( mlab_smtp_webhook_types() );
	$rows    = (array) mlab_smtp_store_run(
		static function () use ( $id ) {
			global $wpdb;
			return $wpdb->get_results( $wpdb->prepare( 'SELECT id, type, processed_at FROM %i WHERE message_id = %d', mlab_smtp_table( 'events' ), $id ), ARRAY_A );
		},
		array()
	);
	$best    = '';
	$pending = array(); // Only events that were read here are marked; one that arrives meanwhile stays open.
	foreach ( $rows as $row ) {
		$status = $types[ $row['type'] ] ?? '';
		if ( '' !== $status && (int) mlab_smtp_store_rank( $status ) > (int) mlab_smtp_store_rank( $best ) ) {
			$best = $status;
		}
		if ( null === $row['processed_at'] ) {
			$pending[] = (int) $row['id'];
		}
	}
	if ( '' === $best ) {
		$result = 'unchanged';
	} else {
		$result = mlab_smtp_store_apply_event_result( $id, $best );
	}
	if ( $pending && ! in_array( $result, array( 'error', 'deferred' ), true ) ) {
		mlab_smtp_store_mark_events_processed( $pending );
	}
	return 'changed' === $result;
}

/**
 * Marks events as processed (their status effect is applied or needs no change).
 *
 * @param int[] $event_ids Event row ids.
 * @return bool False when the update failed.
 */
function mlab_smtp_store_mark_events_processed( array $event_ids ): bool {
	$event_ids = array_values( array_filter( array_map( 'intval', $event_ids ) ) );
	if ( ! $event_ids ) {
		return true;
	}
	return false !== mlab_smtp_store_run(
		static function () use ( $event_ids ) {
			global $wpdb;
			$in = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );
			return $wpdb->query( $wpdb->prepare( "UPDATE %i SET processed_at = %d WHERE processed_at IS NULL AND id IN ($in)", array_merge( array( mlab_smtp_table( 'events' ), mlab_smtp_store_now() ), $event_ids ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name, placeholders only.
		}
	);
}

/**
 * Applies a delivery event status. Never downgrades: only a higher rank replaces the current one.
 *
 * @param int    $id     Message id.
 * @param string $status accepted, delivered, bounced, complained or failed.
 * @param int    $now    Time (default now).
 * @return bool True when the status changed.
 */
function mlab_smtp_store_apply_event_status( int $id, string $status, int $now = 0 ): bool {
	return 'changed' === mlab_smtp_store_apply_event_result( $id, $status, $now );
}

/**
 * Like mlab_smtp_store_apply_event_status(), but tells an expected «nothing to change» from a database error.
 *
 * - changed:   the status was raised.
 * - unchanged: nothing to do (the message has a status of equal or higher rank, or does not exist, or the status is unknown).
 * - deferred:  the row is processing; the event is applied after the completion (mlab_smtp_store_apply_pending_events).
 * - error:     a database error or an unconfirmed race; the caller must treat the event as not processed.
 *
 * @param int    $id     Message id.
 * @param string $status accepted, delivered, bounced, complained or failed.
 * @param int    $now    Time (default now).
 * @return string changed, unchanged, deferred or error.
 */
function mlab_smtp_store_apply_event_result( int $id, string $status, int $now = 0 ): string {
	$new = mlab_smtp_store_rank( $status );
	if ( null === $new || 'processing' === $status ) {
		return 'unchanged';
	}
	return (string) mlab_smtp_store_run(
		static function () use ( $id, $status, $new, $now ): string {
			global $wpdb;
			for ( $i = 0; $i < 3; $i++ ) {
				$current = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', mlab_smtp_table( 'messages' ), $id ) );
				if ( null === $current ) {
					return '' === (string) $wpdb->last_error ? 'unchanged' : 'error';
				}
				$rank = mlab_smtp_store_rank( (string) $current );
				if ( 'processing' === $current ) {
					return 'deferred';
				}
				if ( null === $rank || $rank >= $new ) {
					return 'unchanged';
				}
				$set = array(
					'status'       => $status,
					'completed_at' => $now > 0 ? $now : mlab_smtp_store_now(),
				);
				if ( mlab_smtp_store_cas( $id, $set, $wpdb->prepare( 'AND status = %s', $current ), $now ) ) {
					return 'changed';
				}
				if ( '' !== (string) $wpdb->last_error ) {
					return 'error';
				}
			}
			return 'error'; // The race could not be settled: not processed, the delivery is repeated.
		},
		'error'
	);
}

/**
 * Claims due messages for a worker. Candidates are selected first, then each row is taken with its own
 * compare-and-set UPDATE (affected rows = 1 means claimed). No UPDATE ... ORDER BY ... LIMIT.
 *
 * @param int $limit Maximum number of messages.
 * @param int $lease Lease in seconds (at least 300).
 * @param int $now   Time (default now).
 * @return array[] Claimed messages (with lock_token).
 */
function mlab_smtp_store_claim_batch( int $limit = 10, int $lease = 300, int $now = 0 ): array {
	$now     = $now > 0 ? $now : mlab_smtp_store_now();
	$lease   = max( 300, $lease );
	$ids     = (array) mlab_smtp_store_run(
		static function () use ( $limit, $now ) {
			global $wpdb;
			return $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM %i WHERE status IN ('queued','temp_failed') AND next_attempt_at <= %d AND (locked_until IS NULL OR locked_until < %d) ORDER BY next_attempt_at ASC, id ASC LIMIT %d",
					mlab_smtp_table( 'messages' ),
					$now,
					$now,
					max( 1, $limit )
				)
			);
		},
		array()
	);
	$claimed = array();
	foreach ( $ids as $id ) {
		$token = wp_generate_password( 32, false );
		$won   = mlab_smtp_store_run(
			static function () use ( $id, $token, $lease, $now ) {
				global $wpdb;
				$set   = array(
					'status'       => 'processing',
					'lock_token'   => $token,
					'locked_until' => $now + $lease,
				);
				$where = $wpdb->prepare( "AND status IN ('queued','temp_failed') AND (locked_until IS NULL OR locked_until < %d)", $now );
				return mlab_smtp_store_cas( (int) $id, $set, $where, $now );
			}
		);
		if ( $won ) {
			$claimed[] = mlab_smtp_store_get( (int) $id );
		}
	}
	return array_values( array_filter( $claimed ) );
}

/**
 * Completes a claimed message. Discarded when the lock token no longer matches (the worker lost its lease).
 *
 * @param int    $id     Message id.
 * @param string $token  Lock token from the claim.
 * @param string $status New status.
 * @param array  $fields See mlab_smtp_store_set_status().
 * @param int    $now    Time (default now).
 */
function mlab_smtp_store_complete( int $id, string $token, string $status, array $fields = array(), int $now = 0 ): bool {
	$now = $now > 0 ? $now : mlab_smtp_store_now();
	if ( '' === $token || ! in_array( $status, mlab_smtp_db_statuses(), true ) ) {
		return false;
	}
	$ok = (bool) mlab_smtp_store_run(
		static function () use ( $id, $token, $status, $fields, $now ) {
			global $wpdb;
			$where = $wpdb->prepare( "AND status = 'processing' AND lock_token = %s", $token );
			return mlab_smtp_store_cas( $id, mlab_smtp_store_status_fields( $status, $fields, $now ), $where, $now );
		}
	);
	if ( $ok && in_array( $status, mlab_smtp_store_final_statuses(), true ) ) {
		mlab_smtp_store_apply_pending_events( $id );
	}
	return $ok;
}

/**
 * Gives a claimed message back (for another try later). Only with the matching lock token.
 *
 * @param int    $id              Message id.
 * @param string $token           Lock token.
 * @param int    $next_attempt_at Time of the next try.
 * @param array  $fields          last_error, error_class.
 * @param string $status          queued or temp_failed.
 */
function mlab_smtp_store_release( int $id, string $token, int $next_attempt_at, array $fields = array(), string $status = 'temp_failed' ): bool {
	$status = 'queued' === $status ? 'queued' : 'temp_failed';
	$fields = array_merge( $fields, array( 'next_attempt_at' => $next_attempt_at ) );
	return mlab_smtp_store_complete( $id, $token, $status, $fields );
}

/**
 * Sweeps expired leases of processing messages. A message with no attempt that reached request_sent_at goes back
 * to the queue (it has a payload), everything else becomes "unclear" because the provider may have accepted it.
 *
 * @param int $now   Time (default now).
 * @param int $limit Maximum number of rows per call.
 * @return array<string,int> Counts: requeued, unclear, and failed (only when present).
 */
function mlab_smtp_store_sweep( int $now = 0, int $limit = 100 ): array {
	$now   = $now > 0 ? $now : mlab_smtp_store_now();
	$count = array(
		'requeued' => 0,
		'unclear'  => 0,
	);
	$ids   = (array) mlab_smtp_store_run(
		static function () use ( $now, $limit ) {
			global $wpdb;
			return $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM %i WHERE status = 'processing' AND ((locked_until IS NOT NULL AND locked_until < %d) OR (locked_until IS NULL AND updated_at < %d)) LIMIT %d",
					mlab_smtp_table( 'messages' ),
					$now,
					$now - 300,
					max( 1, $limit )
				)
			);
		},
		array()
	);
	foreach ( $ids as $id ) {
		$outcome = mlab_smtp_store_sweep_one( (int) $id, $now );
		if ( $outcome ) {
			$count[ $outcome ] = ( $count[ $outcome ] ?? 0 ) + 1;
		}
	}
	return $count;
}

/**
 * Sweeps one message with an expired lease.
 *
 * @param int $id  Message id.
 * @param int $now Time.
 * @return string requeued, unclear, failed or empty when the row was not taken over.
 */
function mlab_smtp_store_sweep_one( int $id, int $now ): string {
	$message = mlab_smtp_store_get( $id );
	if ( ! $message ) {
		return '';
	}
	// Only attempts of the current claim count (started since the claim, finished or not): earlier rounds are settled already.
	// A finished attempt with a definite refusal (temporary, permanent) means nothing was delivered.
	$claim    = (int) $message['updated_at'];
	$attempts = (array) mlab_smtp_store_run(
		static function () use ( $id, $claim ) {
			global $wpdb;
			return $wpdb->get_results( $wpdb->prepare( "SELECT route, provider, outcome, provider_msg_id FROM %i WHERE message_id = %d AND request_sent_at IS NOT NULL AND started_at >= %d AND outcome NOT IN ('temporary','permanent') ORDER BY id ASC", mlab_smtp_table( 'attempts' ), $id, $claim ), ARRAY_A );
		},
		array()
	);
	$sent     = count( $attempts );
	$accepted = array_values( array_filter( $attempts, static fn( $a ): bool => 'accepted' === $a['outcome'] ) );
	$partial  = array_values( array_filter( $attempts, static fn( $a ): bool => 'partial' === $a['outcome'] ) );
	$queued   = 0 === $sent && 'queued' === $message['mode'] && ! empty( $message['has_payload'] );
	$text     = '';
	if ( $accepted || $partial ) {
		$pool = $accepted ? $accepted : $partial;
		$hit  = (array) end( $pool );
		$set  = mlab_smtp_store_status_fields(
			$accepted ? 'accepted' : 'partial',
			array(
				'provider_msg_id' => (string) $hit['provider_msg_id'],
				'via'             => (string) $hit['provider'],
				'fallback'        => 'fallback' === $hit['route'] ? 1 : 0,
			),
			$now
		);
	} elseif ( $queued && mlab_smtp_store_swept_count( $id ) >= max( 1, (int) $message['max_attempts'] ) ) {
		$queued = false; // The worker died again and again before any request: stop, a person decides.
		$text   = __( 'Der Worker wurde wiederholt vor dem Senden unterbrochen, die Nachricht wird nicht weiter versucht.', 'mlab-smtp' );
		$set    = mlab_smtp_store_status_fields(
			'failed',
			array(
				'error_class' => 'permanent',
				'last_error'  => $text,
			),
			$now
		);
	} elseif ( $queued ) {
		$set = array(
			'status'       => 'queued',
			'lock_token'   => null,
			'locked_until' => null,
		);
	} else {
		$text = __( 'Der Versand wurde unterbrochen, ob der Anbieter die Nachricht angenommen hat, ist unklar.', 'mlab-smtp' );
		$set  = mlab_smtp_store_status_fields( 'unclear', array( 'last_error' => $text ), $now );
	}
	$won = mlab_smtp_store_run(
		static function () use ( $id, $set, $now ) {
			global $wpdb;
			$where = $wpdb->prepare( "AND status = 'processing' AND ((locked_until IS NOT NULL AND locked_until < %d) OR (locked_until IS NULL AND updated_at < %d))", $now, $now - 300 );
			return mlab_smtp_store_cas( $id, $set, $where, $now );
		}
	);
	if ( $won && $queued ) {
		mlab_smtp_store_note_sweep( $id, $now );
	}
	if ( $won && ! $queued ) {
		mlab_smtp_store_swept( $message, $set );
	}
	return $won ? ( $queued ? 'requeued' : (string) $set['status'] ) : '';
}

/**
 * Follow-up of a sweep that ended a message: tells the extension points, matches waiting events, applies stored events.
 *
 * @param array $message Row before the sweep.
 * @param array $set     Fields written (status, provider_msg_id).
 */
function mlab_smtp_store_swept( array $message, array $set ): void {
	$id     = (int) $message['id'];
	$status = (string) $set['status'];
	if ( function_exists( 'mlab_smtp_engine_changed' ) ) {
		mlab_smtp_engine_changed( $id, $status, 'processing', 'worker' );
	}
	if ( ! empty( $set['provider_msg_id'] ) && function_exists( 'mlab_smtp_webhook_rematch_message' ) ) {
		mlab_smtp_webhook_rematch_message( $id );
	}
	if ( in_array( $status, array( 'accepted', 'partial' ), true ) ) {
		do_action(
			'accepted' === $status ? 'mlab_smtp_message_accepted' : 'mlab_smtp_message_failed',
			array(
				'id'              => $id,
				'uuid'            => (string) $message['uuid'],
				'status'          => $status,
				'class'           => 'accepted' === $status ? 'accepted' : 'partial',
				'mode'            => (string) $message['mode'],
				'via'             => (string) ( $set['via'] ?? '' ),
				'fallback'        => ! empty( $set['fallback'] ),
				'provider_msg_id' => (string) ( $set['provider_msg_id'] ?? '' ),
				'error'           => '',
			)
		);
	}
	mlab_smtp_store_apply_pending_events( $id ); // An event that arrived while the worker owned the row.
}

/**
 * Number of times the sweep put a message back into the queue (marker attempts with outcome «swept»).
 *
 * @param int $id Message id.
 */
function mlab_smtp_store_swept_count( int $id ): int {
	return (int) mlab_smtp_store_run(
		static function () use ( $id ) {
			global $wpdb;
			return $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE message_id = %d AND outcome = 'swept'", mlab_smtp_table( 'attempts' ), $id ) );
		},
		0
	);
}

/**
 * Remembers a requeue by the sweep as a marker attempt (no route, so it is no round and no request).
 *
 * @param int $id  Message id.
 * @param int $now Time.
 */
function mlab_smtp_store_note_sweep( int $id, int $now ): void {
	mlab_smtp_store_run(
		static function () use ( $id, $now ) {
			global $wpdb;
			return $wpdb->insert(
				mlab_smtp_table( 'attempts' ),
				array(
					'message_id'  => $id,
					'started_at'  => $now,
					'finished_at' => $now,
					'outcome'     => 'swept',
					'error_code'  => 'lease_expired',
					'error_text'  => '',
				)
			);
		}
	);
}

/**
 * One message (without payload).
 *
 * @param int $id Message id.
 * @return array|null
 */
function mlab_smtp_store_get( int $id ): ?array {
	$row = mlab_smtp_store_run(
		static function () use ( $id ) {
			global $wpdb;
			return $wpdb->get_row( $wpdb->prepare( 'SELECT ' . MLAB_SMTP_STORE_COLUMNS . ' FROM %i WHERE id = %d', mlab_smtp_table( 'messages' ), $id ), ARRAY_A );
		},
		null
	);
	return is_array( $row ) ? $row : null;
}

/**
 * Which of the given legacy keys already exist (resumable migration).
 *
 * @param string[] $keys Keys.
 * @return string[]
 */
function mlab_smtp_store_existing_legacy_keys( array $keys ): array {
	$found = array();
	foreach ( array_chunk( array_values( $keys ), 200 ) as $chunk ) {
		$rows  = (array) mlab_smtp_store_run(
			static function () use ( $chunk ) {
				global $wpdb;
				return $wpdb->get_col( $wpdb->prepare( 'SELECT legacy_key FROM %i WHERE legacy_key IN (' . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ')', array_merge( array( mlab_smtp_table( 'messages' ) ), $chunk ) ) );
			},
			array()
		);
		$found = array_merge( $found, $rows );
	}
	return $found;
}

/**
 * One message by uuid or by provider message id.
 *
 * @param string $value Value.
 * @param string $field uuid or provider_msg_id.
 * @return array|null
 */
function mlab_smtp_store_find( string $value, string $field = 'uuid' ): ?array {
	$field = 'provider_msg_id' === $field ? 'provider_msg_id' : 'uuid';
	if ( '' === $value ) {
		return null;
	}
	$row = mlab_smtp_store_run(
		static function () use ( $value, $field ) {
			global $wpdb;
			return $wpdb->get_row( $wpdb->prepare( 'SELECT ' . MLAB_SMTP_STORE_COLUMNS . ' FROM %i WHERE %i = %s ORDER BY id DESC LIMIT 1', mlab_smtp_table( 'messages' ), $field, $value ), ARRAY_A );
		},
		null
	);
	return is_array( $row ) ? $row : null;
}

/**
 * WHERE clause of a list query.
 *
 * @param array $args status (string or list), group (ok|bad), search, since.
 */
function mlab_smtp_store_where( array $args ): string {
	global $wpdb;
	$where = array( '1=1' );
	$bad   = "'failed','blocked','temp_failed','unclear','bounced','complained'";
	if ( ! empty( $args['status'] ) ) {
		$list    = array_intersect( (array) $args['status'], array_merge( mlab_smtp_db_statuses(), mlab_smtp_store_event_statuses() ) );
		$where[] = $list ? $wpdb->prepare( 'status IN (' . implode( ',', array_fill( 0, count( $list ), '%s' ) ) . ')', array_values( $list ) ) : '1=0';
	}
	if ( 'bad' === ( $args['group'] ?? '' ) ) {
		$where[] = "(status IN ({$bad}) OR fallback = 1)";
	} elseif ( 'ok' === ( $args['group'] ?? '' ) ) {
		$where[] = "(status NOT IN ({$bad}) AND fallback = 0)";
	}
	if ( ! empty( $args['since'] ) ) {
		$where[] = $wpdb->prepare( 'created_at >= %d', (int) $args['since'] );
	}
	if ( '' !== (string) ( $args['search'] ?? '' ) ) {
		$like    = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		$where[] = $wpdb->prepare(
			'(recipients LIKE %s OR subject LIKE %s OR last_error LIKE %s OR uuid = %s OR id IN (SELECT message_id FROM %i WHERE hmac = %s))',
			$like,
			$like,
			$like,
			(string) $args['search'],
			mlab_smtp_table( 'recipients' ),
			mlab_smtp_store_hmac( (string) $args['search'] )
		);
	}
	return implode( ' AND ', $where );
}

/**
 * Messages, newest first, with pagination (without payload).
 *
 * @param array $args page, per_page (max 200), status, group (ok|bad), search (recipient, subject, error), since.
 * @return array{items:array[], total:int, pages:int, page:int}
 */
function mlab_smtp_store_list( array $args = array() ): array {
	$per_page = min( 200, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
	$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
	$empty    = array(
		'items' => array(),
		'total' => 0,
		'pages' => 0,
		'page'  => $page,
	);
	return (array) mlab_smtp_store_run(
		static function () use ( $args, $per_page, $page, $empty ) {
			global $wpdb;
			$where = mlab_smtp_store_where( $args );
			$table = mlab_smtp_table( 'messages' );
			// $where is built by mlab_smtp_store_where(): allow-listed columns, every value prepared.
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", $table ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See above.
			$items = $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where: see above.
				$wpdb->prepare( 'SELECT ' . MLAB_SMTP_STORE_COLUMNS . " FROM %i WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $table, $per_page, ( $page - 1 ) * $per_page ),
				ARRAY_A
			);
			return array(
				'items' => (array) $items,
				'total' => $total,
				'pages' => (int) ceil( $total / $per_page ),
				'page'  => $page,
			);
		},
		$empty
	);
}

/**
 * Number of messages per status (all statuses present, zero when none).
 *
 * @return array<string,int>
 */
function mlab_smtp_store_counts(): array {
	$counts = array_fill_keys( mlab_smtp_db_statuses(), 0 );
	$rows   = (array) mlab_smtp_store_run(
		static function () {
			global $wpdb;
			return $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', mlab_smtp_table( 'messages' ) ), ARRAY_A );
		},
		array()
	);
	foreach ( $rows as $row ) {
		$counts[ $row['status'] ] = (int) $row['n'];
	}
	return $counts;
}

/**
 * Ids of the messages that went to an address (lookup through the HMAC index).
 *
 * @param string $email  Address.
 * @param int    $limit  Maximum number of ids.
 * @param int    $offset Offset.
 * @return int[]
 */
function mlab_smtp_store_ids_by_email( string $email, int $limit = 50, int $offset = 0 ): array {
	return (array) mlab_smtp_store_ids_by_email_checked( $email, $limit, $offset );
}

/**
 * Like mlab_smtp_store_ids_by_email(), but null when the lookup failed (an empty list means: nothing found).
 *
 * @param string $email  Address.
 * @param int    $limit  Maximum number of ids.
 * @param int    $offset Offset.
 * @return int[]|null
 */
function mlab_smtp_store_ids_by_email_checked( string $email, int $limit = 50, int $offset = 0 ): ?array {
	$hmac = mlab_smtp_store_hmac( $email );
	$ids  = mlab_smtp_store_run(
		static function () use ( $hmac, $limit, $offset ) {
			global $wpdb;
			$col = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT message_id FROM %i WHERE hmac = %s ORDER BY message_id ASC LIMIT %d OFFSET %d', mlab_smtp_table( 'recipients' ), $hmac, max( 1, $limit ), max( 0, $offset ) ) );
			return '' === (string) $wpdb->last_error ? $col : null;
		},
		null
	);
	return is_array( $ids ) ? array_map( 'intval', $ids ) : null;
}

/**
 * Deletes messages with their attempts, events and recipient index rows. Returns only the number; use
 * mlab_smtp_store_delete_result() to tell a failure from «nothing to delete».
 *
 * @param int[] $ids Message ids.
 * @return int Number of deleted messages (0 on failure).
 */
function mlab_smtp_store_delete_ids( array $ids ): int {
	return mlab_smtp_store_delete_result( $ids )['deleted'];
}

/**
 * True when every table of the store can take part in a transaction (InnoDB). Checked once per request.
 */
function mlab_smtp_store_transactional(): bool {
	/**
	 * Lets a test or a special setup declare that transactions are not available for the store tables.
	 *
	 * @param bool|null $supported Null to detect.
	 */
	$forced = apply_filters( 'mlab_smtp_store_transactional', null );
	if ( null !== $forced ) {
		return (bool) $forced;
	}
	static $result = null;
	if ( null === $result ) {
		$result = (bool) mlab_smtp_store_run(
			static function (): bool {
				global $wpdb;
				if ( ! preg_match( '/mysql|maria/i', (string) $wpdb->db_server_info() ) ) {
					return true; // The SQLite driver of the test environment wraps transactions itself.
				}
				$names = array_map( 'mlab_smtp_table', array( 'messages', 'attempts', 'events', 'recipients' ) );
				$in    = implode( ',', array_fill( 0, count( $names ), '%s' ) );
				$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND ENGINE = 'InnoDB' AND TABLE_NAME IN ($in)", $names ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders only.
				return count( $names ) === (int) $count;
			}
		);
	}
	return $result;
}

/**
 * True when a transaction of another party is already open on this connection (known for MariaDB; MySQL has no way to tell).
 */
function mlab_smtp_store_foreign_transaction(): bool {
	global $wpdb;
	$value = $wpdb->get_var( 'SELECT @@in_transaction' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Connection state.
	return '1' === (string) $value;
}

/**
 * Deletes messages with their attempts, events and recipient index rows together. Every statement is checked.
 * With InnoDB the deletion is one transaction: on any error it is rolled back and nothing is removed. Without
 * transaction support (for example MyISAM) it is not atomic, and the result says so. The order is attempts, events,
 * recipient index, messages: a failure before the index is removed can be repeated by e-mail address; a message whose
 * index is already gone is removed by the retention run, which finds messages by status and age.
 * A transaction that someone else already opened is neither committed nor rolled back: it is not used at all.
 *
 * @param int[] $ids Message ids.
 * @return array{ok:bool, deleted:int, atomic:bool, error:string}
 */
function mlab_smtp_store_delete_result( array $ids ): array {
	$ids  = array_values( array_filter( array_map( 'intval', $ids ) ) );
	$fail = array(
		'ok'      => false,
		'deleted' => 0,
		'atomic'  => false,
		'error'   => 'not ready',
	);
	if ( ! $ids ) {
		return array_merge( $fail, array( 'ok' => true ) );
	}
	$result = mlab_smtp_store_run(
		static function () use ( $ids ): array {
			global $wpdb;
			$use_tx = mlab_smtp_store_transactional() && ! mlab_smtp_store_foreign_transaction();
			if ( $use_tx && false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Transaction control.
				return array(
					'ok'      => false,
					'deleted' => 0,
					'atomic'  => false,
					'error'   => 'begin failed',
				);
			}
			$in      = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$deleted = 0;
			$error   = '';
			foreach ( array( 'attempts', 'events', 'recipients', 'messages' ) as $name ) {
				$column = 'messages' === $name ? 'id' : 'message_id';
				$count  = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE $column IN ($in)", array_merge( array( mlab_smtp_table( $name ) ), $ids ) ) );
				if ( false === $count ) {
					$error = $name;
					break;
				}
				$deleted = 'messages' === $name ? (int) $count : $deleted;
			}
			if ( $use_tx ) {
				if ( '' === $error && false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Transaction control.
					$error = 'commit';
				}
				if ( '' !== $error ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Transaction control.
				}
			}
			return array(
				'ok'      => '' === $error,
				'deleted' => '' === $error || ! $use_tx ? $deleted : 0,
				'atomic'  => $use_tx,
				'error'   => $error,
			);
		},
		$fail
	);
	return is_array( $result ) ? $result : $fail;
}

/**
 * Ids of messages that are not waiting for the worker, optionally older than a time.
 *
 * @param int $before Created before this time (0 = all).
 * @param int $limit  Maximum number of ids.
 * @return int[]
 */
function mlab_smtp_store_finished_ids( int $before, int $limit ): array {
	return (array) mlab_smtp_store_finished_ids_checked( $before, $limit );
}

/**
 * Like mlab_smtp_store_finished_ids(), but null when the lookup failed (an empty list means: nothing to delete).
 *
 * @param int $before Created before this time (0 = all).
 * @param int $limit  Maximum number of ids.
 * @return int[]|null
 */
function mlab_smtp_store_finished_ids_checked( int $before, int $limit ): ?array {
	$ids = mlab_smtp_store_run(
		static function () use ( $before, $limit ) {
			global $wpdb;
			$col = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE status NOT IN ('queued','processing','temp_failed') AND (%d = 0 OR created_at < %d) ORDER BY id ASC LIMIT %d", mlab_smtp_table( 'messages' ), $before, $before, max( 1, $limit ) ) );
			return '' === (string) $wpdb->last_error ? $col : null;
		},
		null
	);
	return is_array( $ids ) ? array_map( 'intval', $ids ) : null;
}

/**
 * Retention: deletes finished messages older than the cutoff in batches, clears expired payloads and old orphan events.
 *
 * @param int $before      Delete messages created before this time.
 * @param int $batch       Rows per batch.
 * @param int $max_batches Maximum number of batches per call.
 * @return int Number of deleted messages.
 */
function mlab_smtp_store_purge( int $before, int $batch = 500, int $max_batches = 20 ): int {
	$deleted = 0;
	for ( $i = 0; $i < $max_batches; $i++ ) {
		$ids = mlab_smtp_store_finished_ids_checked( $before, $batch );
		if ( ! $ids ) {
			break;
		}
		$result   = mlab_smtp_store_delete_result( $ids );
		$deleted += $result['deleted'];
		if ( ! $result['ok'] ) {
			break; // The same rows would fail again within this call; the next daily run tries again.
		}
		if ( count( $ids ) < $batch ) {
			break;
		}
	}
	mlab_smtp_store_clear_expired_payloads();
	return $deleted;
}

/**
 * Empties the payload of messages whose payload has expired and removes orphan events older than 7 days.
 *
 * @param int $now Time (default now).
 */
function mlab_smtp_store_clear_expired_payloads( int $now = 0 ): void {
	$now = $now > 0 ? $now : mlab_smtp_store_now();
	mlab_smtp_store_run(
		static function () use ( $now ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET payload = NULL, payload_expires_at = NULL WHERE payload_expires_at IS NOT NULL AND payload_expires_at < %d', mlab_smtp_table( 'messages' ), $now ) );
			return $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE message_id IS NULL AND received_at < %d', mlab_smtp_table( 'events' ), $now - 7 * DAY_IN_SECONDS ) );
		}
	);
}

/**
 * Records a provider event. The pair (provider, event_id) is unique: a repeated event is not stored twice.
 *
 * @param array $event provider, event_id, type, occurred_at, message_id (nullable), detail.
 * @return int|false Event id, false for a duplicate or when it cannot be stored.
 */
function mlab_smtp_store_add_event( array $event ) {
	$row = array(
		'provider'    => sanitize_key( (string) ( $event['provider'] ?? '' ) ),
		'event_id'    => mlab_smtp_store_cut( (string) ( $event['event_id'] ?? '' ), 191 ),
		'type'        => mlab_smtp_store_cut( sanitize_text_field( (string) ( $event['type'] ?? '' ) ), 64 ),
		'occurred_at' => (int) ( $event['occurred_at'] ?? mlab_smtp_store_now() ),
		'received_at' => mlab_smtp_store_now(),
		'detail'      => mlab_smtp_store_text( $event['detail'] ?? '', 500 ),
		'ref_email'   => mlab_smtp_store_cut( (string) ( $event['ref_email'] ?? '' ), 100 ),
		'ref_uuid'    => mlab_smtp_store_cut( (string) ( $event['ref_uuid'] ?? '' ), 32 ),
	);
	if ( ! empty( $event['message_id'] ) ) {
		$row['message_id'] = (int) $event['message_id'];
	}
	if ( '' === $row['event_id'] ) {
		return false;
	}
	$id = mlab_smtp_store_run(
		static function () use ( $row ) {
			global $wpdb;
			return false === $wpdb->insert( mlab_smtp_table( 'events' ), $row ) ? false : (int) $wpdb->insert_id;
		}
	);
	return $id ? (int) $id : false;
}

/**
 * Removes one event row (for a failed processing, so the provider's redelivery is not swallowed).
 *
 * @param string $provider Provider.
 * @param string $event_id Event id.
 */
function mlab_smtp_store_delete_event( string $provider, string $event_id ): bool {
	return (bool) mlab_smtp_store_run(
		static function () use ( $provider, $event_id ) {
			global $wpdb;
			return false !== $wpdb->delete(
				mlab_smtp_table( 'events' ),
				array(
					'provider' => $provider,
					'event_id' => $event_id,
				)
			);
		}
	);
}

/**
 * Maximum size of a stored snapshot in bytes (size of the encoded text).
 */
function mlab_smtp_store_max_bytes(): int {
	return max( 1, (int) apply_filters( 'mlab_smtp_queue_max_bytes', 10 * MB_IN_BYTES ) );
}

/**
 * Encrypts a message snapshot for storage in the payload column (ASCII text, safe in a LONGBLOB).
 *
 * @param array $snapshot Built message (addresses, subject, bodies, headers, attachments as Base64).
 * @return string|WP_Error Encoded payload, or an error when it is too large or encryption is not available.
 */
function mlab_smtp_store_payload_encode( array $snapshot ) {
	$json = wp_json_encode( $snapshot );
	if ( ! is_string( $json ) ) {
		return new WP_Error( 'mlab_smtp_payload', __( 'Die Nachricht konnte nicht gespeichert werden.', 'mlab-smtp' ) );
	}
	$encoded = mlab_smtp_encrypt( $json );
	if ( '' === $encoded ) {
		return mlab_smtp_crypto_error();
	}
	if ( strlen( $encoded ) > mlab_smtp_store_max_bytes() ) {
		return new WP_Error( 'mlab_smtp_payload_size', __( 'Die Nachricht ist für die Warteschlange zu gross.', 'mlab-smtp' ) );
	}
	return $encoded;
}

/**
 * Stores the snapshot of a message.
 *
 * @param int   $id         Message id.
 * @param array $snapshot   Snapshot.
 * @param int   $expires_at Time after which the payload is removed (0 = no expiry).
 * @param array $only_status When not empty, the payload is written only while the row still has one of these statuses.
 * @return bool|WP_Error
 */
function mlab_smtp_store_set_payload( int $id, array $snapshot, int $expires_at = 0, array $only_status = array() ) {
	$encoded = mlab_smtp_store_payload_encode( $snapshot );
	if ( is_wp_error( $encoded ) ) {
		return $encoded;
	}
	return (bool) mlab_smtp_store_run(
		static function () use ( $id, $encoded, $expires_at, $only_status ) {
			global $wpdb;
			$where = $only_status ? 'AND status IN (' . implode( ',', array_fill( 0, count( $only_status ), '%s' ) ) . ')' : '';
			return mlab_smtp_store_cas(
				$id,
				array(
					'payload'            => $encoded,
					'payload_expires_at' => $expires_at > 0 ? $expires_at : null,
				),
				$only_status ? $wpdb->prepare( $where, array_values( $only_status ) ) : ''
			);
		}
	);
}

/**
 * Reads and decrypts the snapshot of a message.
 *
 * @param int $id Message id.
 * @return array|WP_Error|null Null when there is no payload, an error when it is unreadable (for example after a salt change).
 */
function mlab_smtp_store_get_payload( int $id ) {
	$raw = mlab_smtp_store_run(
		static function () use ( $id ) {
			global $wpdb;
			return $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM %i WHERE id = %d', mlab_smtp_table( 'messages' ), $id ) );
		},
		null
	);
	if ( ! is_string( $raw ) || '' === $raw ) {
		return null;
	}
	$data = json_decode( mlab_smtp_decrypt( $raw ), true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'mlab_smtp_payload_unreadable', __( 'Die gespeicherte Nachricht ist nicht mehr lesbar (Schlüssel geändert).', 'mlab-smtp' ) );
	}
	return $data;
}

/**
 * Removes the payload of a message.
 *
 * @param int $id Message id.
 */
function mlab_smtp_store_clear_payload( int $id ): bool {
	return (bool) mlab_smtp_store_run(
		static function () use ( $id ) {
			return mlab_smtp_store_cas(
				$id,
				array(
					'payload'            => null,
					'payload_expires_at' => null,
				)
			);
		}
	);
}
