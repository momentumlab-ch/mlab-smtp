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
	return '' !== $description ? $description : (string) $error;
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
 * @return string|WP_Error Access token or error.
 */
function mlab_smtp_cached_token( string $key, string $url, array $body ) {
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
	return $data['access_token'];
}
