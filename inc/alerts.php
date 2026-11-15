<?php
/**
 * Alarmierung per Webhook. Unabhängig vom Mailweg: ausschliesslich HTTP, auch wenn der Mailversand ausfällt.
 *
 * Einstellungen in der eigenen Option mlab_smtp_alerts (kein Autoload): url (https), Signatur-Secret (verschlüsselt,
 * wird nie zurückgegeben), Ereignisse primary_failing, fallback_active, queue_stalled, final_failures. Zustand
 * (letztes Ergebnis, Drosselung) in mlab_smtp_alerts_state.
 *
 * API für die Oberfläche: mlab_smtp_alerts_get(), mlab_smtp_alerts_save(), mlab_smtp_alert_test(),
 * mlab_smtp_alerts_last(), mlab_smtp_alert_dispatch(). Admin-Aktionen: mlab_smtp_alert_test, mlab_smtp_alert_save.
 * Der Inhalt enthält nie Empfänger, Betreffe oder Secrets.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_ALERTS_OPTION = 'mlab_smtp_alerts';
const MLAB_SMTP_ALERTS_STATE  = 'mlab_smtp_alerts_state';

add_action( 'mlab_smtp_failure', 'mlab_smtp_alerts_on_failure', 20, 2 );
add_action( 'mlab_smtp_message_status_changed', 'mlab_smtp_alerts_on_status_changed', 20, 10 );
add_action( 'mlab_smtp_message_failed', 'mlab_smtp_alerts_on_message_failed', 20, 10 );
add_action( 'mlab_smtp_queue_stalled', 'mlab_smtp_alerts_on_queue_stalled', 20, 1 );
add_action( 'mlab_smtp_daily', 'mlab_smtp_alerts_check_queue' );
add_action( 'admin_post_mlab_smtp_alert_test', 'mlab_smtp_alert_handle_test' );
add_action( 'admin_post_mlab_smtp_alert_save', 'mlab_smtp_alert_handle_save' );

/**
 * Event keys with German labels.
 *
 * @return array<string,string>
 */
function mlab_smtp_alert_events(): array {
	return array(
		'primary_failing' => __( 'Hauptweg gestört', 'mlab-smtp' ),
		'fallback_active' => __( 'Ersatzweg aktiv', 'mlab-smtp' ),
		'queue_stalled'   => __( 'Warteschlange staut sich', 'mlab-smtp' ),
		'final_failures'  => __( 'Endgültige Fehler', 'mlab-smtp' ),
	);
}

/**
 * Stored settings for display. The signing secret is never returned, only whether one exists.
 *
 * @return array{url:string, has_secret:bool, events:array<string,bool>}
 */
function mlab_smtp_alerts_get(): array {
	$stored = get_option( MLAB_SMTP_ALERTS_OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();
	$events = array();
	foreach ( array_keys( mlab_smtp_alert_events() ) as $key ) {
		$events[ $key ] = ! isset( $stored['events'][ $key ] ) || ! empty( $stored['events'][ $key ] );
	}
	return array(
		'url'        => (string) ( $stored['url'] ?? '' ),
		'has_secret' => '' !== (string) ( $stored['secret'] ?? '' ),
		'events'     => $events,
	);
}

/**
 * Validates and stores the settings.
 *
 * @param array $input url, secret (empty keeps the stored one), clear_secret (bool), events (key => bool).
 * @return string[] German error messages, empty when saved.
 */
function mlab_smtp_alerts_save( array $input ): array {
	$stored = get_option( MLAB_SMTP_ALERTS_OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();
	$url    = trim( (string) ( $input['url'] ?? '' ) );
	if ( '' !== $url ) {
		$safe = mlab_smtp_safe_url( $url );
		if ( is_wp_error( $safe ) ) {
			return array( $safe->get_error_message() );
		}
		$url = esc_url_raw( $safe, array( 'https' ) );
	}
	$secret = (string) ( $input['secret'] ?? '' );
	if ( ! empty( $input['clear_secret'] ) ) {
		$stored['secret'] = '';
	} elseif ( '' !== $secret ) {
		if ( ! mlab_smtp_crypto_available() ) {
			return array( mlab_smtp_crypto_error()->get_error_message() );
		}
		$stored['secret'] = mlab_smtp_encrypt( $secret );
	}
	$events = array();
	foreach ( array_keys( mlab_smtp_alert_events() ) as $key ) {
		$events[ $key ] = ! empty( $input['events'][ $key ] );
	}
	$stored['url']    = $url;
	$stored['events'] = $events;
	update_option( MLAB_SMTP_ALERTS_OPTION, $stored, false );
	return array();
}

/**
 * Last delivery result of an alert, for the UI.
 *
 * @return array{ok:bool, time:int, code:int, event:string, message:string}
 */
function mlab_smtp_alerts_last(): array {
	$state = get_option( MLAB_SMTP_ALERTS_STATE, array() );
	$last  = is_array( $state ) && is_array( $state['last'] ?? null ) ? $state['last'] : array();
	return wp_parse_args(
		$last,
		array(
			'ok'      => false,
			'time'    => 0,
			'code'    => 0,
			'event'   => '',
			'message' => '',
		)
	);
}

/**
 * Remembers a result and the throttle time.
 *
 * @param string $event  Event.
 * @param array  $result ok, code, message.
 * @param bool   $throttle True to start the throttle window.
 */
function mlab_smtp_alerts_remember( string $event, array $result, bool $throttle ): void {
	$state = get_option( MLAB_SMTP_ALERTS_STATE, array() );
	$state = is_array( $state ) ? $state : array();
	if ( $throttle ) {
		$state['sent'][ $event ] = time();
	}
	$state['last'] = array(
		'ok'      => (bool) $result['ok'],
		'time'    => time(),
		'code'    => (int) $result['code'],
		'event'   => $event,
		'message' => mlab_smtp_redact( substr( (string) $result['message'], 0, 300 ) ),
	);
	update_option( MLAB_SMTP_ALERTS_STATE, $state, false );
}

/**
 * Sends one alert if the event is switched on and not throttled. Never throws.
 *
 * @param string $event primary_failing, fallback_active, queue_stalled or final_failures.
 * @param array  $data  count (int), text (short text, is redacted).
 * @return string sent, failed, throttled, disabled or off.
 */
function mlab_smtp_alert_dispatch( string $event, array $data = array() ): string {
	try {
		$settings = mlab_smtp_alerts_get();
		if ( '' === $settings['url'] ) {
			return 'off';
		}
		if ( empty( $settings['events'][ $event ] ) ) {
			return 'disabled';
		}
		$state    = get_option( MLAB_SMTP_ALERTS_STATE, array() );
		$last     = (int) ( is_array( $state ) ? ( $state['sent'][ $event ] ?? 0 ) : 0 );
		$throttle = (int) apply_filters( 'mlab_smtp_alert_throttle', 60 * MINUTE_IN_SECONDS, $event );
		if ( $last && time() - $last < $throttle ) {
			return 'throttled';
		}
		$result = mlab_smtp_alert_send( $event, $data );
		mlab_smtp_alerts_remember( $event, $result, true );
		return $result['ok'] ? 'sent' : 'failed';
	} catch ( Throwable $e ) {
		return 'failed';
	}
}

/**
 * Body of an alert. No recipients, no subjects, no secrets.
 *
 * @param string $event Event.
 * @param array  $data  count, text.
 */
function mlab_smtp_alert_payload( string $event, array $data ): string {
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$text = mlab_smtp_redact( substr( (string) ( $data['text'] ?? '' ), 0, 300 ), true );
	return (string) wp_json_encode(
		array(
			'site'      => $host,
			'event'     => $event,
			'timestamp' => gmdate( 'c' ),
			'counts'    => array( 'affected' => (int) ( $data['count'] ?? 0 ) ),
			'text'      => $text,
		)
	);
}

/**
 * Sends the payload (HTTPS only, checked again now, signed when a secret is set).
 *
 * @param string $event Event.
 * @param array  $data  count, text.
 * @return array{ok:bool, message:string, code:int}
 */
function mlab_smtp_alert_send( string $event, array $data ): array {
	$stored = get_option( MLAB_SMTP_ALERTS_OPTION, array() );
	$url    = (string) ( $stored['url'] ?? '' );
	if ( '' === $url ) {
		return array(
			'ok'      => false,
			'message' => __( 'Es ist keine Adresse für den Alarm eingetragen.', 'mlab-smtp' ),
			'code'    => 0,
		);
	}
	$body    = mlab_smtp_alert_payload( $event, $data );
	$headers = array( 'X-Mlab-Timestamp' => (string) time() );
	$secret  = '' !== (string) ( $stored['secret'] ?? '' ) ? mlab_smtp_decrypt( (string) $stored['secret'] ) : '';
	if ( '' !== $secret ) {
		$headers['X-Mlab-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
	}
	$response = mlab_smtp_safe_post( $url, $body, $headers );
	if ( is_wp_error( $response ) ) {
		return array(
			'ok'      => false,
			/* translators: %s: error text */
			'message' => sprintf( __( 'Der Alarm konnte nicht gesendet werden: %s', 'mlab-smtp' ), $response->get_error_message() ),
			'code'    => 0,
		);
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$ok   = $code >= 200 && $code < 300;
	return array(
		'ok'      => $ok,
		/* translators: %d: HTTP status code */
		'message' => $ok ? sprintf( __( 'Der Alarm wurde zugestellt (HTTP %d).', 'mlab-smtp' ), $code ) : sprintf( __( 'Die Gegenstelle hat den Alarm abgelehnt (HTTP %d).', 'mlab-smtp' ), $code ),
		'code'    => $code,
	);
}

/**
 * Sends a clearly marked test event, ignoring toggles and throttle, and reports the result in plain German.
 *
 * @return array{ok:bool, message:string, code:int}
 */
function mlab_smtp_alert_test(): array {
	try {
		$result = mlab_smtp_alert_send(
			'test',
			array( 'text' => 'TEST: Dies ist ein Testalarm von mlab-smtp. Es ist nichts passiert.' )
		);
	} catch ( Throwable $e ) {
		$result = array(
			'ok'      => false,
			'message' => __( 'Der Test-Alarm konnte nicht gesendet werden.', 'mlab-smtp' ),
			'code'    => 0,
		);
	}
	mlab_smtp_alerts_remember( 'test', $result, false );
	return $result;
}

/**
 * Handles the action mlab_smtp_failure: the primary route failed, and maybe the fallback delivered.
 *
 * @param string $error    Error text.
 * @param bool   $fallback True when the fallback delivered.
 */
function mlab_smtp_alerts_on_failure( $error = '', $fallback = false ): void {
	$count = (int) mlab_smtp_state_get()['failures_since'];
	/* translators: %s: error text */
	mlab_smtp_alert_dispatch(
		'primary_failing',
		array(
			'count' => $count,
			// translators: %s: error text.
			'text'  => sprintf( __( 'Der Hauptweg meldet einen Fehler: %s', 'mlab-smtp' ), (string) $error ),
		)
	);
	if ( $fallback ) {
		mlab_smtp_alert_dispatch(
			'fallback_active',
			array(
				'count' => $count,
				'text'  => __( 'Mails werden zurzeit über den Ersatzweg gesendet.', 'mlab-smtp' ),
			)
		);
	}
}

/**
 * Handles the action mlab_smtp_message_status_changed (fired by the engine): a message reached a final failure.
 *
 * @param mixed ...$args Message id and status (engine defines the order); only a status of failed counts.
 */
function mlab_smtp_alerts_on_status_changed( ...$args ): void {
	if ( in_array( 'failed', array_filter( $args, 'is_string' ), true ) ) {
		mlab_smtp_alerts_on_message_failed();
	}
}

/**
 * Handles the action mlab_smtp_message_failed (fired by the engine): a message failed for good.
 */
function mlab_smtp_alerts_on_message_failed(): void {
	$count = (int) ( mlab_smtp_store_counts()['failed'] ?? 0 );
	mlab_smtp_alert_dispatch(
		'final_failures',
		array(
			'count' => $count,
			'text'  => __( 'Mindestens eine E-Mail konnte endgültig nicht zugestellt werden.', 'mlab-smtp' ),
		)
	);
}

/**
 * Handles the action mlab_smtp_queue_stalled.
 *
 * @param int $count Number of waiting messages.
 */
function mlab_smtp_alerts_on_queue_stalled( $count = 0 ): void {
	mlab_smtp_alert_dispatch(
		'queue_stalled',
		array(
			'count' => (int) $count,
			'text'  => __( 'Die Warteschlange wird nicht abgearbeitet.', 'mlab-smtp' ),
		)
	);
}

/**
 * Checks for messages that are overdue for more than 30 minutes and raises queue_stalled.
 */
function mlab_smtp_alerts_check_queue(): void {
	$limit = time() - (int) apply_filters( 'mlab_smtp_alert_stalled_after', 30 * MINUTE_IN_SECONDS );
	$count = (int) mlab_smtp_store_run(
		static function () use ( $limit ) {
			global $wpdb;
			return $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status IN ('queued','temp_failed') AND next_attempt_at < %d", mlab_smtp_table( 'messages' ), $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Table name from the plugin, counter query.
		},
		0
	);
	if ( $count > 0 ) {
		mlab_smtp_alerts_on_queue_stalled( $count );
	}
}

/**
 * Admin-post handler: test button.
 */
function mlab_smtp_alert_handle_test(): void {
	mlab_smtp_alert_guard( 'mlab_smtp_alert_test' );
	$result = mlab_smtp_alert_test();
	if ( function_exists( 'mlab_smtp_flash' ) ) {
		mlab_smtp_flash( $result['ok'] ? 'success' : 'error', $result['message'] );
	}
	mlab_smtp_alert_back();
}

/**
 * Admin-post handler: save.
 */
function mlab_smtp_alert_handle_save(): void {
	mlab_smtp_alert_guard( 'mlab_smtp_alert_save' );
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in the guard; the secret must stay unaltered and is validated by the sanitizer.
	$input = array(
		'url'          => isset( $_POST['url'] ) ? wp_unslash( (string) $_POST['url'] ) : '',
		'secret'       => isset( $_POST['secret'] ) ? wp_unslash( (string) $_POST['secret'] ) : '',
		'clear_secret' => ! empty( $_POST['clear_secret'] ),
		'events'       => isset( $_POST['events'] ) && is_array( $_POST['events'] ) ? array_map( 'boolval', wp_unslash( $_POST['events'] ) ) : array(),
	);
	// phpcs:enable
	$errors = mlab_smtp_alerts_save( $input );
	if ( function_exists( 'mlab_smtp_flash' ) ) {
		$errors ? mlab_smtp_flash( 'error', implode( ' ', $errors ) ) : mlab_smtp_flash( 'success', __( 'Die Alarmierung ist gespeichert.', 'mlab-smtp' ) );
	}
	mlab_smtp_alert_back();
}

/**
 * Permission and nonce check.
 *
 * @param string $action Nonce action.
 */
function mlab_smtp_alert_guard( string $action ): void {
	if ( function_exists( 'mlab_smtp_guard' ) ) {
		mlab_smtp_guard( $action );
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'mlab-smtp' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( $action );
}

/**
 * Redirects back to the settings and stops.
 */
function mlab_smtp_alert_back(): void {
	wp_safe_redirect( function_exists( 'mlab_smtp_page_url' ) ? mlab_smtp_page_url( array( 'view' => 'settings' ) ) : admin_url( 'admin.php?page=mlab-smtp&view=settings' ) );
	exit;
}
