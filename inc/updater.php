<?php
/**
 * Updates über GitHub Releases (Plugin Update Checker v5).
 *
 * Die Quelle steht nur hier. Der Header "Update URI" in der Hauptdatei nennt dieselbe URL.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

add_action( 'init', 'mlab_smtp_register_updater' );

/**
 * Registers the update checker. Private repos need the token constant in wp-config.php.
 */
function mlab_smtp_register_updater(): void {
	$private = false;
	$token   = defined( 'MLAB_SMTP_GITHUB_TOKEN' ) ? (string) constant( 'MLAB_SMTP_GITHUB_TOKEN' ) : '';

	if ( $private && '' === $token ) {
		add_action( 'admin_notices', 'mlab_smtp_missing_token_notice' );
		return;
	}

	$checker = PucFactory::buildUpdateChecker(
		'https://github.com/momentumlab-ch/mlab-smtp/',
		dirname( __DIR__ ) . '/mlab-smtp.php',
		'mlab-smtp'
	);
	$checker->setBranch( 'main' );
	$checker->addResultFilter( 'mlab_smtp_plugin_details' );

	$api = $checker->getVcsApi();
	// 2 = Api::REQUIRE_RELEASE_ASSETS: ohne passendes ZIP kein Update, nie der GitHub-Quellcode.
	$api->enableReleaseAssets( '/^mlab-smtp\.zip$/', 2 );
	if ( $private ) {
		$api->setAuthentication( $token );
	}
}

/**
 * Admin-only hint, shown when a private repo has no token constant.
 */
function mlab_smtp_missing_token_notice(): void {
	if ( ! current_user_can( mlab_smtp_capability() ) ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'Updates sind nicht aktiv: In wp-config.php fehlt die Konstante MLAB_SMTP_GITHUB_TOKEN.', 'mlab-smtp' )
	);
}

/**
 * Adds the bundled English screenshot to the GitHub distribution's plugin details.
 * Directory screenshots are served separately by WordPress.org from its SVN assets folder.
 *
 * @param object|null $info Parsed plugin metadata, or null after a failed metadata request.
 * @return object|null
 */
function mlab_smtp_plugin_details( $info ) {
	if ( ! is_object( $info ) ) {
		return $info;
	}
	$image = '<p><img src="' . esc_url( plugins_url( 'assets/screenshots/screenshot-1.png', MLAB_SMTP_FILE ) ) . '" alt="MomentumLab SMTP English dashboard with main route, backup readiness and queue status" style="max-width:100%;height:auto;" /></p>'
		. '<p>English interface with demonstration data. Review sending health, main and backup routes, waiting emails and issues that need attention.</p>';

	$info->sections = (array) ( $info->sections ?? array() );

	$info->sections['screenshots'] = $image;
	$info->sections['description'] = (string) ( $info->sections['description'] ?? '' ) . '<h3>Inside the plugin</h3>' . $image;
	return $info;
}
