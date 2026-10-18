<?php
/**
 * Google: Versand über die Gmail-API. Die Verbindung läuft einmalig per OAuth im Backend.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_GOOGLE_SCOPE = 'https://www.googleapis.com/auth/gmail.send';

add_action( 'admin_post_mlab_smtp_google_connect', 'mlab_smtp_google_connect' );
add_action( 'admin_post_mlab_smtp_google_callback', 'mlab_smtp_google_callback' );
add_action( 'admin_post_mlab_smtp_google_disconnect', 'mlab_smtp_google_disconnect' );

/**
 * Redirect URI that has to be entered in the Google Cloud console.
 */
function mlab_smtp_google_redirect_uri(): string {
	return admin_url( 'admin-post.php?action=mlab_smtp_google_callback' );
}

/**
 * Sends the admin to Google's consent screen.
 */
function mlab_smtp_google_connect(): void {
	mlab_smtp_guard( 'mlab_smtp_google_connect' );
	if ( '' === mlab_smtp_get( 'google_client_id' ) || '' === mlab_smtp_get( 'google_client_secret' ) ) {
		mlab_smtp_flash( 'error', __( 'Bitte zuerst Client-ID und Client-Secret speichern.', 'mlab-smtp' ) );
		mlab_smtp_back();
	}
	$url = add_query_arg(
		array(
			'client_id'     => mlab_smtp_get( 'google_client_id' ),
			'redirect_uri'  => mlab_smtp_google_redirect_uri(),
			'response_type' => 'code',
			'scope'         => MLAB_SMTP_GOOGLE_SCOPE,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => wp_create_nonce( 'mlab_smtp_google_state' ),
		),
		'https://accounts.google.com/o/oauth2/v2/auth'
	);
	// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Google is an external host by design; the URL is built above.
	wp_redirect( $url );
	exit;
}

/**
 * Receives Google's answer and stores the refresh token.
 */
function mlab_smtp_google_callback(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'mlab-smtp' ), '', array( 'response' => 403 ) );
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The state parameter is the nonce, verified below.
	$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
	$code  = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
	// phpcs:enable
	if ( ! wp_verify_nonce( $state, 'mlab_smtp_google_state' ) ) {
		wp_die( esc_html__( 'Der Link ist abgelaufen. Bitte die Verbindung erneut starten.', 'mlab-smtp' ), '', array( 'response' => 403 ) );
	}
	$token = '' === $code ? new WP_Error( 'mlab_smtp_denied', __( 'Google hat die Verbindung nicht bestätigt.', 'mlab-smtp' ) ) : mlab_smtp_google_exchange_code( $code );
	if ( is_wp_error( $token ) ) {
		mlab_smtp_flash( 'error', $token->get_error_message() );
		mlab_smtp_back();
	}
	mlab_smtp_update( 'google_refresh_token', $token );
	delete_transient( 'mlab_smtp_google_token' );
	mlab_smtp_flash( 'success', __( 'Mit Google verbunden.', 'mlab-smtp' ) );
	mlab_smtp_back();
}

/**
 * Trades the authorization code for a refresh token.
 *
 * @param string $code Authorization code from Google.
 * @return string|WP_Error Refresh token or error.
 */
function mlab_smtp_google_exchange_code( string $code ) {
	$data = mlab_smtp_request_token(
		'https://oauth2.googleapis.com/token',
		array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'client_id'     => (string) mlab_smtp_get( 'google_client_id' ),
			'client_secret' => (string) mlab_smtp_get( 'google_client_secret' ),
			'redirect_uri'  => mlab_smtp_google_redirect_uri(),
		)
	);
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	if ( empty( $data['refresh_token'] ) ) {
		return new WP_Error( 'mlab_smtp_no_refresh', __( 'Google hat keine dauerhafte Freigabe erteilt. Bitte den Zugriff unter myaccount.google.com/permissions entfernen und erneut verbinden.', 'mlab-smtp' ) );
	}
	return (string) $data['refresh_token'];
}

/**
 * Removes the stored connection.
 */
function mlab_smtp_google_disconnect(): void {
	mlab_smtp_guard( 'mlab_smtp_google_disconnect' );
	mlab_smtp_update( 'google_refresh_token', '' );
	delete_transient( 'mlab_smtp_google_token' );
	mlab_smtp_flash( 'success', __( 'Die Verbindung zu Google wurde getrennt.', 'mlab-smtp' ) );
	mlab_smtp_back();
}

/**
 * Access token for the Gmail API, refreshed from the stored refresh token.
 *
 * @return string|WP_Error
 */
function mlab_smtp_google_token() {
	$refresh = (string) mlab_smtp_get( 'google_refresh_token' );
	if ( '' === $refresh ) {
		return new WP_Error( 'mlab_smtp_google_unlinked', __( 'Google ist noch nicht verbunden.', 'mlab-smtp' ) );
	}
	return mlab_smtp_cached_token(
		'mlab_smtp_google_token',
		'https://oauth2.googleapis.com/token',
		array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh,
			'client_id'     => (string) mlab_smtp_get( 'google_client_id' ),
			'client_secret' => (string) mlab_smtp_get( 'google_client_secret' ),
		)
	);
}

/**
 * Sends a finished MIME message through the connected Google account.
 *
 * @param string $mime Complete MIME message.
 * @return true|WP_Error
 */
function mlab_smtp_send_google( string $mime ) {
	$token = mlab_smtp_google_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$response = wp_remote_post(
		'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
		array(
			'timeout' => MLAB_SMTP_TIMEOUT,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Gmail requires the MIME message base64url encoded.
			'body'    => wp_json_encode( array( 'raw' => rtrim( strtr( base64_encode( $mime ), '+/', '-_' ), '=' ) ) ),
		)
	);
	$result = mlab_smtp_decode_response( $response );
	return is_wp_error( $result ) ? $result : true;
}
