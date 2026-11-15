<?php
/**
 * Übernahme des alten Protokolls (Option `mlab_smtp_log`) in die Tabellen.
 *
 * Batches von 100, wiederaufnehmbar über `legacy_key` (UNIQUE), mit atomarer Sperre (add_option) samt Ablaufzeit.
 * Zustand in `mlab_smtp_migration`. Die alte Option wird nie gelöscht und nach der Übernahme nicht mehr geschrieben,
 * sie bleibt eine Release lang für eine Rückstufung erhalten (uninstall.php löscht sie).
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_MIGRATION_OPTION = 'mlab_smtp_migration';
const MLAB_SMTP_MIGRATION_LOCK   = 'mlab_smtp_migration_lock';

add_action( 'admin_init', 'mlab_smtp_migration_maybe_run' );
add_action( 'admin_notices', 'mlab_smtp_migration_notice' );

/**
 * Migration state.
 *
 * @return array{done:bool, total:int, migrated:int, failed:int, stuck:int, last_error:string, updated:int}
 */
function mlab_smtp_migration_state(): array {
	return wp_parse_args(
		(array) get_option( MLAB_SMTP_MIGRATION_OPTION, array() ),
		array(
			'done'       => false,
			'total'      => 0,
			'migrated'   => 0,
			'failed'     => 0,
			'stuck'      => 0,
			'last_error' => '',
			'updated'    => 0,
		)
	);
}

/**
 * Takes the migration lock (atomic through add_option). An expired lock of a dead run is replaced.
 *
 * @param int $ttl Lock lifetime in seconds.
 */
function mlab_smtp_migration_lock( int $ttl = 120 ): bool {
	if ( add_option( MLAB_SMTP_MIGRATION_LOCK, time() + $ttl, '', false ) ) {
		return true;
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( MLAB_SMTP_MIGRATION_LOCK, 'options' );
	if ( (int) get_option( MLAB_SMTP_MIGRATION_LOCK, 0 ) >= time() ) {
		return false;
	}
	delete_option( MLAB_SMTP_MIGRATION_LOCK );
	return (bool) add_option( MLAB_SMTP_MIGRATION_LOCK, time() + $ttl, '', false );
}

/**
 * Stable key of an old entry (its id, or a fingerprint for entries without id).
 *
 * @param array $entry Old entry.
 */
function mlab_smtp_migration_key( array $entry ): string {
	$id = (string) ( $entry['id'] ?? '' );
	return sha1( '' !== $id ? 'id:' . $id : 'e:' . ( $entry['time'] ?? 0 ) . '|' . ( $entry['to'] ?? '' ) . '|' . ( $entry['subject'] ?? '' ) . '|' . ( $entry['error'] ?? '' ) );
}

/**
 * Old entries with their key, as an array key => entry.
 *
 * @return array<string,array>
 */
function mlab_smtp_migration_entries(): array {
	$old    = get_option( MLAB_SMTP_LOG_OPTION, array() );
	$cutoff = time() - max( 1, (int) mlab_smtp_get( 'log_retention' ) ) * DAY_IN_SECONDS;
	$out    = array();
	foreach ( is_array( $old ) ? $old : array() as $entry ) {
		// Entries beyond the retention period (or of unknown age) are not imported: they must not come back.
		if ( is_array( $entry ) && (int) ( $entry['time'] ?? 0 ) >= $cutoff ) {
			$out[ mlab_smtp_migration_key( $entry ) ] = $entry;
		}
	}
	return $out;
}

/**
 * Writes one old entry as a message row.
 *
 * @param string $key   legacy_key.
 * @param array  $entry Old entry.
 * @return bool
 */
function mlab_smtp_migration_insert( string $key, array $entry ): bool {
	if ( apply_filters( 'mlab_smtp_migration_skip_entry', false, $entry ) ) {
		return false;
	}
	$id   = (string) ( $entry['id'] ?? '' );
	$ok   = ! isset( $entry['ok'] ) || (bool) $entry['ok'];
	$time = (int) ( $entry['time'] ?? 0 );
	return false !== mlab_smtp_store_create(
		array(
			'uuid'       => preg_match( '/^[0-9a-f-]{36}$/i', $id ) ? strtolower( $id ) : wp_generate_uuid4(),
			'legacy_key' => $key,
			'status'     => $ok ? 'accepted' : 'failed',
			'recipients' => explode( ',', (string) ( $entry['to'] ?? '' ) ),
			'subject'    => (string) ( $entry['subject'] ?? '' ),
			'via'        => (string) ( $entry['via'] ?? '' ),
			'fallback'   => ! empty( $entry['fallback'] ),
			'last_error' => (string) ( $entry['error'] ?? '' ),
			'created_at' => $time,
		)
	);
}

/**
 * One batch: inserts up to $batch entries that are not in the tables yet.
 *
 * @param array<string,array> $entries Old entries by key.
 * @param int                 $batch   Batch size.
 * @return array{inserted:int, failed:int, pending:int} Counts; pending = entries still missing after this batch.
 */
function mlab_smtp_migration_batch( array $entries, int $batch ): array {
	$pending  = array_diff_key( $entries, array_flip( mlab_smtp_store_existing_legacy_keys( array_keys( $entries ) ) ) );
	$inserted = 0;
	$failed   = 0;
	foreach ( array_slice( $pending, 0, $batch, true ) as $key => $entry ) {
		if ( mlab_smtp_migration_insert( (string) $key, $entry ) ) {
			++$inserted;
		} else {
			++$failed;
		}
	}
	return array(
		'inserted' => $inserted,
		'failed'   => $failed,
		'pending'  => count( $pending ) - $inserted,
	);
}

/**
 * Hook on admin_init: one small step while the migration is open (a cheap option read once it is done).
 */
function mlab_smtp_migration_maybe_run(): void {
	mlab_smtp_migration_run();
}

/**
 * Runs a small migration step (lazy, never longer than one batch). Safe to call often.
 *
 * @param int $batch Entries per step.
 * @return array State after the step.
 */
function mlab_smtp_migration_run( int $batch = 100 ): array {
	$state = mlab_smtp_migration_state();
	if ( $state['done'] || ! mlab_smtp_db_ready() || ! mlab_smtp_migration_lock() ) {
		return $state;
	}
	$entries            = mlab_smtp_migration_entries();
	$result             = mlab_smtp_migration_batch( $entries, $batch );
	$state['total']     = count( $entries );
	$state['migrated'] += $result['inserted'];
	$state['failed']    = $result['failed'];
	$state['done']      = 0 === $result['pending'];
	$progress           = $result['inserted'] > 0 || 0 === $result['pending'];
	$state['stuck']     = $progress ? 0 : (int) $state['stuck'] + 1;
	if ( $result['failed'] > 0 && ! $state['done'] ) {
		$state['last_error'] = sprintf( 'Einträge nicht übernommen: %d', $result['failed'] );
	} else {
		$state['last_error'] = '';
	}
	$state['updated'] = time();
	update_option( MLAB_SMTP_MIGRATION_OPTION, $state, true );
	delete_option( MLAB_SMTP_MIGRATION_LOCK );
	return $state;
}

/**
 * Admin notice, only when the migration keeps failing (not on a single failed run).
 */
function mlab_smtp_migration_notice(): void {
	$state = mlab_smtp_migration_state();
	if ( $state['done'] || $state['stuck'] < 3 || '' === $state['last_error'] || ! current_user_can( mlab_smtp_capability() ) ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
		esc_html__( 'MomentumLab SMTP:', 'mlab-smtp' ),
		esc_html__( 'Das alte Versandprotokoll konnte nicht vollständig übernommen werden. Die alten Einträge bleiben erhalten, die Übernahme wird automatisch wiederholt. Besteht der Fehler weiter, prüfen Sie die Datenbankrechte oder wenden Sie sich an den Support.', 'mlab-smtp' )
	);
}

/**
 * Runs a callback while the migration lock is held, so that no migration batch can write at the same time.
 * This is what keeps an erased entry from coming back: a running batch holds entries it read earlier.
 *
 * @param callable $callback Work to do.
 * @return mixed|null Result of the callback, null when the lock could not be taken.
 */
function mlab_smtp_legacy_log_locked( callable $callback ) {
	if ( ! mlab_smtp_migration_lock( 60 ) ) {
		return null;
	}
	try {
		return $callback();
	} finally {
		delete_option( MLAB_SMTP_MIGRATION_LOCK );
	}
}

/**
 * Rewrites the old log option so that only entries for which $keep returns true stay, then checks the stored result.
 * The old option is a copy for a downgrade; what may no longer be kept must not stay in it.
 *
 * @param callable $keep Receives an entry, returns true to keep it.
 * @return array{ok:bool, removed:int} ok is false when entries that had to go are still stored afterwards.
 */
function mlab_smtp_legacy_log_rewrite( callable $keep ): array {
	$old = get_option( MLAB_SMTP_LOG_OPTION, false );
	if ( ! is_array( $old ) ) {
		return array(
			'ok'      => true,
			'removed' => 0,
		);
	}
	$kept = array_values( array_filter( $old, static fn( $entry ): bool => is_array( $entry ) && $keep( $entry ) ) );
	if ( count( $kept ) === count( $old ) ) {
		return array(
			'ok'      => true,
			'removed' => 0,
		);
	}
	if ( $kept ) {
		update_option( MLAB_SMTP_LOG_OPTION, $kept, false );
	} else {
		delete_option( MLAB_SMTP_LOG_OPTION );
	}
	wp_cache_delete( MLAB_SMTP_LOG_OPTION, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	$stored = get_option( MLAB_SMTP_LOG_OPTION, array() );
	$clean  = true;
	foreach ( is_array( $stored ) ? $stored : array() as $entry ) {
		$clean = $clean && is_array( $entry ) && $keep( $entry );
	}
	return array(
		'ok'      => $clean,
		'removed' => count( $old ) - count( $kept ),
	);
}

/**
 * E-mail addresses of an old entry (field «to»: addresses separated by commas, with or without names).
 *
 * @param array $entry Old entry.
 * @return string[] Lower case.
 */
function mlab_smtp_legacy_entry_addresses( array $entry ): array {
	preg_match_all( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', (string) ( $entry['to'] ?? '' ), $m );
	return array_map( 'strtolower', $m[0] );
}

/**
 * Removes every entry of one recipient from the old log copy (personal data erasure).
 *
 * @param string $email Address.
 * @return array{ok:bool, removed:int} ok is false when the copy could not be cleaned (lock held, database error).
 */
function mlab_smtp_legacy_log_erase_email( string $email ): array {
	$needle = strtolower( trim( $email ) );
	$result = mlab_smtp_legacy_log_locked(
		static fn(): array => mlab_smtp_legacy_log_rewrite( static fn( array $entry ): bool => ! in_array( $needle, mlab_smtp_legacy_entry_addresses( $entry ), true ) )
	);
	return $result ?? array(
		'ok'      => false,
		'removed' => 0,
	);
}

/**
 * Enforces the retention period on the old log copy. Entries without a valid time cannot prove their age and go.
 *
 * @param int $days Retention in days.
 * @return bool False when the copy could not be cleaned.
 */
function mlab_smtp_legacy_log_prune( int $days ): bool {
	$cutoff = time() - max( 1, $days ) * DAY_IN_SECONDS;
	$result = mlab_smtp_legacy_log_locked(
		static fn(): array => mlab_smtp_legacy_log_rewrite( static fn( array $entry ): bool => (int) ( $entry['time'] ?? 0 ) >= $cutoff )
	);
	return null !== $result && $result['ok'];
}

/**
 * Empties the old log copy («clear log»).
 *
 * @return bool False when the copy could not be emptied.
 */
function mlab_smtp_legacy_log_clear(): bool {
	$result = mlab_smtp_legacy_log_locked( static fn(): array => mlab_smtp_legacy_log_rewrite( '__return_false' ) );
	return null !== $result && $result['ok'];
}

