<?php
/**
 * Zustellmotor, Versand: Zeile vor dem Senden, ein Versuch je Weg, Ersatzweg-Regeln, Einreihen.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_mail_succeeded', 'mlab_smtp_engine_after_mail', 20 );
add_action( 'wp_mail_failed', 'mlab_smtp_engine_after_mail', 20 );

/**
 * Final state of the synchronous mail of this request (id, status, fields), so the log hooks cannot downgrade «unclear» or «partial» to «failed».
 *
 * @param array|null $set New value (an empty array resets), null to only read.
 * @return array{id:int, status:string, fields:array}
 */
function mlab_smtp_engine_final( ?array $set = null ): array {
	static $final = null;
	if ( null !== $set || null === $final ) {
		$final = array_merge(
			array(
				'id'     => 0,
				'status' => '',
				'fields' => array(),
			),
			(array) $set
		);
	}
	return $final;
}

/**
 * Runs after the log hooks: re-applies the engine's status when a hook changed it, then forgets the mail.
 */
function mlab_smtp_engine_after_mail(): void {
	$final = mlab_smtp_engine_final();
	if ( $final['id'] > 0 && function_exists( 'mlab_smtp_store_get' ) ) {
		$row = mlab_smtp_store_get( $final['id'] );
		if ( $row && $row['status'] !== $final['status'] ) {
			mlab_smtp_store_set_status( $final['id'], $final['status'], $final['fields'], 0, true );
		}
	}
	mlab_smtp_engine_final( array() );
	if ( function_exists( 'mlab_smtp_log_active_message' ) ) {
		mlab_smtp_log_active_message( 0 );
	}
}

/**
 * True when rows can be written.
 */
function mlab_smtp_engine_store_ready(): bool {
	return function_exists( 'mlab_smtp_store_create' ) && mlab_smtp_db_ready();
}

/**
 * Routes of this mail in the order they are tried. Filter mlab_smtp_route_candidates may reorder or remove them.
 *
 * @return array<int, array{route:string, provider:string}>
 */
function mlab_smtp_engine_routes(): array {
	$routes = array();
	if ( ! mlab_smtp_flag( 'fallback_only' ) ) {
		$routes[] = array(
			'route'    => 'primary',
			'provider' => (string) mlab_smtp_get( 'provider' ),
		);
	}
	if ( mlab_smtp_flag( 'fallback_only' ) || mlab_smtp_fallback_ready() ) {
		$routes[] = array(
			'route'    => 'fallback',
			'provider' => (string) mlab_smtp_get( 'fb_provider' ),
		);
	}
	/**
	 * Routes of a message in the order they are tried.
	 *
	 * @param array $routes List of array{route: primary|fallback, provider: string}.
	 */
	$filtered = apply_filters( 'mlab_smtp_route_candidates', $routes );
	$clean    = array();
	foreach ( is_array( $filtered ) ? $filtered : array() as $route ) {
		if ( is_array( $route ) && in_array( $route['route'] ?? '', array( 'primary', 'fallback' ), true ) ) {
			$clean[] = array(
				'route'    => (string) $route['route'],
				'provider' => (string) ( $route['provider'] ?? '' ),
			);
		}
	}
	return $clean ? $clean : $routes;
}

/**
 * Plain addresses of all recipients of the mailer (to, cc, bcc).
 *
 * @param Mlab_Smtp_Mailer $mailer Mailer.
 * @return string[]
 */
function mlab_smtp_engine_recipients( $mailer ): array {
	$all = array();
	foreach ( array( $mailer->getToAddresses(), $mailer->getCcAddresses(), $mailer->getBccAddresses() ) as $list ) {
		foreach ( $list as $pair ) {
			$all[] = (string) $pair[0];
		}
	}
	return $all;
}

/**
 * Starts the engine for one mail: uuid, routes, whether it is tracked.
 *
 * @param Mlab_Smtp_Mailer $mailer Prepared mailer.
 */
function mlab_smtp_engine_begin( $mailer ): array {
	return array(
		'uuid'          => '' !== $mailer->mlab_uuid ? $mailer->mlab_uuid : wp_generate_uuid4(),
		'id'            => 0,
		'track'         => mlab_smtp_engine_store_ready() && mlab_smtp_tracking(),
		'routes'        => mlab_smtp_engine_routes(),
		'message_id'    => (string) $mailer->getLastMessageID(),
		'resend'        => 0,
		'mode'          => 'sync',
		'after_unclear' => false,
	);
}

/**
 * Sends the prepared message. Called by Mlab_Smtp_Mailer::postSend().
 *
 * @param Mlab_Smtp_Mailer $mailer Prepared mailer.
 * @return bool True when the message was accepted by a route, or (mode queue, background retry) is stored for sending.
 * @throws \PHPMailer\PHPMailer\Exception When no route accepted the message.
 */
function mlab_smtp_engine_deliver( $mailer ): bool {
	$msg = mlab_smtp_engine_begin( $mailer );
	if ( $msg['track'] && 'queue' === mlab_smtp_engine_mode() && mlab_smtp_engine_enqueue_new( $mailer, $msg ) ) {
		return true;
	}
	mlab_smtp_engine_open_row( $mailer, $msg );
	$results = mlab_smtp_engine_run_routes( $mailer, $msg );
	return mlab_smtp_engine_finish( $mailer, $msg, $results );
}

/**
 * Writes the row of a synchronous mail before sending (status processing, lease 300 s), so a fatal error leaves a trace.
 *
 * @param Mlab_Smtp_Mailer $mailer Mailer.
 * @param array            $msg    Message context (gets the row id).
 */
function mlab_smtp_engine_open_row( $mailer, array &$msg ): void {
	if ( ! $msg['track'] || ! mlab_smtp_get( 'log_enabled' ) ) {
		return;
	}
	$id = mlab_smtp_store_create(
		array(
			'uuid'         => $msg['uuid'],
			'mode'         => 'sync',
			'status'       => 'processing',
			'lease'        => MLAB_SMTP_ENGINE_LEASE,
			'provider'     => (string) mlab_smtp_get( 'provider' ),
			'recipients'   => mlab_smtp_engine_recipients( $mailer ),
			'subject'      => (string) $mailer->Subject, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
			'max_attempts' => max( 1, (int) mlab_smtp_get( 'max_attempts' ) ),
		)
	);
	if ( $id ) {
		$msg['id'] = (int) $id;
		mlab_smtp_log_active_message( (int) $id );
	}
}

/**
 * Tries the routes one after the other until one accepts, according to the unclear policy of the mode.
 *
 * @param Mlab_Smtp_Mailer $mailer Prepared mailer (sync) or the one rebuilt from the snapshot (worker).
 * @param array            $msg    Message context.
 * @param callable|null    $mailer_for Returns the mailer for a route (worker: a fresh one per route), null to reuse $mailer.
 * @return array[] Results per route.
 */
function mlab_smtp_engine_run_routes( $mailer, array &$msg, ?callable $mailer_for = null ): array {
	$results = array();
	foreach ( $msg['routes'] as $route ) {
		$m         = $mailer_for ? $mailer_for( $route ) : $mailer;
		$result    = mlab_smtp_engine_try_route( $m, $msg, $route );
		$results[] = $result;
		if ( 'accepted' === $result['class'] || ! mlab_smtp_engine_may_continue( $result, (string) $msg['mode'] ) ) {
			break;
		}
		$msg['after_unclear'] = 'unclear' === $result['class'];
	}
	return $results;
}

/**
 * True when the next route may be tried after this failure.
 *
 * @param array  $result Result of the route.
 * @param string $mode   sync or queued.
 */
function mlab_smtp_engine_may_continue( array $result, string $mode ): bool {
	if ( 'partial' === $result['class'] || ! empty( $result['info']['no_fallback'] ) ) {
		return false; // The message may already have reached some or all recipients.
	}
	if ( 'unclear' === $result['class'] ) {
		return (bool) mlab_smtp_get( 'queued' === $mode ? 'queue_unclear_failover' : 'unclear_failover' );
	}
	return true;
}

/**
 * Outcome name of an attempt row for a class.
 *
 * @param string $kind accepted, temporary, permanent, unclear or partial.
 */
function mlab_smtp_engine_attempt_outcome( string $kind ): string {
	return 'unclear' === $kind ? 'unknown' : $kind;
}

/**
 * One attempt on one route: attempt row before the network access, stable idempotency key and Message-ID, classification.
 *
 * @param Mlab_Smtp_Mailer $mailer Mailer of the route.
 * @param array            $msg    Message context.
 * @param array            $route  route and provider.
 * @return array{route:string, provider:string, class:string, error:string, info:array, provider_msg_id:string, retry_after:int}
 */
function mlab_smtp_engine_try_route( $mailer, array $msg, array $route ): array {
	$attempt = 0;
	$key     = mlab_smtp_engine_idem_key( $msg['uuid'], $route['route'], (int) $msg['resend'] );
	if ( $msg['id'] > 0 ) {
		$attempt = (int) mlab_smtp_store_add_attempt(
			$msg['id'],
			array(
				'route'           => $route['route'],
				'provider'        => $route['provider'],
				'idem_key'        => $key,
				'conn_hash'       => mlab_smtp_route_connection_hash( $route['route'], $route['provider'] ),
				'mail_message_id' => $msg['message_id'],
			)
		);
	}
	mlab_smtp_engine_context(
		array(
			'uuid'       => $msg['uuid'],
			'idem_key'   => $key,
			'attempt_id' => $attempt,
		)
	);
	$error = '';
	$info  = array();
	try {
		$mailer->send_route( $route['route'] );
	} catch ( \PHPMailer\PHPMailer\Exception $e ) {
		$error = '' !== $e->getMessage() ? $e->getMessage() : __( 'Unbekannter Fehler.', 'mlab-smtp' );
		$info  = $e instanceof Mlab_Smtp_Route_Error ? $e->info : array();
	}
	$context = mlab_smtp_engine_context();
	mlab_smtp_engine_context( array() );
	$class  = '' === $error ? 'accepted' : mlab_smtp_engine_classify(
		array_merge(
			$info,
			array(
				'request_sent' => $context['request_sent'],
				'provider'     => $route['provider'],
				'message'      => $error,
			)
		)
	);
	$result = array(
		'route'           => $route['route'],
		'provider'        => $route['provider'],
		'class'           => $class,
		'error'           => $error,
		'info'            => $info,
		'provider_msg_id' => $context['provider_msg_id'],
		'retry_after'     => (int) ( $info['retry_after'] ?? 0 ),
	);
	if ( $attempt > 0 ) {
		mlab_smtp_store_finish_attempt(
			$attempt,
			array(
				'outcome'         => mlab_smtp_engine_attempt_outcome( $class ),
				'http_status'     => (int) ( $info['http_status'] ?? 0 ),
				'error_code'      => $msg['after_unclear'] && 'fallback' === $route['route'] ? 'fallback_after_unclear' : (string) ( $info['error_code'] ?? '' ),
				'error_text'      => $error,
				'provider_msg_id' => $context['provider_msg_id'],
				'retry_after'     => $result['retry_after'],
			)
		);
	}
	return $result;
}

/**
 * Overall class of failed results: unclear before partial before temporary before permanent.
 *
 * @param array[] $results Results of all routes tried.
 */
function mlab_smtp_engine_overall_class( array $results ): string {
	$classes = array_column( $results, 'class' );
	foreach ( array( 'unclear', 'partial', 'temporary' ) as $class ) {
		if ( in_array( $class, $classes, true ) ) {
			return $class;
		}
	}
	return 'permanent';
}

/**
 * True when a retry later is safe: a temporary failure, or an unclear one only on providers with a verifiable idempotency key.
 *
 * @param array[] $results Results of all routes tried.
 */
function mlab_smtp_engine_retryable( array $results ): bool {
	$class = mlab_smtp_engine_overall_class( $results );
	if ( 'temporary' === $class ) {
		return true;
	}
	if ( 'unclear' !== $class ) {
		return false;
	}
	foreach ( $results as $result ) {
		if ( 'unclear' === $result['class'] && ! in_array( $result['provider'], mlab_smtp_engine_idempotent_providers(), true ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Condenses the results of the routes tried into one outcome.
 *
 * @param array[] $results Results per route (at least one).
 * @return array{ok:bool, class:string, message:string, primary_error:string, via:string, fallback:bool, provider_msg_id:string, retry_after:int, retryable:bool, status:string, error:string}
 */
function mlab_smtp_engine_outcome( array $results ): array {
	$last    = (array) end( $results );
	$first   = (array) reset( $results );
	$ok      = 'accepted' === $last['class'];
	$pair    = count( $results ) > 1;
	$class   = $ok ? 'accepted' : mlab_smtp_engine_overall_class( $results );
	$primary = 'primary' === $first['route'] ? $first['error'] : '';
	if ( $ok && $pair && 'unclear' === $first['class'] ) {
		/* translators: %s: error of the main route */
		$primary = sprintf( __( 'Hauptweg unklar (fallback_after_unclear): %s', 'mlab-smtp' ), $first['error'] );
	}
	if ( $ok ) {
		$message = '';
	} elseif ( $pair ) {
		/* translators: 1: error of the main route, 2: error of the fallback route */
		$message = sprintf( __( 'Hauptweg: %1$s | Ersatzweg: %2$s', 'mlab-smtp' ), $first['error'], $last['error'] );
	} elseif ( 'fallback' === $last['route'] ) {
		/* translators: %s: error of the fallback route */
		$message = sprintf( __( 'Ersatzweg: %s', 'mlab-smtp' ), $last['error'] );
	} else {
		$message = $last['error'];
	}
	$status = array(
		'accepted' => 'accepted',
		'unclear'  => 'unclear',
		'partial'  => 'partial',
	);
	return array(
		'ok'              => $ok,
		'class'           => $class,
		'status'          => $status[ $class ] ?? 'failed',
		'message'         => $message,
		'error'           => $message,
		'primary_error'   => $primary,
		'via'             => $ok ? $last['provider'] : (string) mlab_smtp_get( 'provider' ),
		'fallback'        => $ok && 'fallback' === $last['route'] && 'primary' === $first['route'],
		'provider_msg_id' => $ok ? $last['provider_msg_id'] : '',
		'retry_after'     => (int) max( array_merge( array( 0 ), array_column( $results, 'retry_after' ) ) ),
		'retryable'       => ! $ok && mlab_smtp_engine_retryable( $results ),
	);
}

/**
 * Pauses a provider after a Retry-After (or 429 without one) for the worker.
 *
 * @param array[] $results Results per route.
 */
function mlab_smtp_engine_pause_from( array $results ): void {
	foreach ( $results as $result ) {
		$status = (int) ( $result['info']['http_status'] ?? 0 );
		if ( 'temporary' === $result['class'] && ( $result['retry_after'] > 0 || 429 === $status ) ) {
			$wait = $result['retry_after'] > 0 ? min( MLAB_SMTP_ENGINE_RETRY_CAP, $result['retry_after'] ) : MINUTE_IN_SECONDS;
			mlab_smtp_engine_pauses( mlab_smtp_engine_pause_key( (string) $result['route'], (string) $result['provider'] ), time() + $wait );
		}
	}
}

/**
 * Applies the outcome of a synchronous mail: row, route result, actions; returns true or throws.
 *
 * @param Mlab_Smtp_Mailer $mailer  Mailer.
 * @param array            $msg     Message context.
 * @param array[]          $results Results per route.
 * @return bool
 * @throws \PHPMailer\PHPMailer\Exception When no route accepted the message.
 */
function mlab_smtp_engine_finish( $mailer, array $msg, array $results ): bool {
	$o = mlab_smtp_engine_outcome( $results );
	mlab_smtp_engine_pause_from( $results );
	mlab_smtp_route_result(
		array(
			'via'           => $o['via'],
			'fallback'      => $o['fallback'],
			'primary_error' => $o['primary_error'],
		)
	);
	if ( ! $o['ok'] && mlab_smtp_engine_retry_in_background( $mailer, $msg, $o ) ) {
		return true;
	}
	mlab_smtp_engine_write_final( $msg, $o );
	if ( $o['ok'] ) {
		do_action( 'mlab_smtp_message_accepted', mlab_smtp_engine_event( $msg, $o ) );
		return true;
	}
	do_action( 'mlab_smtp_message_failed', mlab_smtp_engine_event( $msg, $o ) );
	throw new \PHPMailer\PHPMailer\Exception( $o['message'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text for logs; escaped on output.
}

/**
 * Data of the actions mlab_smtp_message_accepted and mlab_smtp_message_failed.
 *
 * @param array $msg Message context.
 * @param array $o   Outcome.
 */
function mlab_smtp_engine_event( array $msg, array $o ): array {
	return array(
		'id'              => (int) $msg['id'],
		'uuid'            => (string) $msg['uuid'],
		'status'          => (string) $o['status'],
		'class'           => (string) $o['class'],
		'mode'            => (string) $msg['mode'],
		'via'             => (string) $o['via'],
		'fallback'        => (bool) $o['fallback'],
		'provider_msg_id' => (string) $o['provider_msg_id'],
		'error'           => (string) $o['message'],
	);
}

/**
 * Fields of the row for an outcome.
 *
 * @param array $o Outcome.
 */
function mlab_smtp_engine_row_fields( array $o ): array {
	return array(
		'via'             => $o['via'],
		'fallback'        => $o['fallback'] ? 1 : 0,
		'provider_msg_id' => $o['provider_msg_id'],
		'error_class'     => $o['ok'] ? '' : $o['class'],
		'last_error'      => $o['ok'] ? $o['primary_error'] : $o['message'],
	);
}

/**
 * Sets the status of a row and tells the extension point.
 *
 * @param int    $id     Message id.
 * @param string $status New status.
 * @param array  $fields Fields for mlab_smtp_store_set_status().
 * @param string $source Where the change came from (sync, worker, manual).
 */
function mlab_smtp_engine_set_status( int $id, string $status, array $fields, string $source ): bool {
	$old = mlab_smtp_store_get( $id );
	$ok  = mlab_smtp_store_set_status( $id, $status, $fields, 0, 'manual' !== $source );
	if ( $ok && (string) ( $old['status'] ?? '' ) !== $status ) {
		mlab_smtp_engine_changed( $id, $status, (string) ( $old['status'] ?? '' ), $source );
	}
	if ( $ok && ! empty( $fields['provider_msg_id'] ) && function_exists( 'mlab_smtp_webhook_rematch' ) ) {
		mlab_smtp_webhook_rematch();
	}
	return $ok;
}

/**
 * Action mlab_smtp_message_status_changed.
 *
 * @param int    $id     Message id.
 * @param string $status New status.
 * @param string $old    Old status.
 * @param string $source sync, worker or manual.
 */
function mlab_smtp_engine_changed( int $id, string $status, string $old, string $source ): void {
	/**
	 * A message changed its status through the engine (sending, worker, manual action).
	 *
	 * @param int    $id     Message id.
	 * @param string $status New status.
	 * @param string $old    Old status.
	 * @param string $source sync, worker or manual.
	 */
	do_action( 'mlab_smtp_message_status_changed', $id, $status, $old, $source );
}

/**
 * Writes the final status of a synchronous mail (and remembers it against the log hooks).
 *
 * @param array $msg Message context.
 * @param array $o   Outcome.
 */
function mlab_smtp_engine_write_final( array $msg, array $o ): void {
	if ( $msg['id'] <= 0 ) {
		return;
	}
	$fields = mlab_smtp_engine_row_fields( $o );
	mlab_smtp_engine_final(
		array(
			'id'     => $msg['id'],
			'status' => $o['status'],
			'fields' => $fields,
		)
	);
	mlab_smtp_engine_set_status( $msg['id'], $o['status'], $fields, 'sync' );
}

/**
 * Stores a new message for the worker (mode queue). False means: send synchronously (too large, no key, no tables).
 *
 * @param Mlab_Smtp_Mailer $mailer Prepared mailer.
 * @param array            $msg    Message context.
 */
function mlab_smtp_engine_enqueue_new( $mailer, array $msg ): bool {
	$snapshot = mlab_smtp_engine_snapshot( $mailer );
	if ( is_wp_error( $snapshot ) ) {
		return false;
	}
	$id = mlab_smtp_engine_store_queued( $mailer, $msg, $snapshot, 'queued', time() );
	if ( ! $id ) {
		return false;
	}
	mlab_smtp_engine_after_enqueue( $id, '' );
	return true;
}

/**
 * Creates the row and the encrypted payload of a message for the worker.
 *
 * @param Mlab_Smtp_Mailer $mailer   Mailer (recipients, subject).
 * @param array            $msg      Message context.
 * @param array            $snapshot Snapshot.
 * @param string           $status   queued or temp_failed.
 * @param int              $next     Time of the first try.
 * @return int Row id, 0 when it could not be stored.
 */
function mlab_smtp_engine_store_queued( $mailer, array $msg, array $snapshot, string $status, int $next ): int {
	$encoded = mlab_smtp_store_payload_encode( $snapshot );
	if ( is_wp_error( $encoded ) ) {
		return 0;
	}
	$id = (int) mlab_smtp_store_create(
		array(
			'payload'         => $encoded, // In the same INSERT: no worker can claim the row before the payload exists.
			'uuid'            => $msg['uuid'],
			'mode'            => 'queued',
			'status'          => $status,
			'provider'        => (string) mlab_smtp_get( 'provider' ),
			'recipients'      => mlab_smtp_engine_recipients( $mailer ),
			'subject'         => (string) $mailer->Subject, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
			'max_attempts'    => max( 1, (int) mlab_smtp_get( 'max_attempts' ) ),
			'next_attempt_at' => $next,
		)
	);
	return $id;
}

/**
 * After a message was stored for the worker: silence the stats hooks of this mail, wake the worker, tell the extension point.
 *
 * @param int    $id  Message id.
 * @param string $old Status before.
 */
function mlab_smtp_engine_after_enqueue( int $id, string $old ): void {
	mlab_smtp_flag( 'quiet', true ); // Core fires wp_mail_succeeded for the enqueue; log and counters must not treat it as a delivery.
	mlab_smtp_log_active_message( 0 );
	mlab_smtp_engine_final( array() );
	mlab_smtp_engine_changed( $id, (string) ( mlab_smtp_store_get( $id )['status'] ?? 'queued' ), $old, 'enqueue' );
	mlab_smtp_engine_spawn();
}

/**
 * Number of rounds (tries over all routes) a message had so far.
 *
 * @param array[] $attempts Attempt rows.
 */
function mlab_smtp_engine_rounds( array $attempts ): int {
	$count = array(
		'primary'  => 0,
		'fallback' => 0,
	);
	foreach ( $attempts as $attempt ) {
		if ( isset( $count[ $attempt['route'] ] ) ) {
			++$count[ $attempt['route'] ];
		}
	}
	return max( $count );
}

/**
 * Setting «Im Hintergrund wiederholen»: after a temporary failure of all routes the message goes to the queue and wp_mail() reports «accepted».
 *
 * @param Mlab_Smtp_Mailer $mailer Mailer.
 * @param array            $msg    Message context.
 * @param array            $o      Outcome of the failed send.
 * @return bool True when the message was queued.
 */
function mlab_smtp_engine_retry_in_background( $mailer, array $msg, array $o ): bool {
	if ( ! $msg['track'] || ! mlab_smtp_get( 'retry_in_background' ) || ! $o['retryable'] || max( 1, (int) mlab_smtp_get( 'max_attempts' ) ) < 2 ) {
		return false;
	}
	$snapshot = mlab_smtp_engine_snapshot( $mailer );
	if ( is_wp_error( $snapshot ) ) {
		return false;
	}
	$next   = time() + mlab_smtp_engine_delay( 1, $o['retry_after'] );
	$fields = array(
		'mode'            => 'queued',
		'status'          => 'temp_failed',
		'next_attempt_at' => $next,
		'lock_token'      => null,
		'locked_until'    => null,
		'completed_at'    => null,
		'error_class'     => $o['class'],
		'last_error'      => mlab_smtp_store_text( $o['message'], 2000 ),
	);
	if ( $msg['id'] > 0 && true === mlab_smtp_store_set_payload( $msg['id'], $snapshot, 0, array( 'processing' ) ) ) {
		global $wpdb;
		// Only while the row is still ours (processing): a swept or changed row stays as it is.
		$won = (bool) mlab_smtp_store_run(
			static function () use ( $msg, $fields, $wpdb ) {
				return mlab_smtp_store_cas( $msg['id'], $fields, $wpdb->prepare( 'AND status = %s', 'processing' ) );
			}
		);
		$id  = $won ? $msg['id'] : 0;
	} else {
		$id = $msg['id'] > 0 ? 0 : mlab_smtp_engine_store_queued( $mailer, $msg, $snapshot, 'temp_failed', $next );
	}
	if ( ! $id ) {
		return false;
	}
	mlab_smtp_engine_after_enqueue( $id, 'processing' );
	return true;
}
