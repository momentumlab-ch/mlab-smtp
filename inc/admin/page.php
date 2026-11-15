<?php
/**
 * Admin entry: menu, assets, routing between the views, shared page helpers.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

foreach ( array( 'components', 'handlers', 'rest', 'overview', 'wizard', 'resend-view', 'log-view', 'settings-view', 'foreign-notices' ) as $mlab_smtp_admin_file ) {
	require_once MLAB_SMTP_DIR . 'inc/admin/' . $mlab_smtp_admin_file . '.php';
}
unset( $mlab_smtp_admin_file );

add_action( 'admin_menu', 'mlab_smtp_add_menu' );
add_action( 'admin_init', 'mlab_smtp_redirect_old_url' );
add_action( 'admin_enqueue_scripts', 'mlab_smtp_enqueue_assets' );
add_filter( 'plugin_action_links_' . plugin_basename( MLAB_SMTP_FILE ), 'mlab_smtp_action_links' );

/**
 * URL of the plugin page.
 *
 * @param array $args Extra query arguments, for example view and step.
 */
function mlab_smtp_page_url( array $args = array() ): string {
	return add_query_arg( array_merge( array( 'page' => 'mlab-smtp' ), $args ), admin_url( 'admin.php' ) );
}

/**
 * Checks permission and nonce of an admin-post request.
 *
 * @param string $action Nonce action.
 */
function mlab_smtp_guard( string $action ): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'mlab-smtp' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( $action );
}

/**
 * Remembers a message for the next page view of the current user.
 *
 * @param string $type    success, error or warning.
 * @param string $message Message text.
 * @param string $detail  Optional technical detail, shown in a collapsible block.
 */
function mlab_smtp_flash( string $type, string $message, string $detail = '' ): void {
	set_transient(
		'mlab_smtp_flash_' . get_current_user_id(),
		array(
			'type'    => $type,
			'message' => $message,
			'detail'  => $detail,
		),
		60
	);
}

/**
 * Returns to the plugin page and stops.
 *
 * @param array $args Query arguments, see mlab_smtp_page_url().
 */
function mlab_smtp_back( array $args = array() ): void {
	wp_safe_redirect( mlab_smtp_page_url( $args ) );
	exit;
}

/**
 * Reads one sanitized GET parameter of the plugin page. Only used for display state, never for writes.
 *
 * @param string $key     Parameter name.
 * @param string $fallback Value when the parameter is missing.
 */
function mlab_smtp_query( string $key, string $fallback = '' ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view state.
	return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $fallback;
}

/**
 * Adds the plugin as its own top-level menu entry with a paper plane icon.
 */
function mlab_smtp_add_menu(): void {
	add_menu_page(
		__( 'E-Mail-Versand', 'mlab-smtp' ),
		__( 'E-Mail-Versand', 'mlab-smtp' ),
		'manage_options',
		'mlab-smtp',
		'mlab_smtp_render_page',
		'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZmlsbD0iI2E3YWFhZCIgZD0iTTE4LjYgMS40YTEgMSAwIDAgMC0xLS4yTDEuNSA3LjRhMSAxIDAgMCAwLS4xIDEuOGw1LjQgMi41IDIuNSA1LjRhMSAxIDAgMCAwIDEuOC0uMWw2LjItMTYuMWExIDEgMCAwIDAtLjctMS41ek04LjIgMTEuMyA1IDkuOCAxNS4yIDUuOWwtNyA1LjR6Ii8+PC9zdmc+',
		76
	);
}

/**
 * Sends old links (Settings > E-Mail-Versand) to the new menu entry, keeping view, step and provider.
 */
function mlab_smtp_redirect_old_url(): void {
	global $pagenow;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only a redirect, nothing is read or changed.
	if ( 'options-general.php' !== $pagenow || 'mlab-smtp' !== ( $_GET['page'] ?? '' ) ) {
		return;
	}
	$args = array();
	foreach ( array( 'view', 'step', 'provider' ) as $key ) {
		$args[ $key ] = mlab_smtp_query( $key );
	}
	wp_safe_redirect( mlab_smtp_page_url( array_filter( $args ) ) );
	exit;
}

/**
 * Adds a "Settings" link on the plugins list.
 *
 * @param string[] $links Existing links.
 * @return string[]
 */
function mlab_smtp_action_links( array $links ): array {
	$link = '<a href="' . esc_url( mlab_smtp_page_url() ) . '">' . esc_html__( 'Einstellungen', 'mlab-smtp' ) . '</a>';
	return array_merge( array( $link ), $links );
}

/**
 * Loads CSS and JS on the plugin page only.
 *
 * @param string $hook_suffix Current admin page.
 */
function mlab_smtp_enqueue_assets( string $hook_suffix ): void {
	if ( MLAB_SMTP_SCREEN !== $hook_suffix ) {
		return;
	}
	wp_enqueue_style( 'mlab-smtp-admin', MLAB_SMTP_URL . 'assets/css/admin.css', array( 'dashicons' ), MLAB_SMTP_VERSION );
	wp_enqueue_script(
		'mlab-smtp-admin',
		MLAB_SMTP_URL . 'assets/js/admin.js',
		array(),
		MLAB_SMTP_VERSION,
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
	wp_add_inline_script( 'mlab-smtp-admin', 'window.mlabSmtp = ' . wp_json_encode( mlab_smtp_script_config() ) . ';', 'before' );
}

/**
 * Data handed to admin.js: REST base, nonce and the texts JavaScript has to show.
 *
 * @return array<string, mixed>
 */
function mlab_smtp_script_config(): array {
	return array(
		'rest'  => esc_url_raw( rest_url( 'mlab-smtp/v1/' ) ),
		'nonce' => wp_create_nonce( 'wp_rest' ),
		'i18n'  => array(
			'saving'    => __( 'Wird gespeichert …', 'mlab-smtp' ),
			'checking'  => __( 'Wird geprüft …', 'mlab-smtp' ),
			'sending'   => __( 'Wird gesendet …', 'mlab-smtp' ),
			'network'   => __( 'Der Server hat nicht geantwortet. Bitte versuchen Sie es erneut.', 'mlab-smtp' ),
			'copied'    => __( 'Kopiert.', 'mlab-smtp' ),
			'copy'      => __( 'Kopieren', 'mlab-smtp' ),
			'technical' => __( 'Technische Meldung', 'mlab-smtp' ),
		),
	);
}

/**
 * Prints the message left by the last action and clears it.
 */
function mlab_smtp_render_flash(): void {
	$key   = 'mlab_smtp_flash_' . get_current_user_id();
	$flash = get_transient( $key );
	if ( ! is_array( $flash ) ) {
		return;
	}
	delete_transient( $key );
	$classes = array(
		'success' => 'notice-success',
		'warning' => 'notice-warning',
	);
	$class   = $classes[ $flash['type'] ] ?? 'notice-error';
	echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $flash['message'] ) . '</p>';
	if ( ! empty( $flash['detail'] ) ) {
		mlab_smtp_ui_technical( (string) $flash['detail'] );
	}
	echo '</div>';
}

/**
 * Page title, lead text and tab navigation.
 *
 * @param string $view Current view.
 */
function mlab_smtp_render_header( string $view ): void {
	$status = mlab_smtp_status();
	$tabs   = array(
		'overview' => __( 'Übersicht', 'mlab-smtp' ),
		'log'      => __( 'Protokoll', 'mlab-smtp' ),
		'settings' => __( 'Einstellungen', 'mlab-smtp' ),
	);
	echo '<div class="mlab-head"><div><h1>' . esc_html__( 'E-Mail-Versand', 'mlab-smtp' ) . '</h1><p class="mlab-lead">' . esc_html__( 'Sorgt dafür, dass Mails dieser Website zuverlässig ankommen und nicht im Spam landen.', 'mlab-smtp' ) . '</p></div>';
	if ( 'overview' === $view ) {
		mlab_smtp_render_head_action();
	}
	echo '</div><hr class="wp-header-end">';
	mlab_smtp_render_flash();
	echo '<nav class="nav-tab-wrapper mlab-tabs" aria-label="' . esc_attr__( 'Bereiche', 'mlab-smtp' ) . '">';
	foreach ( $tabs as $slug => $label ) {
		$count = ( 'log' === $slug && 'bad' === $status['state'] && $status['failures'] > 0 ) ? (int) $status['failures'] : 0;
		printf(
			'<a class="nav-tab%1$s" href="%2$s"%3$s>%4$s%5$s</a>',
			$view === $slug ? ' nav-tab-active' : '',
			esc_url( mlab_smtp_page_url( 'overview' === $slug ? array() : array( 'view' => $slug ) ) ),
			$view === $slug ? ' aria-current="page"' : '',
			esc_html( $label ),
			$count ? '<span class="mlab-count">' . esc_html( (string) $count ) . '</span>' : ''
		);
	}
	echo '</nav>';
}

/**
 * Renders the requested view.
 */
function mlab_smtp_render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$view = mlab_smtp_query( 'view', 'overview' );
	$view = in_array( $view, array( 'overview', 'log', 'settings', 'wizard' ), true ) ? $view : 'overview';
	echo '<div class="wrap mlab-smtp">';
	if ( 'wizard' === $view ) {
		mlab_smtp_render_wizard();
	} else {
		mlab_smtp_render_header( $view );
		call_user_func(
			array(
				'overview' => 'mlab_smtp_render_overview',
				'log'      => 'mlab_smtp_render_log',
				'settings' => 'mlab_smtp_render_settings',
			)[ $view ]
		);
	}
	echo '</div>';
	mlab_smtp_render_foreign_notices();
}
