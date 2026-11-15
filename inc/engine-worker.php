<?php
/**
 * Zustellmotor, Worker: WP-Cron-Ereignis, Übernahme mit Lease, Wiederholung, manuelle Aktionen, Zusammenfassung.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_QUEUE_HOOK = 'mlab_smtp_process_queue';

add_filter( 'cron_schedules', 'mlab_smtp_engine_cron_schedules' ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- The queue worker has to run every minute.
add_action( MLAB_SMTP_QUEUE_HOOK, 'mlab_smtp_engine_cron' );
add_action( 'init', 'mlab_smtp_engine_schedule', 20 );

/**
 * Adds the one-minute interval.
 *
 * @param array $schedules Schedules of WordPress.
 * @return array
 */
function mlab_smtp_engine_cron_schedules( $schedules ): array {
	$schedules = (array) $schedules;
	// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- The queue worker has to run every minute, see the architecture note.
	$schedules['mlab_smtp_minute'] = array(
		'interval' => MINUTE_IN_SECONDS,
		'display'  => __( 'Jede Minute (MomentumLab SMTP)', 'mlab-smtp' ),
	);
	return $schedules;
}

/**
 * Plans the worker when it is missing (activation and self-healing on every request).
 */
function mlab_smtp_engine_schedule(): void {
	if ( ! wp_next_scheduled( MLAB_SMTP_QUEUE_HOOK ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'mlab_smtp_minute', MLAB_SMTP_QUEUE_HOOK );
	}
}

/**
 * Plugin deactivation: removes the daily job and the worker (including one-off events).
 */
function mlab_smtp_engine_deactivate(): void {
	mlab_smtp_deactivate();
	wp_unschedule_hook( MLAB_SMTP_QUEUE_HOOK );
}

/**
 * Wakes the worker right after a message was queued: a one-off event now, then spawn_cron().
 */
function mlab_smtp_engine_spawn(): void {
	if ( ! apply_filters( 'mlab_smtp_engine_spawn', true ) ) {
		return;
	}
	wp_schedule_single_event( time(), MLAB_SMTP_QUEUE_HOOK, array( 'now' ) );
	spawn_cron();
}

/**
 * Cron callback.
 */
function mlab_smtp_engine_cron(): void {
	mlab_smtp_process_queue();
}

/**
 * One worker run: sweeps stale leases, then claims and sends due messages one by one (the lease starts right before sending).
 *
 * @param int $max    Most messages per run.
 * @param int $budget Seconds after which no further message is started.
 * @return array<string,int> Counts per result.
 */
function mlab_smtp_process_queue( int $max = 20, int $budget = 45 ): array {
	$done = array();
	if ( ! mlab_smtp_engine_store_ready() ) {
		return $done;
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( MLAB_SMTP_ENGINE_LEASE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- A worker run may take longer than the web limit.
	}
	update_option( 'mlab_smtp_engine_last_run', time(), false );
	mlab_smtp_store_sweep();
	$deadline = time() + $budget;
	for ( $i = 0; $i < $max; $i++ ) {
		if ( time() >= $deadline ) {
			break;
		}
		$claimed = mlab_smtp_store_claim_batch( 1, MLAB_SMTP_ENGINE_LEASE );
		if ( ! $claimed ) {
			break;
		}
		try {
			$result = mlab_smtp_engine_process( $claimed[0] );
		} catch ( Throwable $e ) {
			$result = 'error'; // The lease runs out and the sweep decides (queued or unclear).
		}
		$done[ $result ] = ( $done[ $result ] ?? 0 ) + 1;
	}
	return $done;
}

/**
 * Routes of a queued message without the providers that are paused by a Retry-After.
 *
 * @return array{0:array[], 1:int} Routes and the time the earliest pause ends (0 when none is paused).
 */
function mlab_smtp_engine_active_routes(): array {
	$pauses = mlab_smtp_engine_pauses();
	$routes = array();
	$until  = 0;
	foreach ( mlab_smtp_engine_routes() as $route ) {
		if ( isset( $pauses[ $route['provider'] ] ) ) {
			$until = 0 === $until ? $pauses[ $route['provider'] ] : min( $until, $pauses[ $route['provider'] ] );
			continue;
		}
		$routes[] = $route;
	}
	return array( $routes, $until );
}

/**
 * Sends one claimed message.
 *
 * @param array $row Claimed row (with lock_token).
 * @return string accepted, partial, failed, unclear, released, lost.
 */
function mlab_smtp_engine_process( array $row ): string {
	$id       = (int) $row['id'];
	$token    = (string) $row['lock_token'];
	$snapshot = mlab_smtp_store_get_payload( $id );
	if ( ! is_array( $snapshot ) ) {
		$text = is_wp_error( $snapshot ) ? $snapshot->get_error_message() : __( 'Die gespeicherte Nachricht ist nicht mehr vorhanden.', 'mlab-smtp' );
		return mlab_smtp_engine_settle_final( $row, $token, 'failed', $text, 'permanent' );
	}
	/**
	 * Lets an extension hold a queued message back. Return false or a WP_Error to postpone it by five minutes.
	 *
	 * @param bool  $proceed True to send.
	 * @param array $row     Message row.
	 */
	$proceed = apply_filters( 'mlab_smtp_queue_before_send', true, $row );
	if ( true !== $proceed ) {
		$text = is_wp_error( $proceed ) ? $proceed->get_error_message() : __( 'Der Versand wurde von einer Erweiterung zurückgestellt.', 'mlab-smtp' );
		return mlab_smtp_engine_hold( $id, $token, time() + 5 * MINUTE_IN_SECONDS, $text );
	}
	list( $routes, $until ) = mlab_smtp_engine_active_routes();
	if ( ! $routes ) {
		return mlab_smtp_engine_hold( $id, $token, max( time() + 1, $until ), __( 'Der Anbieter hat um eine Pause gebeten (Retry-After).', 'mlab-smtp' ) );
	}
	$msg = array(
		'uuid'          => (string) $row['uuid'],
		'id'            => $id,
		'track'         => true,
		'routes'        => $routes,
		'message_id'    => (string) ( $snapshot['message_id'] ?? '' ),
		'resend'        => (int) ( $snapshot['resend'] ?? 0 ),
		'mode'          => 'queued',
		'after_unclear' => false,
	);
	try {
		$results = mlab_smtp_engine_run_routes(
			null,
			$msg,
			static function () use ( $snapshot ) {
				return mlab_smtp_engine_mailer_from_snapshot( $snapshot );
			}
		);
	} catch ( \PHPMailer\PHPMailer\Exception $e ) {
		return mlab_smtp_engine_settle_final( $row, $token, 'failed', $e->getMessage(), 'permanent' );
	}
	mlab_smtp_engine_pause_from( $results );
	return mlab_smtp_engine_settle( $row, $token, $snapshot, mlab_smtp_engine_outcome( $results ) );
}

/**
 * Gives a message back without an attempt.
 *
 * @param int    $id    Message id.
 * @param string $token Lock token.
 * @param int    $next  Time of the next try.
 * @param string $text  Reason, shown as the last error.
 */
function mlab_smtp_engine_hold( int $id, string $token, int $next, string $text ): string {
	mlab_smtp_store_release( $id, $token, $next, array( 'last_error' => $text ), 'queued' );
	return 'released';
}

/**
 * Ends a message with a final status.
 *
 * @param array  $row    Claimed row.
 * @param string $token  Lock token.
 * @param string $status failed or unclear.
 * @param string $text   Error text.
 * @param string $kind   Error class.
 */
function mlab_smtp_engine_settle_final( array $row, string $token, string $status, string $text, string $kind ): string {
	$fields = array(
		'last_error'  => $text,
		'error_class' => $kind,
	);
	if ( ! mlab_smtp_store_complete( (int) $row['id'], $token, $status, $fields ) ) {
		return 'lost';
	}
	mlab_smtp_engine_changed( (int) $row['id'], $status, 'processing', 'worker' );
	$event = array(
		'id'              => (int) $row['id'],
		'uuid'            => (string) $row['uuid'],
		'status'          => $status,
		'class'           => $kind,
		'mode'            => 'queued',
		'via'             => '',
		'fallback'        => false,
		'provider_msg_id' => '',
		'error'           => $text,
	);
	do_action( 'mlab_smtp_message_failed', $event );
	if ( 'failed' === $status && function_exists( 'mlab_smtp_record' ) ) {
		mlab_smtp_record( false, $text, false );
	}
	return $status;
}

/**
 * Applies the outcome of a worker round: complete, release for a later try, or end.
 *
 * @param array  $row      Claimed row.
 * @param string $token   Lock token.
 * @param array  $snapshot Snapshot (for the event).
 * @param array  $o        Outcome of mlab_smtp_engine_outcome().
 * @return string Result name.
 */
function mlab_smtp_engine_settle( array $row, string $token, array $snapshot, array $o ): string {
	$id = (int) $row['id'];
	if ( $o['ok'] || 'partial' === $o['class'] ) {
		return mlab_smtp_engine_settle_done( $row, $token, $snapshot, $o );
	}
	if ( ! $o['retryable'] ) {
		return mlab_smtp_engine_settle_final( $row, $token, $o['status'], $o['message'], $o['class'] );
	}
	$attempts = mlab_smtp_store_attempts( $id );
	$rounds   = mlab_smtp_engine_rounds( $attempts );
	if ( $rounds >= (int) $row['max_attempts'] ) {
		$status = 'unclear' === $o['class'] ? 'unclear' : 'failed';
		return mlab_smtp_engine_settle_final( $row, $token, $status, $o['message'] . ' ' . __( 'Die Höchstzahl der Versuche ist erreicht.', 'mlab-smtp' ), $o['class'] );
	}
	$next = time() + mlab_smtp_engine_delay( $rounds, $o['retry_after'] );
	if ( mlab_smtp_engine_unclear_expired( $attempts, $next ) ) {
		return mlab_smtp_engine_settle_final( $row, $token, 'unclear', $o['message'], 'unclear' );
	}
	$fields = array(
		'last_error'  => $o['message'],
		'error_class' => $o['class'],
	);
	if ( ! mlab_smtp_store_release( $id, $token, $next, $fields, 'temp_failed' ) ) {
		return 'lost';
	}
	mlab_smtp_engine_changed( $id, 'temp_failed', 'processing', 'worker' );
	return 'released';
}

/**
 * True when a message with an unclear attempt would be repeated later than 23 hours after its first attempt.
 *
 * @param array[] $attempts Attempt rows.
 * @param int     $next     Planned time of the next try.
 */
function mlab_smtp_engine_unclear_expired( array $attempts, int $next ): bool {
	if ( ! in_array( 'unknown', array_column( $attempts, 'outcome' ), true ) ) {
		return false;
	}
	$first = (int) min( array_merge( array( PHP_INT_MAX ), array_map( 'intval', array_column( $attempts, 'started_at' ) ) ) );
	return $next > $first + MLAB_SMTP_ENGINE_UNCLEAR_MAX;
}

/**
 * Completes an accepted (or partly accepted) message and reports it.
 *
 * @param array  $row      Claimed row.
 * @param string $token    Lock token.
 * @param array  $snapshot Snapshot.
 * @param array  $o        Outcome.
 */
function mlab_smtp_engine_settle_done( array $row, string $token, array $snapshot, array $o ): string {
	$id     = (int) $row['id'];
	$status = $o['ok'] ? 'accepted' : 'partial';
	$fields = mlab_smtp_engine_row_fields( $o );
	if ( ! $o['ok'] ) {
		$fields['last_error'] = $o['message'];
	}
	if ( ! mlab_smtp_store_complete( $id, $token, $status, $fields ) ) {
		return 'lost';
	}
	mlab_smtp_engine_changed( $id, $status, 'processing', 'worker' );
	if ( '' !== $o['provider_msg_id'] && function_exists( 'mlab_smtp_webhook_rematch' ) ) {
		mlab_smtp_webhook_rematch();
	}
	$msg = array(
		'id'   => $id,
		'uuid' => (string) $row['uuid'],
		'mode' => 'queued',
	);
	if ( $o['ok'] && function_exists( 'mlab_smtp_record' ) ) {
		mlab_smtp_record( true, $o['primary_error'], $o['fallback'] );
	}
	mlab_smtp_engine_fire_core_success( $snapshot, (string) $row['uuid'] );
	do_action( $o['ok'] ? 'mlab_smtp_message_accepted' : 'mlab_smtp_message_failed', mlab_smtp_engine_event( $msg, $o ) );
	return $status;
}

/**
 * Fires wp_mail_succeeded for a message the worker delivered, marked with a context. Our own log and counters ignore it (quiet flag).
 *
 * @param array  $snapshot Snapshot.
 * @param string $uuid     Message uuid.
 */
function mlab_smtp_engine_fire_core_success( array $snapshot, string $uuid ): void {
	$to = array_map( static fn( $pair ): string => (string) $pair[0], (array) ( $snapshot['to'] ?? array() ) );
	mlab_smtp_flag( 'quiet', true );
	do_action(
		'wp_mail_succeeded',
		array(
			'to'           => $to,
			'subject'      => (string) ( $snapshot['subject'] ?? '' ),
			'message'      => (string) ( $snapshot['body'] ?? '' ),
			'headers'      => array(),
			'attachments'  => array(),
			'embeds'       => array(),
			'mlab_context' => 'worker',
			'mlab_uuid'    => $uuid,
		)
	);
	mlab_smtp_flag( 'quiet', false );
}

/**
 * Compare-and-set on a message that must still have the given status.
 *
 * @param int    $id   Message id.
 * @param string $from Required status.
 * @param array  $set  Fields to write.
 */
function mlab_smtp_engine_cas_from( int $id, string $from, array $set ): bool {
	return (bool) mlab_smtp_store_run(
		static function () use ( $id, $from, $set ) {
			global $wpdb;
			return mlab_smtp_store_cas( $id, $set, $wpdb->prepare( 'AND status = %s', $from ) );
		}
	);
}

/**
 * Manual action: tries a failed message again (only while its payload exists).
 *
 * @param int    $id     Message id.
 * @param string $from   Required status (failed, or unclear for «send again»).
 * @param int    $resend Value of the resend counter to store in the snapshot (0 = unchanged).
 * @return true|WP_Error
 */
function mlab_smtp_engine_requeue( int $id, string $from, int $resend = 0 ) {
	$row = mlab_smtp_store_get( $id );
	if ( ! $row || $from !== $row['status'] ) {
		return new WP_Error( 'mlab_smtp_state', __( 'Die Nachricht hat einen anderen Status, die Aktion ist nicht mehr möglich.', 'mlab-smtp' ) );
	}
	$snapshot = empty( $row['has_payload'] ) ? null : mlab_smtp_store_get_payload( $id );
	if ( ! is_array( $snapshot ) ) {
		return new WP_Error( 'mlab_smtp_payload', __( 'Der Inhalt der Nachricht wird nicht mehr aufbewahrt, sie kann nicht erneut gesendet werden.', 'mlab-smtp' ) );
	}
	if ( $resend > 0 ) {
		$snapshot['resend'] = (int) ( $snapshot['resend'] ?? 0 ) + 1;
		mlab_smtp_store_set_payload( $id, $snapshot );
	}
	$set = array(
		'status'             => 'queued',
		'mode'               => 'queued',
		'next_attempt_at'    => time(),
		'max_attempts'       => mlab_smtp_engine_rounds( mlab_smtp_store_attempts( $id ) ) + max( 1, (int) mlab_smtp_get( 'max_attempts' ) ),
		'completed_at'       => null,
		'payload_expires_at' => null,
		'lock_token'         => null,
		'locked_until'       => null,
		'error_class'        => '',
	);
	if ( ! mlab_smtp_engine_cas_from( $id, $from, $set ) ) {
		return new WP_Error( 'mlab_smtp_state', __( 'Die Nachricht wurde in der Zwischenzeit geändert.', 'mlab-smtp' ) );
	}
	mlab_smtp_engine_changed( $id, 'queued', $from, 'manual' );
	mlab_smtp_engine_spawn();
	return true;
}

/**
 * Manual action: tries a failed message again.
 *
 * @param int $id Message id.
 * @return true|WP_Error
 */
function mlab_smtp_engine_manual_retry( int $id ) {
	return mlab_smtp_engine_requeue( $id, 'failed' );
}

/**
 * Manual action «Erneut senden (Doppelversand möglich)» for an unclear message. Gets a new idempotency key.
 *
 * @param int $id Message id.
 * @return true|WP_Error
 */
function mlab_smtp_engine_manual_resend( int $id ) {
	return mlab_smtp_engine_requeue( $id, 'unclear', 1 );
}

/**
 * Manual action «Als zugestellt markieren» for an unclear message.
 *
 * @param int $id Message id.
 * @return true|WP_Error
 */
function mlab_smtp_engine_mark_delivered( int $id ) {
	$fields = array( 'last_error' => __( 'Manuell als zugestellt markiert.', 'mlab-smtp' ) );
	$set    = mlab_smtp_store_status_fields( 'accepted', $fields, time() );
	if ( ! mlab_smtp_engine_cas_from( $id, 'unclear', $set ) ) {
		return new WP_Error( 'mlab_smtp_state', __( 'Die Nachricht hat einen anderen Status, die Aktion ist nicht mehr möglich.', 'mlab-smtp' ) );
	}
	mlab_smtp_engine_changed( $id, 'accepted', 'unclear', 'manual' );
	return true;
}

/**
 * URL of a manual action (with nonce), for buttons in the log.
 *
 * @param int    $id Message id.
 * @param string $action retry, resend or delivered.
 */
function mlab_smtp_engine_action_url( int $id, string $action ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'mlab_smtp_message_action',
				'do'     => $action,
				'id'     => $id,
			),
			admin_url( 'admin-post.php' )
		),
		'mlab_smtp_message_action'
	);
}

/**
 * Runs a manual action by name.
 *
 * @param string $action retry, resend or delivered.
 * @param int    $id Message id.
 * @return true|WP_Error
 */
function mlab_smtp_engine_run_action( string $action, int $id ) {
	$map = array(
		'retry'     => 'mlab_smtp_engine_manual_retry',
		'resend'    => 'mlab_smtp_engine_manual_resend',
		'delivered' => 'mlab_smtp_engine_mark_delivered',
	);
	return isset( $map[ $action ] ) && $id > 0 ? call_user_func( $map[ $action ], $id ) : new WP_Error( 'mlab_smtp_action', __( 'Unbekannte Aktion.', 'mlab-smtp' ) );
}

/**
 * Wait in seconds of the oldest message that is due and not yet taken (0 when none).
 */
function mlab_smtp_engine_oldest_wait(): int {
	$due = (int) mlab_smtp_store_run(
		static function () {
			global $wpdb;
			return $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(next_attempt_at) FROM ' . mlab_smtp_table( 'messages' ) . " WHERE status IN ('queued','temp_failed') AND next_attempt_at <= %d AND (locked_until IS NULL OR locked_until < %d)", time(), time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
		},
		0
	);
	return $due > 0 ? max( 0, time() - $due ) : 0;
}

/**
 * State of the WP-Cron that drives the worker.
 *
 * @return array{disabled:bool, scheduled:bool, next:int, last_run:int}
 */
function mlab_smtp_engine_cron_state(): array {
	return array(
		'disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		'scheduled' => false !== wp_next_scheduled( MLAB_SMTP_QUEUE_HOOK ),
		'next'      => (int) wp_next_scheduled( MLAB_SMTP_QUEUE_HOOK ),
		'last_run'  => (int) get_option( 'mlab_smtp_engine_last_run', 0 ),
	);
}

/**
 * Counts and texts of the queue for health rows and the overview.
 *
 * @return array{mode:string, retry_in_background:bool, waiting:int, processing:int, unclear:int, failed:int, oldest_wait:int, stale:bool, cron:array, paused:array, cron_text:string}
 */
function mlab_smtp_engine_summary(): array {
	$counts = mlab_smtp_store_counts();
	$wait   = mlab_smtp_engine_oldest_wait();
	$cron   = mlab_smtp_engine_cron_state();
	return array(
		'mode'                => mlab_smtp_engine_mode(),
		'retry_in_background' => (bool) mlab_smtp_get( 'retry_in_background' ),
		'waiting'             => (int) $counts['queued'] + (int) $counts['temp_failed'],
		'processing'          => (int) $counts['processing'],
		'unclear'             => (int) $counts['unclear'],
		'failed'              => (int) $counts['failed'],
		'oldest_wait'         => $wait,
		'stale'               => $wait > 10 * MINUTE_IN_SECONDS,
		'cron'                => $cron,
		'paused'              => mlab_smtp_engine_pauses(),
		'cron_text'           => mlab_smtp_engine_cron_text( $cron ),
	);
}

/**
 * Plain explanation of the cron state with the recommendation for a system cron.
 *
 * @param array $cron Result of mlab_smtp_engine_cron_state().
 */
function mlab_smtp_engine_cron_text( array $cron ): string {
	$parts = array();
	if ( $cron['disabled'] ) {
		$parts[] = __( 'WP-Cron ist abgeschaltet (DISABLE_WP_CRON). Die Warteschlange läuft nur, wenn ein System-Cron wp-cron.php aufruft.', 'mlab-smtp' );
	} elseif ( ! $cron['scheduled'] ) {
		$parts[] = __( 'Das Cron-Ereignis der Warteschlange ist nicht geplant. Es wird beim nächsten Seitenaufruf neu angelegt.', 'mlab-smtp' );
	} else {
		$parts[] = $cron['last_run'] > 0
			/* translators: %s: time since the last run */
			? sprintf( __( 'Der Worker lief zuletzt vor %s.', 'mlab-smtp' ), human_time_diff( $cron['last_run'] ) )
			: __( 'Der Worker lief noch nie.', 'mlab-smtp' );
	}
	$parts[] = __( 'Empfehlung: einen System-Cron einrichten, der jede Minute «wget -q -O - https://ihre-domain.ch/wp-cron.php?doing_wp_cron» oder «wp cron event run --due-now» aufruft, und WP-Cron mit DISABLE_WP_CRON abschalten.', 'mlab-smtp' );
	return implode( ' ', $parts );
}
