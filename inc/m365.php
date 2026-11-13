<?php
/**
 * Microsoft 365: Versand über die Graph-API (App-Berechtigung Mail.Send, ohne Benutzer-Login).
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

// Graph accepts at most 4 MB per request; base64 adds a third on top of the MIME text.
const MLAB_SMTP_M365_LIMIT = 4 * MB_IN_BYTES;

/**
 * Access token for Microsoft Graph (client credentials).
 *
 * @param bool $fresh True to bypass the cache (connection check, retry after 401).
 * @return string|WP_Error
 */
function mlab_smtp_m365_token( bool $fresh = false ) {
	$tenant = rawurlencode( (string) mlab_smtp_get( 'm365_tenant' ) );
	$key    = mlab_smtp_token_key( 'm365', array( (string) mlab_smtp_get( 'm365_tenant' ), (string) mlab_smtp_get( 'm365_client_id' ), (string) mlab_smtp_get( 'm365_client_secret' ) ) );
	return mlab_smtp_cached_token(
		$key,
		'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token',
		array(
			'grant_type'    => 'client_credentials',
			'client_id'     => (string) mlab_smtp_get( 'm365_client_id' ),
			'client_secret' => (string) mlab_smtp_get( 'm365_client_secret' ),
			'scope'         => 'https://graph.microsoft.com/.default',
		),
		$fresh
	);
}

/**
 * Sends a finished MIME message from the given mailbox.
 *
 * @param string $mime Complete MIME message.
 * @param string $from Mailbox the message is sent from.
 * @return true|WP_Error
 */
function mlab_smtp_send_m365( string $mime, string $from ) {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Graph requires the MIME message base64 encoded.
	$body  = base64_encode( $mime );
	$error = mlab_smtp_size_error( $body, MLAB_SMTP_M365_LIMIT );
	if ( $error ) {
		return $error;
	}
	$result = mlab_smtp_api_post( 'm365', 'https://graph.microsoft.com/v1.0/users/' . rawurlencode( $from ) . '/sendMail', $body, 'text/plain' );
	return is_wp_error( $result ) ? $result : true;
}
