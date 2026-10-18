<?php
/**
 * Backend: Einstellungsseite unter Einstellungen > MLab SMTP, Testmail und Protokoll.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'mlab_smtp_add_menu' );
add_action( 'admin_enqueue_scripts', 'mlab_smtp_enqueue_assets' );
add_action( 'admin_post_mlab_smtp_test', 'mlab_smtp_send_test' );
add_filter( 'plugin_action_links_' . plugin_basename( MLAB_SMTP_FILE ), 'mlab_smtp_action_links' );

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
 * URL of the settings page.
 */
function mlab_smtp_page_url(): string {
	return admin_url( 'options-general.php?page=mlab-smtp' );
}

/**
 * Remembers a message for the next page view of the current user.
 *
 * @param string $type    success or error.
 * @param string $message Message text.
 */
function mlab_smtp_flash( string $type, string $message ): void {
	set_transient(
		'mlab_smtp_flash_' . get_current_user_id(),
		array(
			'type'    => $type,
			'message' => $message,
		),
		60
	);
}

/**
 * Returns to the settings page.
 */
function mlab_smtp_back(): void {
	wp_safe_redirect( mlab_smtp_page_url() );
	exit;
}

/**
 * Adds the settings page.
 */
function mlab_smtp_add_menu(): void {
	add_options_page(
		__( 'MLab SMTP', 'mlab-smtp' ),
		__( 'MLab SMTP', 'mlab-smtp' ),
		'manage_options',
		'mlab-smtp',
		'mlab_smtp_render_page'
	);
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
 * Loads CSS and JS on the settings page only.
 *
 * @param string $hook_suffix Current admin page.
 */
function mlab_smtp_enqueue_assets( string $hook_suffix ): void {
	if ( 'settings_page_mlab-smtp' !== $hook_suffix ) {
		return;
	}
	wp_enqueue_style( 'mlab-smtp-admin', MLAB_SMTP_URL . 'assets/css/admin.css', array(), MLAB_SMTP_VERSION );
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
}

/**
 * Shows and clears the message left by the last action.
 */
function mlab_smtp_render_flash(): void {
	$key   = 'mlab_smtp_flash_' . get_current_user_id();
	$flash = get_transient( $key );
	if ( ! is_array( $flash ) ) {
		return;
	}
	delete_transient( $key );
	$class = 'success' === $flash['type'] ? 'notice-success' : 'notice-error';
	printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $flash['message'] ) );
}

/**
 * Settings page.
 */
function mlab_smtp_render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="wrap mlab-smtp"><h1>' . esc_html__( 'MLab SMTP', 'mlab-smtp' ) . '</h1>';
	mlab_smtp_render_flash();
	echo '<form method="post" action="options.php">';
	settings_fields( 'mlab_smtp' );
	mlab_smtp_render_sender();
	mlab_smtp_render_provider_choice();
	mlab_smtp_render_smtp_panel();
	mlab_smtp_render_m365_panel();
	mlab_smtp_render_google_panel();
	mlab_smtp_render_log_setting();
	submit_button();
	echo '</form>';
	mlab_smtp_render_test_form();
	mlab_smtp_render_log();
	echo '</div>';
}

/**
 * Prints one table row with a label and a field.
 *
 * @param string $key   Setting key.
 * @param string $label Row label.
 * @param array  $args  type (text, password, number, email), help, placeholder.
 */
function mlab_smtp_field( string $key, string $label, array $args = array() ): void {
	$args     = wp_parse_args(
		$args,
		array(
			'type'        => 'text',
			'help'        => '',
			'placeholder' => '',
		)
	);
	$secret   = in_array( $key, mlab_smtp_secret_keys(), true );
	$stored   = (string) mlab_smtp_get_all()[ $key ];
	$value    = $secret ? '' : $stored;
	$hint     = $secret && '' !== $stored ? __( 'Gespeichert. Leer lassen, um es zu behalten.', 'mlab-smtp' ) : $args['placeholder'];
	$field_id = 'mlab-smtp-' . $key;

	echo '<tr><th scope="row"><label for="' . esc_attr( $field_id ) . '">' . esc_html( $label ) . '</label></th><td>';
	printf(
		'<input type="%1$s" id="%2$s" name="%3$s[%4$s]" value="%5$s" placeholder="%6$s" class="regular-text" autocomplete="off">',
		esc_attr( $args['type'] ),
		esc_attr( $field_id ),
		esc_attr( MLAB_SMTP_OPTION ),
		esc_attr( $key ),
		esc_attr( (string) $value ),
		esc_attr( $hint )
	);
	if ( '' !== $args['help'] ) {
		echo '<p class="description">' . esc_html( $args['help'] ) . '</p>';
	}
	echo '</td></tr>';
}

/**
 * Prints one table row with a checkbox.
 *
 * @param string $key   Setting key.
 * @param string $label Row label.
 * @param string $text  Text next to the box.
 */
function mlab_smtp_checkbox( string $key, string $label, string $text ): void {
	echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label>';
	printf(
		'<input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s',
		esc_attr( MLAB_SMTP_OPTION ),
		esc_attr( $key ),
		checked( (bool) mlab_smtp_get_all()[ $key ], true, false ),
		esc_html( $text )
	);
	echo '</label></td></tr>';
}

/**
 * Sender section.
 */
function mlab_smtp_render_sender(): void {
	echo '<h2>' . esc_html__( 'Absender', 'mlab-smtp' ) . '</h2><table class="form-table" role="presentation">';
	mlab_smtp_field(
		'from_email',
		__( 'Absender-Adresse', 'mlab-smtp' ),
		array(
			'type' => 'email',
			'help' => __( 'Bei Microsoft 365 und Google muss das ein Postfach deines Kontos oder ein erlaubter Alias sein.', 'mlab-smtp' ),
		)
	);
	mlab_smtp_field( 'from_name', __( 'Absender-Name', 'mlab-smtp' ), array( 'placeholder' => get_bloginfo( 'name' ) ) );
	mlab_smtp_checkbox( 'force_from', __( 'Absender erzwingen', 'mlab-smtp' ), __( 'Adresse und Name auch dann verwenden, wenn ein anderes Plugin einen anderen setzt.', 'mlab-smtp' ) );
	echo '</table>';
}

/**
 * Provider radio buttons.
 */
function mlab_smtp_render_provider_choice(): void {
	$current   = (string) mlab_smtp_get( 'provider' );
	$providers = array(
		'smtp'   => __( 'Eigener Mailserver (SMTP)', 'mlab-smtp' ),
		'm365'   => __( 'Microsoft 365', 'mlab-smtp' ),
		'google' => __( 'Google (Gmail / Workspace)', 'mlab-smtp' ),
	);
	echo '<h2>' . esc_html__( 'Versandart', 'mlab-smtp' ) . '</h2><p class="mlab-smtp-providers">';
	foreach ( $providers as $value => $label ) {
		printf(
			'<label><input type="radio" name="%1$s[provider]" value="%2$s" %3$s> %4$s</label>',
			esc_attr( MLAB_SMTP_OPTION ),
			esc_attr( $value ),
			checked( $current, $value, false ),
			esc_html( $label )
		);
	}
	echo '</p>';
}

/**
 * SMTP panel.
 */
function mlab_smtp_render_smtp_panel(): void {
	echo '<div class="mlab-smtp-panel" data-provider="smtp"><table class="form-table" role="presentation">';
	mlab_smtp_field( 'smtp_host', __( 'Server', 'mlab-smtp' ), array( 'placeholder' => 'mail.example.ch' ) );
	mlab_smtp_field(
		'smtp_port',
		__( 'Port', 'mlab-smtp' ),
		array(
			'type' => 'number',
			'help' => __( 'Üblich: 587 mit STARTTLS, 465 mit SSL.', 'mlab-smtp' ),
		)
	);
	echo '<tr><th scope="row"><label for="mlab-smtp-smtp_secure">' . esc_html__( 'Verschlüsselung', 'mlab-smtp' ) . '</label></th><td>';
	echo '<select id="mlab-smtp-smtp_secure" name="' . esc_attr( MLAB_SMTP_OPTION ) . '[smtp_secure]">';
	$options = array(
		'tls'  => __( 'STARTTLS', 'mlab-smtp' ),
		'ssl'  => __( 'SSL/TLS', 'mlab-smtp' ),
		'none' => __( 'Keine (nicht empfohlen)', 'mlab-smtp' ),
	);
	foreach ( $options as $value => $label ) {
		printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( (string) mlab_smtp_get( 'smtp_secure' ), $value, false ), esc_html( $label ) );
	}
	echo '</select></td></tr>';
	mlab_smtp_checkbox( 'smtp_auth', __( 'Anmeldung', 'mlab-smtp' ), __( 'Der Server verlangt Benutzername und Passwort.', 'mlab-smtp' ) );
	mlab_smtp_field( 'smtp_user', __( 'Benutzername', 'mlab-smtp' ) );
	mlab_smtp_field(
		'smtp_pass',
		__( 'Passwort', 'mlab-smtp' ),
		array(
			'type' => 'password',
			'help' => __( 'Wird verschlüsselt gespeichert. Alternativ die Konstante MLAB_SMTP_SMTP_PASS in der wp-config.php setzen.', 'mlab-smtp' ),
		)
	);
	echo '</table></div>';
}

/**
 * Microsoft 365 panel.
 */
function mlab_smtp_render_m365_panel(): void {
	echo '<div class="mlab-smtp-panel" data-provider="m365">';
	echo '<p class="description">' . esc_html__( 'In Microsoft Entra eine App registrieren, die Anwendungsberechtigung „Mail.Send“ (Microsoft Graph) hinzufügen und den Administratorzugriff erteilen. Die App kann danach aus Postfächern senden. Beschränke sie mit einer Application Access Policy auf das Absender-Postfach.', 'mlab-smtp' ) . '</p>';
	echo '<table class="form-table" role="presentation">';
	mlab_smtp_field( 'm365_tenant', __( 'Verzeichnis-ID (Tenant)', 'mlab-smtp' ), array( 'placeholder' => '00000000-0000-0000-0000-000000000000' ) );
	mlab_smtp_field( 'm365_client_id', __( 'Anwendungs-ID (Client)', 'mlab-smtp' ) );
	mlab_smtp_field(
		'm365_client_secret',
		__( 'Geheimer Clientschlüssel', 'mlab-smtp' ),
		array(
			'type' => 'password',
			'help' => __( 'Der Wert des Schlüssels, nicht seine ID.', 'mlab-smtp' ),
		)
	);
	echo '</table></div>';
}

/**
 * Google panel with the connect button.
 */
function mlab_smtp_render_google_panel(): void {
	echo '<div class="mlab-smtp-panel" data-provider="google">';
	echo '<p class="description">' . esc_html__( 'In der Google Cloud Console ein OAuth-Client vom Typ „Webanwendung“ anlegen, die Gmail API aktivieren und die untenstehende Weiterleitungs-URI eintragen. Danach Client-ID und Secret speichern und das Konto verbinden.', 'mlab-smtp' ) . '</p>';
	echo '<table class="form-table" role="presentation">';
	echo '<tr><th scope="row">' . esc_html__( 'Weiterleitungs-URI', 'mlab-smtp' ) . '</th><td><code>' . esc_html( mlab_smtp_google_redirect_uri() ) . '</code></td></tr>';
	mlab_smtp_field( 'google_client_id', __( 'Client-ID', 'mlab-smtp' ) );
	mlab_smtp_field( 'google_client_secret', __( 'Client-Secret', 'mlab-smtp' ), array( 'type' => 'password' ) );
	echo '<tr><th scope="row">' . esc_html__( 'Verbindung', 'mlab-smtp' ) . '</th><td>';
	mlab_smtp_render_google_status();
	echo '</td></tr></table></div>';
}

/**
 * Connection state and the matching button.
 */
function mlab_smtp_render_google_status(): void {
	if ( '' !== mlab_smtp_get( 'google_refresh_token' ) ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=mlab_smtp_google_disconnect' ), 'mlab_smtp_google_disconnect' );
		echo '<strong>' . esc_html__( 'Verbunden.', 'mlab-smtp' ) . '</strong> <a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Trennen', 'mlab-smtp' ) . '</a>';
		return;
	}
	$url = wp_nonce_url( admin_url( 'admin-post.php?action=mlab_smtp_google_connect' ), 'mlab_smtp_google_connect' );
	echo '<a class="button button-secondary" href="' . esc_url( $url ) . '">' . esc_html__( 'Mit Google verbinden', 'mlab-smtp' ) . '</a>';
	echo '<p class="description">' . esc_html__( 'Zuerst Client-ID und Secret speichern.', 'mlab-smtp' ) . '</p>';
}

/**
 * Log setting.
 */
function mlab_smtp_render_log_setting(): void {
	echo '<h2>' . esc_html__( 'Protokoll', 'mlab-smtp' ) . '</h2><table class="form-table" role="presentation">';
	mlab_smtp_checkbox( 'log_enabled', __( 'Versand protokollieren', 'mlab-smtp' ), __( 'Speichert Empfänger, Betreff und Ergebnis der letzten 50 Nachrichten, nie den Inhalt.', 'mlab-smtp' ) );
	echo '</table>';
}

/**
 * Test mail form.
 */
function mlab_smtp_render_test_form(): void {
	$user = wp_get_current_user();
	echo '<hr><h2>' . esc_html__( 'Testmail senden', 'mlab-smtp' ) . '</h2>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="mlab_smtp_test">';
	wp_nonce_field( 'mlab_smtp_test' );
	echo '<p><input type="email" name="to" class="regular-text" required value="' . esc_attr( $user->user_email ) . '"> ';
	submit_button( __( 'Testmail senden', 'mlab-smtp' ), 'secondary', 'submit', false );
	echo '</p></form>';
}

/**
 * Sends the test mail and reports the result.
 */
function mlab_smtp_send_test(): void {
	mlab_smtp_guard( 'mlab_smtp_test' );
	$to = sanitize_email( wp_unslash( $_POST['to'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in mlab_smtp_guard().
	if ( '' === $to ) {
		mlab_smtp_flash( 'error', __( 'Bitte eine gültige E-Mail-Adresse eingeben.', 'mlab-smtp' ) );
		mlab_smtp_back();
	}

	$errors  = array();
	$capture = static function ( WP_Error $error ) use ( &$errors ): void {
		$errors[] = $error->get_error_message();
	};
	add_action( 'wp_mail_failed', $capture );
	/* translators: %s: site name */
	$subject = sprintf( __( 'Testmail von %s', 'mlab-smtp' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	$sent    = wp_mail( $to, $subject, __( 'Diese Nachricht bestätigt, dass der E-Mail-Versand funktioniert.', 'mlab-smtp' ) );
	remove_action( 'wp_mail_failed', $capture );

	if ( $sent ) {
		/* translators: %s: recipient address */
		mlab_smtp_flash( 'success', sprintf( __( 'Die Testmail an %s wurde versendet.', 'mlab-smtp' ), $to ) );
	} else {
		/* translators: %s: error message */
		mlab_smtp_flash( 'error', sprintf( __( 'Der Versand ist fehlgeschlagen: %s', 'mlab-smtp' ), implode( ' ', $errors ) ) );
	}
	mlab_smtp_back();
}

/**
 * Log table.
 */
function mlab_smtp_render_log(): void {
	$log = mlab_smtp_log_get();
	echo '<hr><h2>' . esc_html__( 'Letzte Nachrichten', 'mlab-smtp' ) . '</h2>';
	if ( ! $log ) {
		echo '<p>' . esc_html__( 'Noch keine Nachrichten.', 'mlab-smtp' ) . '</p>';
		return;
	}
	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Zeit', 'mlab-smtp' ) . '</th><th>' . esc_html__( 'Empfänger', 'mlab-smtp' ) . '</th><th>' . esc_html__( 'Betreff', 'mlab-smtp' ) . '</th><th>' . esc_html__( 'Ergebnis', 'mlab-smtp' ) . '</th></tr></thead><tbody>';
	foreach ( $log as $entry ) {
		$result = $entry['ok'] ? __( 'Gesendet', 'mlab-smtp' ) : $entry['error'];
		printf(
			'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
			esc_html( wp_date( 'd.m.Y H:i', (int) $entry['time'] ) ),
			esc_html( $entry['to'] ),
			esc_html( $entry['subject'] ),
			esc_html( $result )
		);
	}
	echo '</tbody></table>';
	$url = wp_nonce_url( admin_url( 'admin-post.php?action=mlab_smtp_clear_log' ), 'mlab_smtp_clear_log' );
	echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Protokoll leeren', 'mlab-smtp' ) . '</a></p>';
}
