<?php
/**
 * Räumt beim Löschen des Plugins alle gespeicherten Daten auf.
 *
 * @package mlab-smtp
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'mlab_smtp_options' );
delete_option( 'mlab_smtp_log' );
delete_transient( 'mlab_smtp_m365_token' );
delete_transient( 'mlab_smtp_google_token' );
