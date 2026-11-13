<?php
/**
 * Versandprotokoll: Nachrichten mit Ergebnis, ohne Inhalt. Begrenzt auf 500 Einträge und die Aufbewahrungsdauer.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_LOG_OPTION = 'mlab_smtp_log';
const MLAB_SMTP_LOG_LIMIT  = 500;

add_action( 'wp_mail_succeeded', 'mlab_smtp_log_success' );
add_action( 'wp_mail_failed', 'mlab_smtp_log_failure' );

/**
 * Raw entries, newest first, each with all keys.
 *
 * @return array<int, array{id:string, time:int, to:string, subject:string, ok:bool, fallback:bool, via:string, error:string}>
 */
function mlab_smtp_log_all(): array {
	$log = get_option( MLAB_SMTP_LOG_OPTION, array() );
	$log = is_array( $log ) ? $log : array();
	$def = array(
		'id'       => '',
		'time'     => 0,
		'to'       => '',
		'subject'  => '',
		'ok'       => true,
		'fallback' => false,
		'via'      => '',
		'error'    => '',
	);
	return array_map(
		static function ( $entry ) use ( $def ): array {
			return wp_parse_args( (array) $entry, $def );
		},
		$log
	);
}

/**
 * Entries, newest first.
 *
 * @param array $args status (all|ok|bad; bad = failed or delivered by the fallback), search (recipient or subject), limit (0 = all).
 * @return array<int, array{id:string, time:int, to:string, subject:string, ok:bool, fallback:bool, via:string, error:string}>
 */
function mlab_smtp_log_get( array $args = array() ): array {
	$args    = wp_parse_args(
		$args,
		array(
			'status' => 'all',
			'search' => '',
			'limit'  => 0,
		)
	);
	$entries = array_values(
		array_filter(
			mlab_smtp_log_all(),
			static function ( array $entry ) use ( $args ): bool {
				return mlab_smtp_log_matches( $entry, (string) $args['status'], (string) $args['search'] );
			}
		)
	);
	return (int) $args['limit'] > 0 ? array_slice( $entries, 0, (int) $args['limit'] ) : $entries;
}

/**
 * True when an entry passes the status filter and the search.
 *
 * @param array  $entry  Log entry.
 * @param string $status all, ok or bad.
 * @param string $search Text to find in recipient or subject.
 */
function mlab_smtp_log_matches( array $entry, string $status, string $search ): bool {
	$bad = ! $entry['ok'] || $entry['fallback'];
	if ( ( 'ok' === $status && $bad ) || ( 'bad' === $status && ! $bad ) ) {
		return false;
	}
	if ( '' === $search ) {
		return true;
	}
	return false !== stripos( $entry['to'] . ' ' . $entry['subject'], $search );
}

/**
 * One entry by id.
 *
 * @param string $id Entry id.
 * @return array|null
 */
function mlab_smtp_log_find( string $id ): ?array {
	foreach ( mlab_smtp_log_all() as $entry ) {
		if ( $entry['id'] === $id ) {
			return $entry;
		}
	}
	return null;
}

/**
 * Drops entries beyond the retention period and the size limit.
 *
 * @param array $log Entries, newest first.
 * @return array
 */
function mlab_smtp_log_trim( array $log ): array {
	$cutoff = time() - (int) mlab_smtp_get( 'log_retention' ) * DAY_IN_SECONDS;
	$log    = array_filter(
		$log,
		static function ( array $entry ) use ( $cutoff ): bool {
			return (int) $entry['time'] >= $cutoff;
		}
	);
	return array_slice( array_values( $log ), 0, MLAB_SMTP_LOG_LIMIT );
}

/**
 * Applies the retention period to the stored log.
 */
function mlab_smtp_log_purge(): void {
	update_option( MLAB_SMTP_LOG_OPTION, mlab_smtp_log_trim( mlab_smtp_log_all() ), false );
}

/**
 * Empties the log.
 */
function mlab_smtp_log_clear(): void {
	delete_option( MLAB_SMTP_LOG_OPTION );
}

/**
 * Gives entries from 1.0.x an id. Called by the migration.
 */
function mlab_smtp_log_upgrade(): void {
	if ( false === get_option( MLAB_SMTP_LOG_OPTION ) ) {
		return;
	}
	$log = mlab_smtp_log_all();
	foreach ( $log as $index => $entry ) {
		$log[ $index ]['id'] = '' !== $entry['id'] ? $entry['id'] : wp_generate_uuid4();
	}
	update_option( MLAB_SMTP_LOG_OPTION, $log, false );
}

/**
 * Adds one entry for the current mail and trims the log. Secrets never end up here: only recipient,
 * subject and the error text are stored.
 *
 * @param array       $mail_data Data wp_mail() passes to its hooks (to, subject).
 * @param string|null $error     Error message, or null on success.
 */
function mlab_smtp_log_add( array $mail_data, ?string $error ): void {
	if ( ! mlab_smtp_get( 'log_enabled' ) || ! mlab_smtp_tracking() ) {
		return;
	}
	$route = mlab_smtp_route_result();
	$log   = mlab_smtp_log_all();
	array_unshift(
		$log,
		array(
			'id'       => wp_generate_uuid4(),
			'time'     => time(),
			'to'       => sanitize_text_field( implode( ', ', (array) ( $mail_data['to'] ?? array() ) ) ),
			'subject'  => sanitize_text_field( (string) ( $mail_data['subject'] ?? '' ) ),
			'ok'       => null === $error,
			'fallback' => null === $error && $route['fallback'],
			'via'      => $route['via'],
			'error'    => sanitize_text_field( null === $error ? $route['primary_error'] : $error ),
		)
	);
	update_option( MLAB_SMTP_LOG_OPTION, mlab_smtp_log_trim( $log ), false );
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
