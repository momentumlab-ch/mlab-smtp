<?php
/**
 * Versandprotokoll auf den Tabellen (eine Zeile je Nachricht, ohne Inhalt). Aufbewahrung nach Einstellung 7/30/90 Tage.
 *
 * Die Funktionsnamen und die Eintragsform (id, time, to, subject, ok, fallback, via, error) bleiben wie in 1.4,
 * neu sind status, uuid, attempts und message_id. Jeder Eintrag ist eine eigene Zeile, gleichzeitige Mails überschreiben sich nicht.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_LOG_OPTION = 'mlab_smtp_log';

add_action( 'wp_mail_succeeded', 'mlab_smtp_log_success' );
add_action( 'wp_mail_failed', 'mlab_smtp_log_failure' );

/**
 * Id of the message row the mailer wrote for the mail that is being sent (0 = none). When set, the log updates this row
 * instead of adding a second one.
 *
 * @param int|null $set Row id to remember (0 resets), null to only read.
 */
function mlab_smtp_log_active_message( ?int $set = null ): int {
	static $active = 0;
	if ( null !== $set ) {
		$active = $set;
	}
	return $active;
}

/**
 * Log entry from a message row.
 *
 * @param array $row Row from the store.
 * @return array{id:string, time:int, to:string, subject:string, ok:bool, fallback:bool, via:string, error:string, status:string, uuid:string, attempts:int, message_id:int}
 */
function mlab_smtp_log_entry( array $row ): array {
	$bad = array( 'failed', 'blocked', 'temp_failed', 'unclear', 'bounced', 'complained' );
	return array(
		'id'         => (string) $row['uuid'],
		'time'       => (int) $row['created_at'],
		'to'         => (string) $row['recipients'],
		'subject'    => (string) $row['subject'],
		'ok'         => ! in_array( $row['status'], $bad, true ),
		'fallback'   => (bool) $row['fallback'],
		'via'        => (string) $row['via'],
		'error'      => (string) $row['last_error'],
		'status'     => (string) $row['status'],
		'uuid'       => (string) $row['uuid'],
		'attempts'   => (int) $row['attempts'],
		'message_id' => (int) $row['id'],
	);
}

/**
 * Entries, newest first.
 *
 * @param array $args status (all|ok|bad; bad = failed or delivered by the fallback), search (recipient, subject or error), limit (0 = up to 5000), page and per_page for paging.
 * @return array<int, array>
 */
function mlab_smtp_log_get( array $args = array() ): array {
	$args  = wp_parse_args(
		$args,
		array(
			'status' => 'all',
			'search' => '',
			'limit'  => 0,
		)
	);
	$limit = (int) $args['limit'] > 0 ? (int) $args['limit'] : 5000;
	$query = array(
		'group'    => in_array( $args['status'], array( 'ok', 'bad' ), true ) ? $args['status'] : '',
		'search'   => (string) $args['search'],
		'per_page' => empty( $args['per_page'] ) ? min( 200, $limit ) : (int) $args['per_page'],
		'page'     => (int) ( $args['page'] ?? 1 ),
	);
	$list  = mlab_smtp_store_list( $query );
	$items = $list['items'];
	$have  = count( $items );
	while ( empty( $args['per_page'] ) && $query['page'] < $list['pages'] && $have < $limit ) {
		++$query['page'];
		$items = array_merge( $items, mlab_smtp_store_list( $query )['items'] );
		$have  = count( $items );
	}
	return array_map( 'mlab_smtp_log_entry', array_slice( $items, 0, $limit ) );
}

/**
 * One entry by id (the uuid).
 *
 * @param string $id Entry id.
 * @return array|null
 */
function mlab_smtp_log_find( string $id ): ?array {
	$row = mlab_smtp_store_find( $id );
	return $row ? mlab_smtp_log_entry( $row ) : null;
}

/**
 * Applies the retention period to the stored log, sweeps expired leases and runs a step of the migration.
 * Runs daily (mlab_smtp_daily).
 */
function mlab_smtp_log_purge(): void {
	$days = max( 1, (int) mlab_smtp_get( 'log_retention' ) );
	mlab_smtp_migration_run();
	// The old copy first: a later migration step must not import what the retention period already removed.
	mlab_smtp_legacy_log_prune( $days );
	mlab_smtp_store_purge( mlab_smtp_store_now() - $days * DAY_IN_SECONDS );
	mlab_smtp_store_sweep();
}

/**
 * Empties the log. Messages that still wait for sending stay.
 */
function mlab_smtp_log_clear(): bool {
	// The old copy first, under the migration lock: otherwise an open migration would bring the entries back.
	if ( ! mlab_smtp_legacy_log_clear() ) {
		return false;
	}
	$ids = mlab_smtp_store_finished_ids( 0, 500 );
	while ( $ids && mlab_smtp_store_delete_ids( $ids ) > 0 ) {
		$ids = mlab_smtp_store_finished_ids( 0, 500 );
	}
	return array() === mlab_smtp_store_finished_ids( 0, 1 );
}

/**
 * Kept for the 1.x options migration. Entries get their ids during the move into the tables, so there is nothing to do here.
 */
function mlab_smtp_log_upgrade(): void {
}

/**
 * Writes the log entry for the current mail. Secrets never end up here: only recipient, subject and the error text,
 * all passed through the redaction.
 *
 * @param array       $mail_data Data wp_mail() passes to its hooks (to, subject).
 * @param string|null $error     Error message, or null on success.
 */
function mlab_smtp_log_add( array $mail_data, ?string $error ): void {
	if ( ! mlab_smtp_get( 'log_enabled' ) || ! mlab_smtp_tracking() ) {
		return;
	}
	$route  = mlab_smtp_route_result();
	$fields = array(
		'via'        => $route['via'],
		'fallback'   => null === $error && $route['fallback'] ? 1 : 0,
		'last_error' => null === $error ? $route['primary_error'] : $error,
	);
	$active = mlab_smtp_log_active_message();
	if ( $active > 0 ) {
		mlab_smtp_store_set_status( $active, null === $error ? 'accepted' : 'failed', $fields, 0, true );
		return;
	}
	mlab_smtp_store_create(
		array(
			'recipients' => (array) ( $mail_data['to'] ?? array() ),
			'subject'    => (string) ( $mail_data['subject'] ?? '' ),
			'status'     => null === $error ? 'accepted' : 'failed',
			'via'        => $route['via'],
			'fallback'   => $fields['fallback'],
			'last_error' => $fields['last_error'],
		)
	);
}

/**
 * Logs a sent message.
 *
 * @param array $mail_data Data of the sent message.
 */
function mlab_smtp_log_success( $mail_data ): void {
	mlab_smtp_log_add( (array) $mail_data, null );
}

/**
 * Logs a failed message.
 *
 * @param WP_Error $error Error of wp_mail().
 */
function mlab_smtp_log_failure( $error ): void {
	if ( is_wp_error( $error ) ) {
		mlab_smtp_log_add( (array) $error->get_error_data(), $error->get_error_message() );
	}
}
