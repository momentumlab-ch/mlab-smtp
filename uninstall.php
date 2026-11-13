<?php
/**
 * Räumt beim Löschen des Plugins alle gespeicherten Daten auf, bei Multisite auf jeder Unterseite.
 *
 * @package mlab-smtp
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Names of the per-user transients (flash message, wizard flags and draft).
 *
 * @return string[]
 */
function mlab_smtp_uninstall_user_transients(): array {
	$names = array();
	foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
		foreach ( array( 'flash', 'verified', 'tested', 'draft' ) as $name ) {
			$names[] = 'mlab_smtp_' . $name . '_' . (int) $user_id;
		}
	}
	return $names;
}

/**
 * Removes the data of the current site.
 */
function mlab_smtp_uninstall_site(): void {
	global $wpdb;

	// Delete the known transients through the API, so a persistent object cache is cleared as well.
	$transients = array( 'mlab_smtp_notify_throttle' );
	foreach ( (array) get_option( 'mlab_smtp_token_keys', array() ) as $key ) {
		$transients[] = (string) $key;
	}
	foreach ( array_merge( $transients, mlab_smtp_uninstall_user_transients() ) as $transient ) {
		delete_transient( $transient );
	}
	foreach ( array( 'mlab_smtp_token_keys', 'mlab_smtp_options', 'mlab_smtp_log', 'mlab_smtp_state', 'mlab_smtp_db_version' ) as $option ) {
		delete_option( $option );
	}
	wp_clear_scheduled_hook( 'mlab_smtp_daily' );
	// Remaining transients by prefix (DNS and detection cache, OAuth states, tokens of older versions).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of prefixed transients.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_mlab\_smtp\_%' OR option_name LIKE '\_transient\_timeout\_mlab\_smtp\_%'" );
}

mlab_smtp_uninstall_site();

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $mlab_smtp_blog_id ) {
		if ( get_current_blog_id() === (int) $mlab_smtp_blog_id ) {
			continue;
		}
		switch_to_blog( (int) $mlab_smtp_blog_id );
		mlab_smtp_uninstall_site();
		restore_current_blog();
	}
}

delete_metadata( 'user', 0, 'mlab_smtp_dismissed', '', true );
