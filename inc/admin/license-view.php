<?php
/**
 * Opt-in license connection, separate from mail configuration.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_mlab_smtp_license', 'mlab_smtp_handle_license' );

/**
 * License form. Nothing is sent to a license server before a key and URL are saved explicitly.
 */
function mlab_smtp_render_license(): void {
	$grant = (array) get_option( 'mlab_smtp_license_grant', array() );
	if ( '' !== (string) mlab_smtp_get( 'license_key' ) && (int) ( $grant['checked_at'] ?? 0 ) < time() - DAY_IN_SECONDS && ! get_transient( 'mlab_smtp_license_retry' ) ) {
		set_transient( 'mlab_smtp_license_retry', 1, HOUR_IN_SECONDS );
		mlab_smtp_license_refresh();
	}
	echo '<section class="mlab-card"><h2>SMTP Pro</h2><p>' . esc_html__( 'Pro ergänzt die DNS-Diagnose um technische Einträge. Absender, Ersatzversand und grundlegende DNS-Warnungen bleiben ohne Lizenz nutzbar.', 'mlab-smtp' ) . '</p>';
	mlab_smtp_ui_hint( mlab_smtp_has_pro() ? 'ok' : 'info', mlab_smtp_has_pro() ? __( 'Pro freigeschaltet', 'mlab-smtp' ) : __( 'Keine aktuelle Pro-Freischaltung', 'mlab-smtp' ), __( 'Produkt im Lizenzserver: mlab-smtp-pro. Bei Serverausfall gilt die letzte Bestätigung höchstens sieben Tage. Mailversand und Ersatzweg sind davon unabhängig.', 'mlab-smtp' ) );
	mlab_smtp_ui_form_open( 'mlab_smtp_license' );
	echo '<p>' . esc_html__( 'Mit «Lizenz prüfen» werden Lizenzschlüssel und Website-Adresse an Ihren angegebenen Lizenzserver übertragen. Tragen Sie die HTTPS-Basisadresse der WordPress-Installation ein, ohne /wp-json.', 'mlab-smtp' ) . '</p>';
	mlab_smtp_ui_field( 'license_url', __( 'Lizenzserver-Adresse', 'mlab-smtp' ), array( 'type' => 'url' ) );
	mlab_smtp_ui_field( 'license_key', __( 'Lizenzschlüssel', 'mlab-smtp' ), array( 'type' => 'password' ) );
	echo '<p><button type="submit" class="button">' . esc_html__( 'Lizenz prüfen', 'mlab-smtp' ) . '</button> <button type="submit" name="disconnect" value="1" class="button">' . esc_html__( 'Lizenzverbindung entfernen', 'mlab-smtp' ) . '</button></p>';
	mlab_smtp_ui_form_close();
	echo '</section>';
}

/**
 * Validates the server before saving or sending a key. Fixed headers, no redirects, safe WordPress HTTP.
 */
function mlab_smtp_handle_license(): void {
	mlab_smtp_guard( 'mlab_smtp_license' );
	$input = mlab_smtp_post_input();
	if ( ! empty( $input['disconnect'] ) ) {
		if ( mlab_smtp_is_locked( 'license_url' ) || mlab_smtp_is_locked( 'license_key' ) ) {
			mlab_smtp_flash( 'error', __( 'Die Lizenzverbindung ist in wp-config.php festgelegt.', 'mlab-smtp' ) );
		} else {
			mlab_smtp_store(
				array(
					'license_url' => '',
					'license_key' => '',
				)
			);
			delete_option( 'mlab_smtp_license_grant' );
		}
		mlab_smtp_back( array( 'view' => 'settings' ) );
	}
	$url = mlab_smtp_is_locked( 'license_url' ) ? (string) mlab_smtp_get( 'license_url' ) : esc_url_raw( (string) ( $input['license_url'] ?? '' ) );
	if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || ! wp_http_validate_url( $url ) || wp_parse_url( $url, PHP_URL_QUERY ) || wp_parse_url( $url, PHP_URL_FRAGMENT ) || wp_parse_url( $url, PHP_URL_USER ) ) {
		mlab_smtp_flash( 'error', __( 'Bitte eine öffentliche HTTPS-Basisadresse ohne Zugangsdaten, Query oder Fragment verwenden.', 'mlab-smtp' ) );
		mlab_smtp_back( array( 'view' => 'settings' ) );
	}
	$input['license_url'] = untrailingslashit( $url );
	$errors               = mlab_smtp_save_fields( $input, array( 'license_url', 'license_key' ) );
	$result               = $errors ? new WP_Error( 'license_input', implode( ' ', $errors ) ) : mlab_smtp_license_refresh();
	mlab_smtp_flash( is_wp_error( $result ) ? 'warning' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'Lizenzprüfung abgeschlossen.', 'mlab-smtp' ) );
	mlab_smtp_back( array( 'view' => 'settings' ) );
}
