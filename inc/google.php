<?php
/**
 * Google: Versand über die Gmail-API. Die Verbindung läuft einmalig per OAuth im Backend.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_GOOGLE_SCOPE = 'https://www.googleapis.com/auth/gmail.send openid email';

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
 * URL that starts the connection (admin-post, protected by a nonce).
 */
function mlab_smtp_google_connect_url(): string {
	return wp_nonce_url( admin_url( 'admin-post.php?action=mlab_smtp_google_connect' ), 'mlab_smtp_google_connect' );
}

/**
 * URL that removes the connection (admin-post, protected by a nonce).
 */
function mlab_smtp_google_disconnect_url(): string {
	return wp_nonce_url( admin_url( 'admin-post.php?action=mlab_smtp_google_disconnect' ), 'mlab_smtp_google_disconnect' );
}

/**
 * Sends the admin back into the setup wizard (or the settings), with a message for the next page view.
 *
 * @param string $type    success or error.
 * @param string $message Message text.
 */
function mlab_smtp_google_return( string $type, string $message ): void {
	mlab_smtp_flash( $type, $message );
	wp_safe_redirect(
		mlab_smtp_page_url(
			array(
				'view'     => 'wizard',
				'step'     => 2,
				'provider' => 'google',
			)
		)
	);
	exit;
}

/**
 * Creates a one-time state token bound to the current user, valid for 10 minutes, plus the PKCE verifier.
 *
 * @return array{state:string, challenge:string}
 */
function mlab_smtp_google_new_state(): array {
	$state    = wp_generate_password( 40, false );
	$verifier = wp_generate_password( 64, false );
	set_transient(
		'mlab_smtp_gstate_' . hash( 'sha256', $state ),
		array(
			'user'     => get_current_user_id(),
			'verifier' => $verifier,
		),
		10 * MINUTE_IN_SECONDS
	);
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE requires the base64url encoded SHA-256 hash.
	$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	return array(
		'state'     => $state,
		'challenge' => $challenge,
	);
}

/**
 * Checks a state token and uses it up.
 *
 * @param string $state State parameter of the callback.
 * @return string|null PKCE verifier, or null when the token is unknown, expired, used or belongs to another user.
 */
function mlab_smtp_google_use_state( string $state ): ?string {
	if ( '' === $state ) {
		return null;
	}
	$key  = 'mlab_smtp_gstate_' . hash( 'sha256', $state );
	$data = get_transient( $key );
	delete_transient( $key );
	if ( ! is_array( $data ) || (int) ( $data['user'] ?? 0 ) !== get_current_user_id() ) {
		return null;
	}
	return (string) $data['verifier'];
}

/**
 * Sends the admin to Google's consent screen.
 */
function mlab_smtp_google_connect(): void {
	mlab_smtp_guard( 'mlab_smtp_google_connect' );
	if ( '' === mlab_smtp_get( 'google_client_id' ) || '' === mlab_smtp_get( 'google_client_secret' ) ) {
		mlab_smtp_google_return( 'error', __( 'Client-ID oder Client-Secret fehlt. Bitte beide eintragen, speichern und die Verbindung dann erneut starten.', 'mlab-smtp' ) );
	}
	$state = mlab_smtp_google_new_state();
	$url   = add_query_arg(
		array(
			'client_id'             => mlab_smtp_get( 'google_client_id' ),
			'redirect_uri'          => mlab_smtp_google_redirect_uri(),
			'response_type'         => 'code',
			'scope'                 => MLAB_SMTP_GOOGLE_SCOPE,
			'access_type'           => 'offline',
			'prompt'                => 'consent',
			'state'                 => $state['state'],
			'code_challenge'        => $state['challenge'],
			'code_challenge_method' => 'S256',
		),
		'https://accounts.google.com/o/oauth2/v2/auth'
	);
	// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Google is an external host by design; the URL is built above.
	wp_redirect( $url );
	exit;
}

/**
 * Receives Google's answer and stores the refresh token and the account.
 * The one-time state token takes the place of a nonce: it is bound to the user and can be used once.
 */
function mlab_smtp_google_callback(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'mlab-smtp' ), '', array( 'response' => 403 ) );
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The state parameter is a one-time token, verified below.
	$verifier = mlab_smtp_google_use_state( sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) ) );
	$code     = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
	// phpcs:enable
	if ( null === $verifier ) {
		wp_die( esc_html__( 'Der Link ist abgelaufen oder wurde schon verwendet. Bitte die Verbindung mit Google erneut starten.', 'mlab-smtp' ), '', array( 'response' => 403 ) );
	}
	$result = '' === $code
		? new WP_Error( 'mlab_smtp_denied', __( 'Google hat die Verbindung nicht bestätigt. Bitte erneut starten und den Zugriff erlauben.', 'mlab-smtp' ) )
		: mlab_smtp_google_exchange_code( $code, $verifier );
	if ( is_wp_error( $result ) ) {
		mlab_smtp_google_return( 'error', $result->get_error_message() );
	}
	mlab_smtp_store(
		array(
			'google_refresh_token' => $result['refresh_token'],
			'google_account'       => $result['account'],
		)
	);
	mlab_smtp_google_return( 'success', __( 'Mit Google verbunden.', 'mlab-smtp' ) );
}

/**
 * Trades the authorization code for a refresh token.
 *
 * @param string $code     Authorization code from Google.
 * @param string $verifier PKCE verifier belonging to the state.
 * @return array{refresh_token:string, account:string}|WP_Error
 */
function mlab_smtp_google_exchange_code( string $code, string $verifier ) {
	$data = mlab_smtp_request_token(
		'https://oauth2.googleapis.com/token',
		array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'code_verifier' => $verifier,
			'client_id'     => (string) mlab_smtp_get( 'google_client_id' ),
			'client_secret' => (string) mlab_smtp_get( 'google_client_secret' ),
			'redirect_uri'  => mlab_smtp_google_redirect_uri(),
		)
	);
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	if ( empty( $data['refresh_token'] ) ) {
		return new WP_Error( 'mlab_smtp_no_refresh', __( 'Google hat keine dauerhafte Freigabe erteilt. Bitte den Zugriff unter myaccount.google.com/permissions entfernen und die Verbindung erneut starten.', 'mlab-smtp' ) );
	}
	return array(
		'refresh_token' => (string) $data['refresh_token'],
		'account'       => mlab_smtp_google_account_from_token( (string) ( $data['id_token'] ?? '' ) ),
	);
}

/**
 * Reads the e-mail address out of a Google id_token. The signature is not checked: the token comes
 * straight from Google's token endpoint over TLS and is only used for display.
 *
 * @param string $id_token JWT from the token response.
 * @return string E-mail address, empty when not available.
 */
function mlab_smtp_google_account_from_token( string $id_token ): string {
	$parts = explode( '.', $id_token );
	if ( 3 !== count( $parts ) ) {
		return '';
	}
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWT payload is base64url JSON, not obfuscation.
	$payload = json_decode( (string) base64_decode( strtr( $parts[1], '-_', '+/' ), true ), true );
	return is_array( $payload ) && ! empty( $payload['email'] ) ? sanitize_email( (string) $payload['email'] ) : '';
}

/**
 * Removes the stored connection.
 */
function mlab_smtp_google_disconnect(): void {
	mlab_smtp_guard( 'mlab_smtp_google_disconnect' );
	mlab_smtp_store(
		array(
			'google_refresh_token' => '',
			'google_account'       => '',
		)
	);
	mlab_smtp_google_return( 'success', __( 'Die Verbindung zu Google wurde getrennt.', 'mlab-smtp' ) );
}

/**
 * Access token for the Gmail API, refreshed from the stored refresh token.
 * An invalid_grant answer (revoked, or the app is still in test mode) keeps the refresh token; the error
 * surfaces through the normal failure path and mlab_smtp_explain_error().
 *
 * @param bool $fresh True to bypass the cache (connection check, retry after 401).
 * @return string|WP_Error
 */
function mlab_smtp_google_token( bool $fresh = false ) {
	$refresh = (string) mlab_smtp_get( 'google_refresh_token' );
	if ( '' === $refresh ) {
		return new WP_Error( 'mlab_smtp_google_unlinked', __( 'Google ist noch nicht verbunden. Bitte unter «Einstellungen» die Verbindung mit Google herstellen.', 'mlab-smtp' ) );
	}
	return mlab_smtp_cached_token(
		'mlab_smtp_google_token',
		'https://oauth2.googleapis.com/token',
		array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh,
			'client_id'     => (string) mlab_smtp_get( 'google_client_id' ),
			'client_secret' => (string) mlab_smtp_get( 'google_client_secret' ),
		),
		$fresh
	);
}

/**
 * Sends a finished MIME message through the connected Google account.
 *
 * @param string $mime Complete MIME message.
 * @return true|WP_Error
 */
function mlab_smtp_send_google( string $mime ) {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Gmail requires the MIME message base64url encoded.
	$raw    = rtrim( strtr( base64_encode( $mime ), '+/', '-_' ), '=' );
	$result = mlab_smtp_api_post( 'google', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send', (string) wp_json_encode( array( 'raw' => $raw ) ), 'application/json' );
	return is_wp_error( $result ) ? $result : true;
}
