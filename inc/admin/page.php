<?php
/**
 * Admin entry: menu, assets, routing between the views, shared page helpers.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

foreach ( array( 'components', 'domain-view', 'license-view', 'handlers', 'rest', 'overview', 'wizard', 'resend-view', 'queue-view', 'log-view', 'settings-view', 'fallback-flow', 'fallback-actions', 'foreign-notices' ) as $mlab_smtp_admin_file ) {
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
	if ( ! current_user_can( mlab_smtp_capability() ) ) {
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
 * Adds the plugin as its own top-level menu entry with the branded mail icon.
 */
function mlab_smtp_add_menu(): void {
	add_menu_page(
		__( 'E-Mail-Versand', 'mlab-smtp' ),
		__( 'E-Mail-Versand', 'mlab-smtp' ),
		mlab_smtp_capability(),
		'mlab-smtp',
		'mlab_smtp_render_page',
		'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCI+PHBhdGggZmlsbD0ibm9uZSIgc3Ryb2tlPSIjYTdhYWFkIiBzdHJva2Utd2lkdGg9IjEuNCIgZD0iTTIuNiA4LjJhMTAgMTAgMCAwIDEgMTguOCAwTTIuNiAxNS44YTEwIDEwIDAgMCAwIDE4LjggME01LjIgOC4yaDEzLjZ2Ny42SDUuMnpNNS4yIDguMiAxMiAxMy4ybDYuOC01Ii8+PC9zdmc+',
		76
	);
}

/**
 * Sends old links (Settings > E-Mail-Versand) to the new menu entry, keeping view, step and provider.
 */
function mlab_smtp_redirect_old_url(): void {
	global $pagenow;
	if ( 'options-general.php' !== $pagenow || 'mlab-smtp' !== mlab_smtp_query( 'page' ) ) {
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
	if ( 'plugin-install.php' === $hook_suffix && 'plugin-information' === mlab_smtp_query( 'tab' ) && 'mlab-smtp' === mlab_smtp_query( 'plugin' ) ) {
		wp_enqueue_style( 'mlab-smtp-details', MLAB_SMTP_URL . 'assets/css/plugin-details.css', array(), MLAB_SMTP_VERSION );
		return;
	}
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
 *
 * @param bool $inline Keep the message in the current setup card instead of a movable WordPress notice.
 */
function mlab_smtp_render_flash( bool $inline = false ): void {
	$key   = 'mlab_smtp_flash_' . get_current_user_id();
	$flash = get_transient( $key );
	if ( ! is_array( $flash ) ) {
		return;
	}
	delete_transient( $key );
	if ( $inline ) {
		$levels = array(
			'success' => 'ok',
			'warning' => 'warn',
		);
		$level  = $levels[ $flash['type'] ] ?? 'bad';
		echo '<div id="mlab-step-feedback" class="mlab-step-feedback" data-mlab-step-feedback tabindex="-1" role="' . ( 'bad' === $level ? 'alert' : 'status' ) . '">';
		mlab_smtp_ui_hint(
			$level,
			(string) $flash['message'],
			'',
			static function () use ( $flash ): void {
				if ( ! empty( $flash['detail'] ) ) {
					mlab_smtp_ui_technical( (string) $flash['detail'] );
				}
			}
		);
		echo '</div>';
		return;
	}
	$classes = array(
		'success' => 'notice-success',
		'warning' => 'notice-warning',
	);
	$class   = $classes[ $flash['type'] ] ?? 'notice-error';
	echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible" role="' . ( 'notice-error' === $class ? 'alert' : 'status' ) . '"><p>' . esc_html( $flash['message'] ) . '</p>';
	if ( ! empty( $flash['detail'] ) ) {
		mlab_smtp_ui_technical( (string) $flash['detail'] );
	}
	echo '</div>';
}

/**
 * Tabs of the page: slug => label. Other code may add tabs through the filter mlab_smtp_admin_tabs.
 *
 * @return array<string, string>
 */
function mlab_smtp_admin_tabs(): array {
	$tabs = apply_filters(
		'mlab_smtp_admin_tabs',
		array(
			'overview' => __( 'Übersicht', 'mlab-smtp' ),
			'queue'    => __( 'Warteschlange', 'mlab-smtp' ),
			'log'      => __( 'Protokoll', 'mlab-smtp' ),
			'settings' => __( 'Einstellungen', 'mlab-smtp' ),
		)
	);
	$out  = array();
	foreach ( is_array( $tabs ) ? $tabs : array() as $slug => $label ) {
		if ( is_string( $slug ) && '' !== sanitize_key( $slug ) && is_string( $label ) ) {
			$out[ sanitize_key( $slug ) ] = $label;
		}
	}
	return $out;
}

/**
 * Number shown as badge on a tab (0 = none): failures on the log, open and unclear messages on the queue.
 *
 * @param string $slug   Tab.
 * @param array  $status Result of mlab_smtp_status().
 */
function mlab_smtp_tab_count( string $slug, array $status ): int {
	if ( 'queue' === $slug ) {
		return mlab_smtp_queue_badge_count();
	}
	return ( 'log' === $slug && 'bad' === $status['state'] && $status['failures'] > 0 ) ? (int) $status['failures'] : 0;
}

/**
 * Page title, lead text and tab navigation.
 *
 * @param string $view Current view.
 */
function mlab_smtp_render_header( string $view ): void {
	$status = mlab_smtp_status();
	echo '<div class="mlab-head"><div><img class="mlab-brand" src="' . esc_url( MLAB_SMTP_URL . 'assets/brand/logo.svg' ) . '" alt="MomentumLab SMTP" width="260" height="65"><h1>' . esc_html__( 'E-Mail-Versand', 'mlab-smtp' ) . '</h1><p class="mlab-lead">' . esc_html__( 'Sorgt dafür, dass Mails dieser Website zuverlässig ankommen und nicht im Spam landen.', 'mlab-smtp' ) . '</p></div>';
	if ( 'overview' === $view ) {
		mlab_smtp_render_head_action();
	}
	echo '</div><hr class="wp-header-end">';
	if ( 'fallback' !== mlab_smtp_query( 'feedback' ) ) {
		mlab_smtp_render_flash();
	}
	echo '<nav class="nav-tab-wrapper mlab-tabs" aria-label="' . esc_attr__( 'Bereiche', 'mlab-smtp' ) . '">';
	foreach ( mlab_smtp_admin_tabs() as $slug => $label ) {
		$count = mlab_smtp_tab_count( $slug, $status );
		printf(
			'<a class="nav-tab%1$s" href="%2$s"%3$s>%4$s%5$s</a>',
			$view === $slug ? ' nav-tab-active' : '',
			esc_url( mlab_smtp_page_url( 'overview' === $slug ? array() : array( 'view' => $slug ) ) ),
			$view === $slug ? ' aria-current="page"' : '',
			esc_html( $label ),
			$count ? '<span class="mlab-count">' . esc_html( (string) $count ) . '<span class="screen-reader-text"> ' . esc_html( _n( 'Hinweis', 'Hinweise', $count, 'mlab-smtp' ) ) . '</span></span>' : ''
		);
	}
	echo '</nav>';
}

/**
 * Renders the requested view.
 */
function mlab_smtp_render_page(): void {
	if ( ! current_user_can( mlab_smtp_capability() ) ) {
		return;
	}
	$view = mlab_smtp_query( 'view', 'overview' );
	$view = in_array( $view, array_merge( array_keys( mlab_smtp_admin_tabs() ), array( 'wizard', 'fallback' ) ), true ) ? $view : 'overview';
	echo '<div class="wrap mlab-smtp">';
	if ( 'wizard' === $view ) {
		mlab_smtp_render_wizard();
	} elseif ( 'fallback' === $view ) {
		mlab_smtp_render_fallback_flow();
	} else {
		mlab_smtp_render_header( $view );
		$core = array(
			'overview' => 'mlab_smtp_render_overview',
			'queue'    => 'mlab_smtp_render_queue',
			'log'      => 'mlab_smtp_render_log',
			'settings' => 'mlab_smtp_render_settings',
		);
		if ( isset( $core[ $view ] ) ) {
			call_user_func( $core[ $view ] );
		} else {
			do_action( 'mlab_smtp_render_tab_' . $view );
		}
	}
	echo '</div>';
	mlab_smtp_render_foreign_notices();
}
