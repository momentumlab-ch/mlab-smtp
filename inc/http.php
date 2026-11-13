<?php
/**
 * HTTP-Helfer für die OAuth-Anbieter (Token holen, Antworten auswerten).
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads a JSON response. HTTP errors and transport errors become a WP_Error with a readable message.
 *
 * @param array|WP_Error $response Result of wp_remote_*().
 * @return array|WP_Error Decoded body (may be empty) or error.
 */
function mlab_smtp_decode_response( $response ) {
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	$data = is_array( $data ) ? $data : array();
	if ( $code >= 400 ) {
		return new WP_Error(
			'mlab_smtp_http',
			/* translators: 1: HTTP status code, 2: error message of the provider */
			sprintf( __( 'Der Anbieter hat mit Fehler %1$d geantwortet: %2$s', 'mlab-smtp' ), $code, mlab_smtp_error_message( $data ) )
		);
	}
	return $data;
}

/**
 * Pulls the error text out of a Google or Microsoft error body.
 *
 * @param array $data Decoded error body.
 */
function mlab_smtp_error_message( array $data ): string {
	$error = $data['error'] ?? '';
	if ( is_array( $error ) ) {
		return (string) ( $error['message'] ?? '' );
	}
	$description = (string) ( $data['error_description'] ?? '' );
	// Keep the error code (for example invalid_grant): mlab_smtp_explain_error() relies on it.
	return '' !== $description && '' !== $error ? $error . ': ' . $description : $description . $error;
}

/**
 * Requests an OAuth token.
 *
 * @param string $url  Token endpoint.
 * @param array  $body Form fields.
 * @return array|WP_Error Token response or error.
 */
function mlab_smtp_request_token( string $url, array $body ) {
	$data = mlab_smtp_decode_response(
		wp_remote_post(
			$url,
			array(
				'timeout' => MLAB_SMTP_TIMEOUT,
				'body'    => $body,
			)
		)
	);
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	if ( empty( $data['access_token'] ) ) {
		return new WP_Error( 'mlab_smtp_token', __( 'Die Antwort des Anbieters enthält kein Zugriffstoken.', 'mlab-smtp' ) );
	}
	return $data;
}

/**
 * Access token from the cache, or a fresh one. The cached token is stored encrypted.
 *
 * @param string $key  Transient name.
 * @param string $url  Token endpoint.
 * @param array  $body Form fields.
 * @param bool   $fresh True to ignore and replace the cached token.
 * @return string|WP_Error Access token or error.
 */
function mlab_smtp_cached_token( string $key, string $url, array $body, bool $fresh = false ) {
	if ( $fresh ) {
		delete_transient( $key );
	}
	$cached = mlab_smtp_decrypt( (string) get_transient( $key ) );
	if ( '' !== $cached ) {
		return $cached;
	}
	$data = mlab_smtp_request_token( $url, $body );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$ttl = max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 120 );
	set_transient( $key, mlab_smtp_encrypt( $data['access_token'] ), $ttl );
	mlab_smtp_remember_token_key( $key );
	return $data['access_token'];
}

/**
 * Name of the token transient. It contains a short hash of the credentials, so a changed constant in
 * wp-config.php or a new secret never meets a token of the old connection.
 *
 * @param string   $provider m365 or google.
 * @param string[] $parts    Credential values the token depends on.
 */
function mlab_smtp_token_key( string $provider, array $parts ): string {
	return 'mlab_smtp_' . $provider . '_token_' . substr( hash_hmac( 'sha256', implode( "\n", $parts ), wp_salt( 'auth' ) ), 0, 12 );
}

/**
 * Remembers a token transient name, so it can be deleted later even though its name depends on the credentials
 * (and a persistent object cache keeps transients out of the options table).
 *
 * @param string $key Transient name.
 */
function mlab_smtp_remember_token_key( string $key ): void {
	$keys = (array) get_option( 'mlab_smtp_token_keys', array() );
	if ( ! in_array( $key, $keys, true ) ) {
		$keys[] = $key;
		update_option( 'mlab_smtp_token_keys', array_slice( $keys, -10 ), false );
	}
}

/**
 * Drops all cached access tokens, whatever credentials they were made for.
 */
function mlab_smtp_clear_tokens(): void {
	foreach ( (array) get_option( 'mlab_smtp_token_keys', array() ) as $key ) {
		delete_transient( (string) $key );
	}
	delete_option( 'mlab_smtp_token_keys' );
}

/**
 * Posts to a provider API with a bearer token. On 401 the cached token is dropped and the call is repeated once.
 *
 * @param string $provider     m365 or google.
 * @param string $url          API endpoint.
 * @param string $body         Request body.
 * @param string $content_type Content type of the body.
 * @return array|WP_Error Decoded response (may be empty) or error.
 */
function mlab_smtp_api_post( string $provider, string $url, string $body, string $content_type ) {
	for ( $attempt = 0; $attempt < 2; $attempt++ ) {
		$token = 'm365' === $provider ? mlab_smtp_m365_token( 0 < $attempt ) : mlab_smtp_google_token( 0 < $attempt );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => MLAB_SMTP_TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => $content_type,
				),
				'body'    => $body,
			)
		);
		if ( 401 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			break;
		}
	}
	return mlab_smtp_decode_response( $response );
}

/**
 * Error for a message that is larger than the provider accepts.
 *
 * @param string $body  Request body that would be sent.
 * @param int    $limit Largest accepted size in bytes.
 * @return WP_Error|null Error when too large.
 */
function mlab_smtp_size_error( string $body, int $limit ): ?WP_Error {
	if ( strlen( $body ) <= $limit ) {
		return null;
	}
	return new WP_Error(
		'mlab_smtp_size',
		sprintf(
			/* translators: %s: size limit in megabytes */
			__( 'Die Nachricht ist zu gross für den Anbieter (Grenze: %s MB). Bitte Anhänge verkleinern oder den Ersatzweg aktivieren.', 'mlab-smtp' ),
			number_format_i18n( $limit / MB_IN_BYTES, 0 )
		)
	);
}
