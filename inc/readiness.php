<?php
/**
 * Bereitschaft der Versandwege: eingetragen, geprüft, getestet, veraltet.
 *
 * Ausgefüllte Felder heissen nicht «bereit». Jedes Ergebnis einer Prüfung oder Testmail trägt einen Fingerabdruck
 * (Schlüssel-Hash über Anbieter, Zugangsdaten, Absender und Domain). Ändert sich eine dieser Eingaben, passt der
 * gespeicherte Fingerabdruck nicht mehr, und das Ergebnis gilt als veraltet.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_READINESS_OPTION = 'mlab_smtp_readiness';

/**
 * Normalizes a route name.
 *
 * @param string $route primary or fallback.
 */
function mlab_smtp_route_name( string $route ): string {
	return 'fallback' === $route ? 'fallback' : 'primary';
}

/**
 * Provider of a route.
 *
 * @param string $route primary or fallback.
 */
function mlab_smtp_route_provider( string $route ): string {
	return (string) mlab_smtp_get( 'fallback' === mlab_smtp_route_name( $route ) ? 'fb_provider' : 'provider' );
}

/**
 * Setting keys that tie a route to its connection (everything the provider needs to log in).
 *
 * @param string $route    primary or fallback.
 * @param string $provider Provider of the route.
 * @return string[]
 */
function mlab_smtp_route_connection_keys( string $route, string $provider ): array {
	$def = mlab_smtp_provider_def( $provider );
	if ( 'fallback' === $route ) {
		$prefix = 'fb_' . $provider . '_';
		$keys   = array_filter( array_keys( mlab_smtp_defaults() ), static fn( string $key ): bool => str_starts_with( $key, $prefix ) );
		return array_values( $keys );
	}
	$keys = array_merge( (array) ( $def['required'] ?? array() ), (array) ( $def['credentials'] ?? array() ), 'smtp' === $provider ? $def['group'] : array() );
	// The main route never depends on fallback settings, and the sender is part of the fingerprint on its own.
	$keys = array_filter( array_unique( $keys ), static fn( string $key ): bool => ! str_starts_with( $key, 'fb_' ) && ! in_array( $key, array( 'from_email', 'm365_secret_expires' ), true ) );
	return array_values( $keys );
}

/**
 * Inputs that make up the fingerprint of a route. Secrets are part of it (the hash is keyed and never shown).
 *
 * @param string $route primary or fallback.
 * @return array<string, mixed>
 */
function mlab_smtp_route_inputs( string $route ): array {
	$route    = mlab_smtp_route_name( $route );
	$provider = mlab_smtp_route_provider( $route );
	$values   = array();
	foreach ( mlab_smtp_route_connection_keys( $route, $provider ) as $key ) {
		$values[ $key ] = (string) mlab_smtp_get( $key );
	}
	$sender = (string) mlab_smtp_get( 'from_email' );
	$secret = (string) mlab_smtp_get( 'fallback' === $route ? 'fb_resend_api_key' : 'resend_api_key' );
	return array(
		'provider'   => $provider,
		'connection' => $values,
		'sender'     => strtolower( $sender ),
		'domain'     => mlab_smtp_domain_of( $sender ),
		'api_key_id' => 'resend' === $provider && '' !== $secret ? substr( hash( 'sha256', 'mlab-smtp-key|' . $secret ), 0, 8 ) : '',
	);
}

/**
 * Fingerprint of a route as it is configured right now.
 *
 * @param string $route primary or fallback.
 */
function mlab_smtp_route_fingerprint( string $route ): string {
	return hash_hmac( 'sha256', wp_json_encode( mlab_smtp_route_inputs( $route ) ), wp_salt( 'auth' ) );
}

/**
 * Non-reversible fingerprint of the connection of a route (provider, host, user, account/API key). It does not contain
 * the sender, so a changed sender address keeps it. An attempt with an unclear result is bound to it: another
 * account does not share the idempotency context of the first one. Salted HMAC; nothing readable is stored.
 *
 * @param string $route    primary or fallback.
 * @param string $provider Provider of the route.
 * @return string 64 hex characters.
 */
function mlab_smtp_route_connection_hash( string $route, string $provider ): string {
	$route  = mlab_smtp_route_name( $route );
	$values = array();
	foreach ( mlab_smtp_route_connection_keys( $route, $provider ) as $key ) {
		if ( ! str_contains( $key, 'webhook' ) ) { // The webhook secret does not decide where mail is delivered.
			$values[ $key ] = (string) mlab_smtp_get( $key );
		}
	}
	ksort( $values );
	return hash_hmac( 'sha256', wp_json_encode( array( $route, $provider, $values ) ), wp_salt( 'auth' ) );
}

/**
 * Stored results of all routes.
 *
 * @return array<string, array<string, array>>
 */
function mlab_smtp_readiness_get(): array {
	$stored = get_option( MLAB_SMTP_READINESS_OPTION, array() );
	return is_array( $stored ) ? $stored : array();
}

/**
 * Stores the result of a check or test mail with the current fingerprint.
 *
 * @param string $route primary or fallback.
 * @param string $kind  check or test.
 * @param bool   $ok    Result.
 * @param string $to    Recipient of a test mail; stored masked.
 */
function mlab_smtp_readiness_record( string $route, string $kind, bool $ok, string $to = '' ): void {
	$route = mlab_smtp_route_name( $route );
	$all   = mlab_smtp_readiness_get();
	$entry = array(
		'time'        => time(),
		'ok'          => $ok,
		'fingerprint' => mlab_smtp_route_fingerprint( $route ),
	);
	if ( 'test' === $kind ) {
		$entry['to_masked'] = '' === $to ? '' : mlab_smtp_mask_email( $to );
	}
	$all[ $route ][ 'test' === $kind ? 'test' : 'check' ] = $entry;
	update_option( MLAB_SMTP_READINESS_OPTION, $all, false );
}

/**
 * True when the route has everything it needs filled in. This is not readiness.
 *
 * @param string $route primary or fallback.
 */
function mlab_smtp_route_filled( string $route ): bool {
	return 'fallback' === mlab_smtp_route_name( $route ) ? mlab_smtp_fallback_filled() : mlab_smtp_has_credentials();
}

/**
 * State of a route: eingetragen, geprueft, getestet, veraltet, plus level, label and text for display.
 *
 * @param string $route primary or fallback.
 * @return array<string, mixed>
 */
function mlab_smtp_route_state( string $route ): array {
	$route   = mlab_smtp_route_name( $route );
	$stored  = mlab_smtp_readiness_get()[ $route ] ?? array();
	$current = mlab_smtp_route_fingerprint( $route );
	$check   = $stored['check'] ?? array();
	$test    = $stored['test'] ?? array();
	$matches = static fn( array $entry ): bool => ! empty( $entry['ok'] ) && hash_equals( (string) ( $entry['fingerprint'] ?? '' ), $current );
	$stale   = ( ! empty( $check['ok'] ) && ! $matches( $check ) ) || ( ! empty( $test['ok'] ) && ! $matches( $test ) );
	$state   = array(
		'route'       => $route,
		'provider'    => mlab_smtp_route_provider( $route ),
		'eingetragen' => mlab_smtp_route_filled( $route ),
		'geprueft'    => $matches( $check ),
		'getestet'    => $matches( $test ),
		'veraltet'    => $stale,
		'checked_at'  => (int) ( $stored['check']['time'] ?? 0 ),
		'tested_at'   => (int) ( $stored['test']['time'] ?? 0 ),
		'tested_to'   => (string) ( $stored['test']['to_masked'] ?? '' ),
	);
	return array_merge( $state, mlab_smtp_route_state_summary( $state ) );
}

/**
 * Level, label and text of a route state.
 *
 * @param array $state Flags of mlab_smtp_route_state().
 * @return array{level:string, label:string, text:string}
 */
function mlab_smtp_route_state_summary( array $state ): array {
	$name = 'fallback' === $state['route'] ? __( 'Der Ersatzweg', 'mlab-smtp' ) : __( 'Der Hauptweg', 'mlab-smtp' );
	if ( ! $state['eingetragen'] ) {
		/* translators: %s: Der Ersatzweg or Der Hauptweg */
		return mlab_smtp_summary( 'info', __( 'Nicht eingetragen', 'mlab-smtp' ), sprintf( __( '%s ist noch nicht vollständig eingetragen.', 'mlab-smtp' ), $name ) );
	}
	if ( $state['veraltet'] ) {
		/* translators: %s: Der Ersatzweg or Der Hauptweg */
		return mlab_smtp_summary( 'warn', __( 'Erneute Prüfung nötig', 'mlab-smtp' ), sprintf( __( 'Seit der letzten Prüfung hat sich etwas geändert (Zugangsdaten, Absender oder Domain). %s muss erneut geprüft und getestet werden.', 'mlab-smtp' ), $name ) );
	}
	if ( $state['getestet'] ) {
		/* translators: %s: Der Ersatzweg or Der Hauptweg */
		return mlab_smtp_summary( 'ok', __( 'Getestet', 'mlab-smtp' ), sprintf( __( '%s hat eine Testmail erfolgreich angenommen.', 'mlab-smtp' ), $name ) );
	}
	if ( $state['geprueft'] ) {
		/* translators: %s: Der Ersatzweg or Der Hauptweg */
		return mlab_smtp_summary( 'warn', __( 'Geprüft, nicht getestet', 'mlab-smtp' ), sprintf( __( '%s ist erreichbar, aber es wurde noch keine Testmail erfolgreich angenommen.', 'mlab-smtp' ), $name ) );
	}
	/* translators: %s: Der Ersatzweg or Der Hauptweg */
	return mlab_smtp_summary( 'warn', __( 'Eingetragen, nicht geprüft', 'mlab-smtp' ), sprintf( __( '%s ist eingetragen, aber noch nicht geprüft. Eingetragen heisst nicht, dass er funktioniert.', 'mlab-smtp' ), $name ) );
}

/**
 * Small helper that builds the summary array.
 *
 * @param string $level ok, warn or info.
 * @param string $label Short label.
 * @param string $text  Explanation.
 * @return array{level:string, label:string, text:string}
 */
function mlab_smtp_summary( string $level, string $label, string $text ): array {
	return array(
		'level' => $level,
		'label' => $label,
		'text'  => $text,
	);
}

/**
 * Answer to «Ist der Ersatzweg tatsächlich getestet?» for the overview.
 *
 * @return array{tested:bool, enabled:bool, level:string, question:string, label:string, text:string}
 */
function mlab_smtp_fallback_tested_answer(): array {
	$state   = mlab_smtp_route_state( 'fallback' );
	$enabled = (bool) mlab_smtp_get( 'fallback_enabled' );
	$tested  = $state['getestet'] && ! $state['veraltet'];
	$answer  = array(
		'tested'   => $tested,
		'enabled'  => $enabled,
		'level'    => $state['level'],
		'question' => __( 'Ist der Ersatzweg tatsächlich getestet?', 'mlab-smtp' ),
		'label'    => $state['label'],
		'text'     => $state['text'],
	);
	if ( $tested && ! $enabled ) {
		$answer['level'] = 'warn';
		$answer['text']  = __( 'Der Ersatzweg ist getestet, aber noch nicht eingeschaltet. Fällt der Hauptweg aus, springt er nicht ein.', 'mlab-smtp' );
	}
	return $answer;
}
