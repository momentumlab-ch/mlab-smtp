<?php
/**
 * Client of the existing MomentumLab license-server catalog contract.
 * License checks are admin-only; delivery never reads a remote entitlement.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks the configured HTTPS server. License keys travel in headers, never URLs.
 * A successful catalog response is authoritative; outages retain a previous grant for seven days.
 *
 * @return true|WP_Error
 */
function mlab_smtp_license_refresh() {
	$url = (string) mlab_smtp_get( 'license_url' );
	$key = (string) mlab_smtp_get( 'license_key' );
	if ( '' === $url || '' === $key || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
		return new WP_Error( 'license_config', __( 'Bitte HTTPS-Adresse des Lizenzservers und Lizenzschlüssel eintragen.', 'mlab-smtp' ) );
	}
	$response = wp_safe_remote_get(
		rtrim( $url, '/' ) . '/wp-json/mlab-ls/v1/catalog',
		array(
			'timeout'             => 10,
			'limit_response_size' => 262144,
			'redirection'         => 0,
			'headers'             => array(
				'X-MLAB-License' => $key,
				'X-MLAB-Site'    => home_url(),
			),
		)
	);
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'license_offline', __( 'Lizenzserver derzeit nicht erreichbar. Der Mailversand läuft unverändert weiter.', 'mlab-smtp' ) );
	}
	$code = wp_remote_retrieve_response_code( $response );
	if ( in_array( $code, array( 401, 403 ), true ) ) {
		delete_option( 'mlab_smtp_license_grant' );
		return new WP_Error( 'license_denied', __( 'Lizenz oder Website nicht freigeschaltet. Bitte im Lizenzserver prüfen.', 'mlab-smtp' ) );
	}
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( 200 !== $code || ! is_array( $body ) || ! isset( $body['products'] ) || ! is_array( $body['products'] ) ) {
		return new WP_Error( 'license_response', __( 'Lizenzprüfung momentan nicht möglich. Bitte später erneut prüfen.', 'mlab-smtp' ) );
	}
	$enabled = false;
	foreach ( $body['products'] as $product ) {
		if ( is_array( $product ) && 'mlab-smtp-pro' === ( $product['slug'] ?? '' ) && true === ( $product['unlocked'] ?? false ) ) {
			$enabled = true;
		}
	}
	update_option(
		'mlab_smtp_license_grant',
		array(
			'enabled'    => $enabled,
			'checked_at' => time(),
			'binding'    => mlab_smtp_license_binding(),
		),
		false
	);
	return true;
}

/**
 * Binds a cached grant to the exact server, key and website without storing credentials in it.
 */
function mlab_smtp_license_binding(): string {
	return hash_hmac( 'sha256', mlab_smtp_get( 'license_url' ) . '|' . mlab_smtp_get( 'license_key' ) . '|' . home_url(), wp_salt( 'auth' ) );
}

/**
 * Local entitlement only; no network calls or restrictions on basic mail delivery.
 */
function mlab_smtp_has_pro(): bool {
	$grant = (array) get_option( 'mlab_smtp_license_grant', array() );
	return ! empty( $grant['enabled'] ) && hash_equals( mlab_smtp_license_binding(), (string) ( $grant['binding'] ?? '' ) ) && (int) ( $grant['checked_at'] ?? 0 ) > time() - 7 * DAY_IN_SECONDS;
}
