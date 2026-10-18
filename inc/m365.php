<?php
/**
 * Microsoft 365: Versand über die Graph-API (App-Berechtigung Mail.Send, ohne Benutzer-Login).
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Access token for Microsoft Graph (client credentials).
 *
 * @return string|WP_Error
 */
function mlab_smtp_m365_token() {
	$tenant = rawurlencode( (string) mlab_smtp_get( 'm365_tenant' ) );
	return mlab_smtp_cached_token(
		'mlab_smtp_m365_token',
		'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token',
		array(
			'grant_type'    => 'client_credentials',
			'client_id'     => (string) mlab_smtp_get( 'm365_client_id' ),
			'client_secret' => (string) mlab_smtp_get( 'm365_client_secret' ),
			'scope'         => 'https://graph.microsoft.com/.default',
		)
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
	$token = mlab_smtp_m365_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$response = wp_remote_post(
		'https://graph.microsoft.com/v1.0/users/' . rawurlencode( $from ) . '/sendMail',
		array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'text/plain',
			),
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Graph requires the MIME message base64 encoded.
			'body'    => base64_encode( $mime ),
		)
	);
	$result = mlab_smtp_decode_response( $response );
	return is_wp_error( $result ) ? $result : true;
}
