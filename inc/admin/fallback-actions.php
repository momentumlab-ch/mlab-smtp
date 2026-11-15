<?php
/**
 * Explicit backup-route lifecycle actions. Disabling preserves credentials; removal clears only backup data.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_mlab_smtp_fallback_disable', 'mlab_smtp_handle_fallback_disable' );
add_action( 'admin_post_mlab_smtp_fallback_remove', 'mlab_smtp_handle_fallback_remove' );

/**
 * Changes the backup route without touching the primary route or delivery history.
 *
 * @param bool $remove Remove credentials and readiness too, rather than only disable the route.
 * @return true|WP_Error
 */
function mlab_smtp_manage_fallback( bool $remove ) {
	$stored = (array) get_option( MLAB_SMTP_OPTION, array() );
	$keys   = array_values( array_filter( array_unique( array_merge( array_keys( mlab_smtp_defaults() ), array_keys( $stored ) ) ), static fn( $key ) => 'fallback_enabled' === $key || str_starts_with( (string) $key, 'fb_' ) ) );
	foreach ( $remove ? $keys : array( 'fallback_enabled' ) as $key ) {
		if ( mlab_smtp_is_locked( $key ) ) {
			return new WP_Error( 'locked', __( 'Der Ersatzweg ist in wp-config.php festgelegt. Ändern Sie zuerst die entsprechenden Konstanten.', 'mlab-smtp' ) );
		}
	}
	if ( $remove ) {
		foreach ( $keys as $key ) {
			unset( $stored[ $key ] );
		}
	} else {
		$stored['fallback_enabled'] = 0;
	}
	update_option( MLAB_SMTP_OPTION, $stored, false );
	$actual = (array) get_option( MLAB_SMTP_OPTION, array() );
	$failed = $remove ? (bool) array_intersect( $keys, array_keys( $actual ) ) : ! empty( $actual['fallback_enabled'] );
	if ( ! $failed && $remove ) {
		$readiness = mlab_smtp_readiness_get();
		unset( $readiness['fallback'] );
		update_option( MLAB_SMTP_READINESS_OPTION, $readiness, false );
		$failed = isset( mlab_smtp_readiness_get()['fallback'] );
	}
	if ( $failed ) {
		return new WP_Error( 'save_failed', __( 'Der Ersatzweg konnte nicht vollständig geändert werden. Bitte versuchen Sie es erneut.', 'mlab-smtp' ) );
	}
	do_action( 'mlab_smtp_settings_changed', $remove ? $keys : array( 'fallback_enabled' ), get_current_user_id() );
	return true;
}

/**
 * Disables the backup route, retaining its configuration.
 */
function mlab_smtp_handle_fallback_disable(): void {
	mlab_smtp_guard( 'mlab_smtp_fallback_disable' );
	mlab_smtp_finish_fallback_action( false );
}

/**
 * Removes the backup configuration after the explicit confirmation button.
 */
function mlab_smtp_handle_fallback_remove(): void {
	mlab_smtp_guard( 'mlab_smtp_fallback_remove' );
	mlab_smtp_finish_fallback_action( true );
}

/**
 * Shared result and redirect for the authorised backup actions.
 *
 * @param bool $remove Whether to remove the configuration.
 */
function mlab_smtp_finish_fallback_action( bool $remove ): void {
	$result = mlab_smtp_manage_fallback( $remove );
	if ( is_wp_error( $result ) ) {
		mlab_smtp_flash( 'error', $result->get_error_message() );
	} else {
		mlab_smtp_flash( 'success', $remove ? __( 'Der Ersatzweg wurde entfernt. Hauptversand und Protokolle bleiben erhalten.', 'mlab-smtp' ) : __( 'Der Ersatzweg ist deaktiviert. Seine Konfiguration bleibt gespeichert.', 'mlab-smtp' ) );
	}
	wp_safe_redirect( mlab_smtp_page_url( array( 'view' => 'settings', 'feedback' => 'fallback' ) ) . '#mlab-s-fallback' );
	exit;
}

/**
 * Shows immediate disable and deliberately confirmed removal actions inside the settings card.
 */
function mlab_smtp_render_fallback_actions(): void {
	$stored     = mlab_smtp_get_all();
	$configured = mlab_smtp_route_filled( 'fallback' ) || ! empty( mlab_smtp_readiness_get()['fallback'] );
	foreach ( $stored as $key => $value ) {
		if ( ( str_starts_with( (string) $key, 'fb_' ) && str_ends_with( (string) $key, '_host' ) && '' !== (string) $value ) || ( in_array( $key, array( 'fb_smtp_user', 'fb_smtp_pass', 'fb_resend_api_key', 'fb_resend_webhook_secret' ), true ) && '' !== (string) $value ) ) {
			$configured = true;
		}
	}
	if ( mlab_smtp_get( 'fallback_enabled' ) ) {
		echo '<p>';
		mlab_smtp_set_action_button( 'fallback-disable', __( 'Ersatzweg deaktivieren', 'mlab-smtp' ) );
		echo '</p>';
		$configured = true;
	}
	if ( $configured ) {
		echo '<details class="mlab-technical"><summary>' . esc_html__( 'Ersatzweg entfernen', 'mlab-smtp' ) . '</summary><p>' . esc_html__( 'Entfernt Server, Zugangsdaten und Prüfstatus des Ersatzwegs. Hauptversand und Protokolle bleiben erhalten.', 'mlab-smtp' ) . '</p>';
		mlab_smtp_set_action_button( 'fallback-remove', __( 'Ersatzweg endgültig entfernen', 'mlab-smtp' ) );
		echo '</details>';
	}
}
