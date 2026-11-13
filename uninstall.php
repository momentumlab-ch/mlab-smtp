<?php
/**
 * Räumt beim Löschen des Plugins alle gespeicherten Daten auf, bei Multisite auf jeder Unterseite.
 *
 * @package mlab-smtp
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes the data of the current site.
 */
function mlab_smtp_uninstall_site(): void {
	global $wpdb;

	foreach ( array( 'mlab_smtp_options', 'mlab_smtp_log', 'mlab_smtp_state', 'mlab_smtp_db_version' ) as $option ) {
		delete_option( $option );
	}
	wp_clear_scheduled_hook( 'mlab_smtp_daily' );
	// Transients: tokens, DNS and detection cache, notification throttle, flash messages, OAuth states.
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
