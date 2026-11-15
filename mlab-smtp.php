<?php
/**
 * Plugin Name: MomentumLab SMTP
 * Description: Versendet WordPress-E-Mails über einen eigenen Mailserver (SMTP), Microsoft 365, Google oder Resend.
 * Version: 1.4.0
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

define( 'MLAB_SMTP_VERSION', '1.4.0' );
define( 'MLAB_SMTP_FILE', __FILE__ );
define( 'MLAB_SMTP_DIR', plugin_dir_path( __FILE__ ) );
define( 'MLAB_SMTP_URL', plugin_dir_url( __FILE__ ) );
// Seconds per network step. Short enough that a dead server fails before the web server gives up (504).
define( 'MLAB_SMTP_TIMEOUT', 10 );
// Screen id of the plugin page (own top-level menu).
define( 'MLAB_SMTP_SCREEN', 'toplevel_page_mlab-smtp' );

foreach ( array( 'providers', 'options', 'crypto', 'security', 'http', 'm365', 'google', 'resend', 'state', 'db', 'store', 'log', 'log-migrate', 'webhooks', 'privacy', 'mailer', 'engine', 'engine-send', 'engine-worker', 'notify', 'health', 'detect', 'check', 'readiness', 'staging', 'alerts', 'support-report', 'admin/page', 'updater' ) as $mlab_smtp_module ) {
	require_once MLAB_SMTP_DIR . 'inc/' . $mlab_smtp_module . '.php';
}
unset( $mlab_smtp_module );

register_activation_hook( __FILE__, 'mlab_smtp_db_activate' );
register_activation_hook( __FILE__, 'mlab_smtp_engine_schedule' );
register_deactivation_hook( __FILE__, 'mlab_smtp_engine_deactivate' );

add_action( 'init', 'mlab_smtp_load_textdomain' );
add_filter( 'plugin_locale', 'mlab_smtp_filter_locale', 10, 2 );

/**
 * Translation file that serves a locale. German is the language of the source texts and needs no file. English,
 * French and Italian map to their file for every regional variant (en_GB, fr_CH, it_CH ...). Every other
 * language gets English, which more readers understand than German.
 *
 * @param string $locale WordPress locale, for example fr_CH.
 * @return string Locale of the translation file to load; the unchanged locale for German.
 */
function mlab_smtp_map_locale( string $locale ): string {
	$language  = strtolower( substr( $locale, 0, 2 ) );
	$supported = array(
		'en' => 'en_US',
		'fr' => 'fr_FR',
		'it' => 'it_IT',
	);
	return 'de' === $language ? $locale : ( $supported[ $language ] ?? 'en_US' );
}

/**
 * Applies mlab_smtp_map_locale() to the text domain of this plugin only.
 *
 * @param string $locale Locale WordPress is about to use.
 * @param string $domain Text domain.
 */
function mlab_smtp_filter_locale( $locale, $domain ) {
	return 'mlab-smtp' === $domain ? mlab_smtp_map_locale( (string) $locale ) : $locale;
}

/**
 * Loads the plugin translations.
 */
function mlab_smtp_load_textdomain(): void {
	load_plugin_textdomain( 'mlab-smtp', false, dirname( plugin_basename( MLAB_SMTP_FILE ) ) . '/languages' );
}
