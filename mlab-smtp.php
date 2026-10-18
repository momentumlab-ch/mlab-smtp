<?php
/**
 * Plugin Name: MomentumLab SMTP
 * Description: Versendet WordPress-E-Mails über einen eigenen Mailserver (SMTP), Microsoft 365 oder Google.
 * Version: 1.0.1
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: MomentumLab
 * Author URI: https://momentumlab.ch
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mlab-smtp
 * Domain Path: /languages
 * Update URI: https://github.com/momentumlab-ch/mlab-smtp/
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

define( 'MLAB_SMTP_VERSION', '1.0.1' );
define( 'MLAB_SMTP_FILE', __FILE__ );
define( 'MLAB_SMTP_DIR', plugin_dir_path( __FILE__ ) );
define( 'MLAB_SMTP_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'options', 'crypto', 'http', 'm365', 'google', 'mailer', 'log', 'admin', 'updater' ) as $mlab_smtp_module ) {
	require_once MLAB_SMTP_DIR . 'inc/' . $mlab_smtp_module . '.php';
}
unset( $mlab_smtp_module );

add_action( 'init', 'mlab_smtp_load_textdomain' );

/**
 * Loads the plugin translations.
 */
function mlab_smtp_load_textdomain(): void {
	load_plugin_textdomain( 'mlab-smtp', false, dirname( plugin_basename( MLAB_SMTP_FILE ) ) . '/languages' );
}
