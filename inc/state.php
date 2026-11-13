<?php
/**
 * Versandzustand: letzter Erfolg und Fehler, Status für die Übersicht, Statistik.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_STATE_OPTION = 'mlab_smtp_state';

add_action( 'wp_mail_succeeded', 'mlab_smtp_state_on_success' );
add_action( 'wp_mail_failed', 'mlab_smtp_state_on_failure' );

/**
 * Stored state, merged with its defaults.
 *
 * @return array{last_success:int, last_failure:int, last_error:string, failing_since:int, failures_since:int, last_fallback:bool, expiry_notified:array}
 */
function mlab_smtp_state_get(): array {
	$stored = get_option( MLAB_SMTP_STATE_OPTION, array() );
	return wp_parse_args(
		is_array( $stored ) ? $stored : array(),
		array(
			'last_success'    => 0,
			'last_failure'    => 0,
			'last_error'      => '',
			'failing_since'   => 0,
			'failures_since'  => 0,
			'last_fallback'   => false,
			'expiry_notified' => array(),
		)
	);
}

/**
 * Merges values into the stored state.
 *
 * @param array $changes Key => value.
 */
function mlab_smtp_state_set( array $changes ): void {
	update_option( MLAB_SMTP_STATE_OPTION, array_merge( mlab_smtp_state_get(), $changes ), false );
}

/**
 * True when the setup was finished and a provider is chosen. This alone decides whether the plugin takes over
 * wp_mail(): missing or unreadable credentials must make sending fail visibly instead of silently falling
 * back to PHP mail(). See mlab_smtp_has_credentials() for the credentials.
 */
function mlab_smtp_is_configured(): bool {
	return 1 === (int) mlab_smtp_get( 'setup_done' ) && in_array( mlab_smtp_get( 'provider' ), mlab_smtp_choices()['provider'], true );
}

/**
 * True when the chosen provider has all its required fields (the setup does not have to be finished).
 */
function mlab_smtp_has_credentials(): bool {
	$provider = (string) mlab_smtp_get( 'provider' );
	$keys     = mlab_smtp_required_keys( $provider );
	foreach ( $keys as $key ) {
		if ( '' === (string) mlab_smtp_get( $key ) ) {
			return false;
		}
	}
	return (bool) $keys;
}

/**
 * Error text for credentials that are missing or can no longer be decrypted.
 */
function mlab_smtp_credentials_error(): string {
	return __( 'Zugangsdaten nicht lesbar / fehlen – bitte im Assistenten neu eingeben.', 'mlab-smtp' );
}

/**
 * Counts one send result. A failure of the primary route counts even when the fallback delivered.
 *
 * @param bool   $ok       True when the message was delivered (by any route).
 * @param string $error    Error of the primary route, empty when none.
 * @param bool   $fallback True when the fallback route delivered.
 */
function mlab_smtp_record( bool $ok, string $error, bool $fallback ): void {
	$state = mlab_smtp_state_get();
	$now   = time();
	if ( $ok ) {
		$state['last_success'] = $now;
	}
	if ( $ok && ! $fallback ) {
		$state['failing_since']  = 0;
		$state['failures_since'] = 0;
		$state['last_error']     = '';
	} else {
		$state['last_failure']    = $now;
		$state['last_error']      = $error;
		$state['failing_since']   = $state['failing_since'] ? $state['failing_since'] : $now;
		$state['failures_since'] += 1;
	}
	$state['last_fallback'] = $ok && $fallback;
	update_option( MLAB_SMTP_STATE_OPTION, $state, false );
	if ( ! $ok || $fallback ) {
		/**
		 * Fires when the primary route failed.
		 *
		 * @param string $error    Error message.
		 * @param bool   $fallback True when the fallback delivered the message anyway.
		 */
		do_action( 'mlab_smtp_failure', $error, $fallback );
	}
}

/**
 * Counts a delivered message. Only mails that the plugin handled are counted.
 */
function mlab_smtp_state_on_success(): void {
	if ( ! mlab_smtp_tracking() ) {
		return;
	}
	$route = mlab_smtp_route_result();
	mlab_smtp_record( true, $route['primary_error'], $route['fallback'] );
}

/**
 * Counts a failed message. Only mails that the plugin handled are counted.
 *
 * @param WP_Error $error Error of wp_mail().
 */
function mlab_smtp_state_on_failure( $error ): void {
	if ( ! mlab_smtp_tracking() || ! is_wp_error( $error ) ) {
		return;
	}
	mlab_smtp_record( false, $error->get_error_message(), false );
}

/**
 * Status for the overview page.
 *
 * @return array{state:string, title:string, text:string, since:int, failures:int}
 */
function mlab_smtp_status(): array {
	$state  = mlab_smtp_state_get();
	$result = array(
		'state'    => 'ok',
		'title'    => __( 'Mails werden zugestellt', 'mlab-smtp' ),
		'text'     => $state['last_success'] ? '' : __( 'Es wurde noch keine Mail über das Plugin versendet. Senden Sie eine Testmail, um den Versand zu prüfen.', 'mlab-smtp' ),
		'since'    => (int) $state['failing_since'],
		'failures' => (int) $state['failures_since'],
	);
	if ( ! mlab_smtp_is_configured() ) {
		return array_merge(
			$result,
			array(
				'state' => 'none',
				'title' => __( 'Noch nicht eingerichtet', 'mlab-smtp' ),
				'text'  => __( 'WordPress versendet Mails direkt über den Webserver. Starten Sie die Einrichtung, damit sie zuverlässig ankommen.', 'mlab-smtp' ),
			)
		);
	}
	if ( ! mlab_smtp_has_credentials() ) {
		return array_merge(
			$result,
			array(
				'state' => 'bad',
				'title' => __( 'Mails werden nicht zugestellt', 'mlab-smtp' ),
				'text'  => mlab_smtp_credentials_error(),
			)
		);
	}
	if ( ! $state['failing_since'] ) {
		return $result;
	}
	$hint = mlab_smtp_explain_error( (string) $state['last_error'] );
	$text = '' !== $hint ? $hint : (string) $state['last_error'];
	if ( $state['last_fallback'] ) {
		return array_merge(
			$result,
			array(
				'state' => 'warn',
				'title' => __( 'Hauptweg gestört, Ersatzweg stellt zu', 'mlab-smtp' ),
				'text'  => $text,
			)
		);
	}
	return array_merge(
		$result,
		array(
			'state' => 'bad',
			'title' => __( 'Mails werden nicht zugestellt', 'mlab-smtp' ),
			'text'  => $text,
		)
	);
}

/**
 * Statistics from the log.
 *
 * @param int $days Number of days for the daily series.
 * @return array{sent:int, failed:int, fallback:int, last:int, daily:int[], daily_failed:int[]}
 */
function mlab_smtp_stats( int $days = 14 ): array {
	$days = max( 1, $days );
	$keys = array();
	for ( $i = $days - 1; $i >= 0; $i-- ) {
		$keys[ wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) ] = 0;
	}
	$stats = array(
		'sent'         => 0,
		'failed'       => 0,
		'fallback'     => 0,
		'last'         => 0,
		'daily'        => $keys,
		'daily_failed' => $keys,
	);
	$week  = time() - 7 * DAY_IN_SECONDS;
	foreach ( mlab_smtp_log_get() as $entry ) {
		$stats = mlab_smtp_stats_add( $stats, $entry, $week );
	}
	$stats['daily']        = array_values( $stats['daily'] );
	$stats['daily_failed'] = array_values( $stats['daily_failed'] );
	return $stats;
}

/**
 * Adds one log entry to the statistics.
 *
 * @param array $stats Statistics so far.
 * @param array $entry Log entry.
 * @param int   $week  Timestamp of the start of the 7 day window.
 * @return array Updated statistics.
 */
function mlab_smtp_stats_add( array $stats, array $entry, int $week ): array {
	$time          = (int) $entry['time'];
	$day           = wp_date( 'Y-m-d', $time );
	$stats['last'] = max( $stats['last'], $time );
	if ( $time >= $week ) {
		$stats['sent']     += $entry['ok'] ? 1 : 0;
		$stats['failed']   += $entry['ok'] ? 0 : 1;
		$stats['fallback'] += $entry['fallback'] ? 1 : 0;
	}
	if ( isset( $stats['daily'][ $day ] ) ) {
		$stats['daily'][ $day ]        += 1;
		$stats['daily_failed'][ $day ] += $entry['ok'] ? 0 : 1;
	}
	return $stats;
}
