<?php
/**
 * Setup wizard: provider, connect, sender, test mail.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Provider the wizard works on: URL parameter, otherwise the stored one, otherwise the detected one.
 */
function mlab_smtp_wizard_provider(): string {
	$allowed = array( 'smtp', 'm365', 'google' );
	$stored  = mlab_smtp_get( 'setup_done' ) ? (string) mlab_smtp_get( 'provider' ) : '';
	foreach ( array( mlab_smtp_query( 'provider' ), $stored ) as $candidate ) {
		if ( in_array( $candidate, $allowed, true ) ) {
			return $candidate;
		}
	}
	return mlab_smtp_pick( mlab_smtp_detect_provider()['provider'] ?? '', $allowed, 'smtp' );
}

/**
 * Wizard page: title, stepper and the current step.
 */
function mlab_smtp_render_wizard(): void {
	$step     = max( 1, min( 4, (int) mlab_smtp_query( 'step', '1' ) ) );
	$provider = mlab_smtp_wizard_provider();
	$steps    = array(
		1 => 'mlab_smtp_render_step_provider',
		2 => 'mlab_smtp_render_step_connect',
		3 => 'mlab_smtp_render_step_sender',
		4 => 'mlab_smtp_render_step_test',
	);
	echo '<div class="mlab-head"><h1>' . esc_html__( 'E-Mail-Versand einrichten', 'mlab-smtp' ) . '</h1><a class="button-link" href="' . esc_url( mlab_smtp_page_url() ) . '">' . esc_html__( 'Abbrechen', 'mlab-smtp' ) . '</a></div><hr class="wp-header-end">';
	mlab_smtp_render_flash();
	mlab_smtp_render_stepper( $step );
	echo '<div class="mlab-card">';
	call_user_func( $steps[ $step ], $provider );
	echo '</div>';
}

/**
 * Numbered step indicator.
 *
 * @param int $step Current step.
 */
function mlab_smtp_render_stepper( int $step ): void {
	$labels = array( __( 'Anbieter', 'mlab-smtp' ), __( 'Verbinden', 'mlab-smtp' ), __( 'Absender', 'mlab-smtp' ), __( 'Testen', 'mlab-smtp' ) );
	echo '<ol class="mlab-stepper">';
	foreach ( $labels as $index => $label ) {
		$number = $index + 1;
		$class  = $number < $step ? 'is-done' : ( $number === $step ? 'is-now' : '' );
		echo '<li class="' . esc_attr( $class ) . '"' . ( $number === $step ? ' aria-current="step"' : '' ) . '>' . esc_html( $label ) . '</li>';
	}
	echo '</ol>';
}

/**
 * Opens the form of a wizard step.
 *
 * @param int    $step     Step number.
 * @param string $provider Provider key.
 * @param string $classes  Extra classes.
 * @param array  $attrs    Extra attributes.
 */
function mlab_smtp_wizard_form_open( int $step, string $provider, string $classes = '', array $attrs = array() ): void {
	mlab_smtp_ui_form_open( 'mlab_smtp_wizard', $classes, $attrs );
	echo '<input type="hidden" name="step" value="' . esc_attr( (string) $step ) . '"><input type="hidden" name="provider" value="' . esc_attr( $provider ) . '">';
}

/**
 * Back link and forward button of a step.
 *
 * @param string $back_url      URL of the previous step.
 * @param string $next          Label of the forward button.
 * @param string $disabled_hint When not empty the button starts disabled and shows this as its title.
 * @param string $value         Value of the do field.
 * @param string $marker        Marker attribute for admin.js.
 */
function mlab_smtp_render_step_foot( string $back_url, string $next, string $disabled_hint = '', string $value = 'next', string $marker = 'data-mlab-next' ): void {
	$disabled = '' !== $disabled_hint ? ' disabled title="' . esc_attr( $disabled_hint ) . '"' : '';
	echo '<div class="mlab-foot"><a class="button button-link" href="' . esc_url( $back_url ) . '">' . esc_html__( 'Zurück', 'mlab-smtp' ) . '</a>';
	echo '<button type="submit" name="do" value="' . esc_attr( $value ) . '" class="button button-primary" ' . esc_attr( $marker ) . $disabled . '>' . esc_html( $next ) . '</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
}

/**
 * Step 1: choose the provider.
 *
 * @param string $provider Preselected provider.
 */
function mlab_smtp_render_step_provider( string $provider ): void {
	$detected = mlab_smtp_detect_provider();
	$domain   = (string) substr( (string) strrchr( (string) get_option( 'admin_email' ), '@' ), 1 );
	echo '<h2>' . esc_html__( 'Über welchen Dienst laufen Ihre Firmen-Mails?', 'mlab-smtp' ) . '</h2><p class="mlab-muted">' . esc_html__( 'Wählen Sie den Dienst, bei dem Ihr Postfach liegt. So kommen Mails am zuverlässigsten an.', 'mlab-smtp' ) . '</p>';
	if ( ! empty( $detected['provider'] ) && 'smtp' !== $detected['provider'] && '' !== $domain ) {
		$name = mlab_smtp_ui_providers()[ $detected['provider'] ]['name'] ?? '';
		/* translators: 1: domain, 2: provider name */
		mlab_smtp_ui_hint( 'info', sprintf( __( 'Für %1$s haben wir %2$s erkannt.', 'mlab-smtp' ), $domain, $name ), '' !== $detected['mx'] ? sprintf( /* translators: %s: MX host */ __( 'Gefunden im Mail-Eintrag der Domain (MX: %s)', 'mlab-smtp' ), $detected['mx'] ) : '' );
	}
	mlab_smtp_wizard_form_open( 1, $provider );
	echo '<fieldset class="mlab-providers"><legend class="screen-reader-text">' . esc_html__( 'Mailanbieter', 'mlab-smtp' ) . '</legend>';
	foreach ( mlab_smtp_provider_cards() as $key => $card ) {
		mlab_smtp_render_provider_card( $key, $card, $provider, '' !== ( $detected['mx'] ?? '' ) && $detected['provider'] === $key );
	}
	echo '</fieldset><div class="mlab-foot"><span></span><button type="submit" class="button button-primary">' . esc_html__( 'Weiter', 'mlab-smtp' ) . '</button></div>';
	mlab_smtp_ui_form_close();
}

/**
 * Description and bullet points of the provider cards.
 *
 * @return array<string, array{text:string, bullets:string[]}>
 */
function mlab_smtp_provider_cards(): array {
	return array(
		'm365'   => array(
			'text'    => __( 'Postfach bei Microsoft (Outlook, Exchange Online).', 'mlab-smtp' ),
			'bullets' => array( __( 'Kein Passwort auf der Website', 'mlab-smtp' ), __( 'Einmalig App in Microsoft anlegen', 'mlab-smtp' ) ),
		),
		'google' => array(
			'text'    => __( 'Postfach bei Google (Gmail, Workspace).', 'mlab-smtp' ),
			'bullets' => array( __( 'Anmelden mit Google-Konto', 'mlab-smtp' ), __( 'Einmalig Zugang in Google Cloud anlegen', 'mlab-smtp' ) ),
		),
		'smtp'   => array(
			'text'    => __( 'Mailserver Ihres Hosters, z. B. Infomaniak, Hostpoint, cyon.', 'mlab-smtp' ),
			'bullets' => array( __( 'Server, Benutzer und Passwort', 'mlab-smtp' ), __( 'Funktioniert mit fast jedem Anbieter', 'mlab-smtp' ) ),
		),
	);
}

/**
 * One selectable provider card (a radio button).
 *
 * @param string $key      Provider key.
 * @param array  $card     Card text and bullets.
 * @param string $selected Selected provider.
 * @param bool   $detected Whether the provider was detected from the domain.
 */
function mlab_smtp_render_provider_card( string $key, array $card, string $selected, bool $detected ): void {
	echo '<label class="mlab-prov"><input type="radio" name="provider" value="' . esc_attr( $key ) . '"' . checked( $selected, $key, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a safe attribute.
	mlab_smtp_ui_logo( $key );
	if ( $detected ) {
		echo '<span class="mlab-tag">';
		mlab_smtp_ui_pill( 'ok', __( 'Erkannt', 'mlab-smtp' ) );
		echo '</span>';
	}
	echo '<strong>' . esc_html( mlab_smtp_ui_providers()[ $key ]['name'] ) . '</strong><span class="mlab-muted mlab-small">' . esc_html( $card['text'] ) . '</span><ul>';
	foreach ( $card['bullets'] as $bullet ) {
		echo '<li>' . esc_html( $bullet ) . '</li>';
	}
	echo '</ul></label>';
}

/**
 * Step 2: connect. Guide and fields depend on the provider.
 *
 * @param string $provider Provider key.
 */
function mlab_smtp_render_step_connect( string $provider ): void {
	mlab_smtp_wizard_form_open(
		2,
		$provider,
		'mlab-connect',
		array(
			'data-mlab-fields' => '1',
			'data-group'       => $provider,
		)
	);
	$renderers = array(
		'm365'   => 'mlab_smtp_render_connect_m365',
		'google' => 'mlab_smtp_render_connect_google',
		'smtp'   => 'mlab_smtp_render_connect_smtp',
	);
	call_user_func( $renderers[ $provider ] );
	mlab_smtp_render_step_foot( mlab_smtp_wizard_url( 1, $provider ), __( 'Weiter', 'mlab-smtp' ), mlab_smtp_wizard_flag( 'verified' ) ? '' : __( 'Zuerst die Verbindung prüfen', 'mlab-smtp' ) );
	mlab_smtp_ui_form_close();
}

/**
 * Check button and result area of step 2.
 *
 * @param string $label Button label.
 */
function mlab_smtp_render_check_button( string $label ): void {
	$done = mlab_smtp_wizard_flag( 'verified' );
	echo '<div class="mlab-row"><button type="submit" name="do" value="check" class="button' . ( $done ? '' : ' button-primary' ) . '" data-mlab-check data-route="primary">' . esc_html( $label ) . '</button></div>';
	mlab_smtp_ui_result_area();
}

/**
 * Numbered guide in a collapsible block.
 *
 * @param string   $summary Summary line.
 * @param callable $steps   Prints the list items.
 * @param string   $link    URL of the provider console.
 * @param string   $button  Label of the console link.
 */
function mlab_smtp_render_guide( string $summary, callable $steps, string $link, string $button ): void {
	echo '<details class="mlab-guide" open><summary>' . esc_html( $summary ) . '</summary><ol class="mlab-todo">';
	$steps();
	echo '</ol><a class="button" href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $button ) . ' ↗</a></details>';
}

/**
 * Prints one numbered guide item.
 *
 * @param string $title Item title.
 * @param string $text  Description (plain text).
 * @param string $copy  Optional text to copy.
 * @param string $after Optional plain text after the copy snippet.
 */
function mlab_smtp_render_guide_item( string $title, string $text, string $copy = '', string $after = '' ): void {
	echo '<li><div><h3>' . esc_html( $title ) . '</h3><p class="mlab-muted mlab-small">' . esc_html( $text ) . '</p>';
	if ( '' !== $copy ) {
		mlab_smtp_ui_copy( $copy );
	}
	if ( '' !== $after ) {
		echo '<p class="mlab-muted mlab-small">' . esc_html( $after ) . '</p>';
	}
	echo '</div></li>';
}

/**
 * Step 2 for Microsoft 365.
 */
function mlab_smtp_render_connect_m365(): void {
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	echo '<h2>' . esc_html__( 'Mit Microsoft 365 verbinden', 'mlab-smtp' ) . '</h2><p class="mlab-muted">' . esc_html__( 'Einmalig in Microsoft Entra eine App anlegen. Die Website meldet sich damit an, ohne Passwort eines Mitarbeiters.', 'mlab-smtp' ) . '</p>';
	mlab_smtp_render_guide(
		__( 'Anleitung in Microsoft Entra (4 Schritte)', 'mlab-smtp' ),
		static function () use ( $host ): void {
			mlab_smtp_render_guide_item( __( 'App registrieren', 'mlab-smtp' ), __( 'Entra › App-Registrierungen › Neue Registrierung. Name:', 'mlab-smtp' ), 'Website ' . $host );
			mlab_smtp_render_guide_item( __( 'Berechtigung hinzufügen', 'mlab-smtp' ), __( 'API-Berechtigungen › Microsoft Graph › Anwendungsberechtigungen › Mail.Send, dann «Administratorzustimmung erteilen».', 'mlab-smtp' ) );
			mlab_smtp_render_guide_item( __( 'Geheimen Schlüssel erstellen', 'mlab-smtp' ), __( 'Zertifikate & Geheimnisse › Neuer geheimer Clientschlüssel. Kopieren Sie den Wert, nicht die ID. Wählen Sie eine Laufzeit von 24 Monaten und tragen Sie das Ablaufdatum unten ein.', 'mlab-smtp' ) );
			mlab_smtp_render_guide_item(
				__( 'Auf ein Postfach beschränken (empfohlen)', 'mlab-smtp' ),
				__( 'Sonst darf die App aus jedem Postfach der Firma senden. Befehl für Exchange Online PowerShell:', 'mlab-smtp' ),
				'New-ApplicationAccessPolicy -AppId <Anwendungs-ID> -PolicyScopeGroupId <Absender-Postfach> -AccessRight RestrictAccess -Description "Website ' . $host . '"',
				__( 'Ersetzen Sie die beiden Platzhalter durch die Anwendungs-ID und das Absender-Postfach.', 'mlab-smtp' )
			);
		},
		'https://entra.microsoft.com/',
		__( 'Microsoft Entra öffnen', 'mlab-smtp' )
	);
	echo '<div class="mlab-fields is-open">';
	mlab_smtp_ui_field( 'm365_tenant', __( 'Verzeichnis-ID (Mandant)', 'mlab-smtp' ), array( 'help' => __( 'Steht auf der Übersichtsseite der App.', 'mlab-smtp' ) ) );
	mlab_smtp_ui_field( 'm365_client_id', __( 'Anwendungs-ID', 'mlab-smtp' ) );
	mlab_smtp_ui_field( 'm365_client_secret', __( 'Geheimer Schlüssel (Wert)', 'mlab-smtp' ), array( 'type' => 'password' ) );
	mlab_smtp_ui_field(
		'm365_secret_expires',
		__( 'Schlüssel gültig bis', 'mlab-smtp' ),
		array(
			'type' => 'date',
			'help' => __( 'Wir erinnern Sie 30 Tage vorher.', 'mlab-smtp' ),
		)
	);
	mlab_smtp_ui_field( 'from_email', __( 'Postfach, aus dem gesendet wird', 'mlab-smtp' ), array( 'type' => 'email' ) );
	echo '</div>';
	mlab_smtp_render_check_button( __( 'Verbindung prüfen', 'mlab-smtp' ) );
}

/**
 * Step 2 for Google.
 */
function mlab_smtp_render_connect_google(): void {
	echo '<h2>' . esc_html__( 'Mit Google verbinden', 'mlab-smtp' ) . '</h2><p class="mlab-muted">' . esc_html__( 'Einmalig in der Google Cloud Console einen Zugang anlegen, danach mit dem Google-Konto anmelden.', 'mlab-smtp' ) . '</p>';
	mlab_smtp_render_guide(
		__( 'Anleitung in Google Cloud (3 Schritte)', 'mlab-smtp' ),
		static function (): void {
			mlab_smtp_render_guide_item( __( 'Gmail API aktivieren', 'mlab-smtp' ), __( 'APIs & Dienste › Bibliothek › Gmail API › Aktivieren.', 'mlab-smtp' ) );
			mlab_smtp_render_guide_item( __( 'OAuth-Client anlegen', 'mlab-smtp' ), __( 'Anmeldedaten › OAuth-Client-ID › Typ «Webanwendung». Als Weiterleitungs-URI eintragen:', 'mlab-smtp' ), mlab_smtp_google_redirect_uri() );
			mlab_smtp_render_guide_item( __( 'App veröffentlichen', 'mlab-smtp' ), __( 'OAuth-Zustimmungsbildschirm › «In Produktion». Wichtig: Im Testmodus läuft der Zugriff nach 7 Tagen ab.', 'mlab-smtp' ) );
		},
		'https://console.cloud.google.com/',
		__( 'Google Cloud öffnen', 'mlab-smtp' )
	);
	echo '<div class="mlab-fields is-open">';
	mlab_smtp_ui_field( 'google_client_id', __( 'Client-ID', 'mlab-smtp' ) );
	mlab_smtp_ui_field( 'google_client_secret', __( 'Client-Secret', 'mlab-smtp' ), array( 'type' => 'password' ) );
	echo '</div>';
	mlab_smtp_render_google_connection();
}

/**
 * Google sign-in or, when a refresh token exists, the connected account and the check button.
 */
function mlab_smtp_render_google_connection(): void {
	$account = (string) mlab_smtp_get( 'google_account' );
	if ( '' === (string) mlab_smtp_get( 'google_refresh_token' ) ) {
		echo '<div class="mlab-row"><button type="submit" name="do" value="google" class="button button-primary" data-mlab-google data-url="' . esc_url( mlab_smtp_google_connect_url() ) . '">' . esc_html__( 'Mit Google anmelden', 'mlab-smtp' ) . '</button></div>';
		mlab_smtp_ui_result_area();
		return;
	}
	echo '<div class="mlab-row">';
	/* translators: %s: Google account */
	mlab_smtp_ui_pill( 'ok', '' !== $account ? sprintf( __( 'Verbunden als %s', 'mlab-smtp' ), $account ) : __( 'Verbunden', 'mlab-smtp' ) );
	echo '<a class="button-link" href="' . esc_url( mlab_smtp_google_connect_url() ) . '">' . esc_html__( 'Anderes Konto', 'mlab-smtp' ) . '</a></div>';
	mlab_smtp_render_check_button( __( 'Verbindung prüfen', 'mlab-smtp' ) );
}

/**
 * Step 2 for an own mail server.
 */
function mlab_smtp_render_connect_smtp(): void {
	echo '<h2>' . esc_html__( 'Mit Ihrem Mailserver verbinden', 'mlab-smtp' ) . '</h2><p class="mlab-muted">' . esc_html__( 'Die Angaben finden Sie im Kundencenter Ihres Hosters, meist unter «E-Mail-Programm einrichten».', 'mlab-smtp' ) . '</p>';
	echo '<div class="mlab-fields is-open">';
	mlab_smtp_render_preset_select();
	mlab_smtp_ui_field( 'smtp_host', __( 'Server', 'mlab-smtp' ), array( 'placeholder' => 'mail.ihre-domain.ch' ) );
	mlab_smtp_ui_segmented( 'smtp_secure', __( 'Verbindung', 'mlab-smtp' ), mlab_smtp_secure_options() );
	mlab_smtp_ui_field(
		'smtp_port',
		__( 'Port', 'mlab-smtp' ),
		array(
			'type'  => 'number',
			'attrs' => array(
				'min' => '1',
				'max' => '65535',
			),
		)
	);
	mlab_smtp_ui_switch( 'smtp_auth', __( 'Der Server verlangt Benutzername und Passwort', 'mlab-smtp' ) );
	mlab_smtp_ui_field( 'smtp_user', __( 'Benutzername', 'mlab-smtp' ), array( 'help' => __( 'Meist die vollständige Mailadresse.', 'mlab-smtp' ) ) );
	mlab_smtp_ui_field( 'smtp_pass', __( 'Passwort', 'mlab-smtp' ), array( 'type' => 'password' ) );
	echo '</div>';
	mlab_smtp_render_check_button( __( 'Verbindung prüfen', 'mlab-smtp' ) );
}

/**
 * Hoster dropdown; admin.js copies the preset values into the fields. It has no name and is not saved.
 */
function mlab_smtp_render_preset_select(): void {
	$host = (string) mlab_smtp_get( 'smtp_host' );
	echo '<div class="mlab-field"><label for="mlab-smtp-preset">' . esc_html__( 'Hoster', 'mlab-smtp' ) . '</label><select id="mlab-smtp-preset" data-mlab-preset aria-describedby="mlab-smtp-preset-help">';
	echo '<option value="">' . esc_html__( 'Bitte wählen', 'mlab-smtp' ) . '</option>';
	foreach ( mlab_smtp_smtp_presets() as $key => $preset ) {
		printf(
			'<option value="%1$s" data-host="%2$s" data-port="%3$s" data-secure="%4$s"%5$s>%6$s</option>',
			esc_attr( $key ),
			esc_attr( $preset['host'] ),
			esc_attr( (string) $preset['port'] ),
			esc_attr( $preset['secure'] ),
			selected( '' !== $host && $host === $preset['host'], true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a safe attribute.
			esc_html( $preset['label'] )
		);
	}
	echo '</select><span class="description" id="mlab-smtp-preset-help">' . esc_html__( 'Füllt Server und Verbindung automatisch aus.', 'mlab-smtp' ) . '</span></div>';
}

/**
 * Step 3: sender.
 *
 * @param string $provider Provider key.
 */
function mlab_smtp_render_step_sender( string $provider ): void {
	$email = (string) mlab_smtp_get( 'from_email' );
	if ( '' === $email ) {
		$email = (string) mlab_smtp_get( 'google' === $provider ? 'google_account' : 'smtp_user' );
		$email = is_email( $email ) ? $email : '';
	}
	$name = (string) mlab_smtp_get( 'from_name' );
	$name = '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	mlab_smtp_wizard_form_open( 3, $provider, '', array( 'data-mlab-sender' => '1' ) );
	echo '<h2>' . esc_html__( 'Absender', 'mlab-smtp' ) . '</h2><p class="mlab-muted">' . esc_html__( 'So erscheinen Mails der Website im Posteingang der Empfänger.', 'mlab-smtp' ) . '</p><div class="mlab-fields is-open">';
	mlab_smtp_render_sender_fields( $name, $email, 'smtp' !== $provider );
	echo '</div><div class="mlab-preview"><p class="mlab-muted mlab-small">' . esc_html__( 'Vorschau', 'mlab-smtp' ) . '</p><strong data-mlab-preview="from_name">' . esc_html( $name ) . '</strong> <span class="mlab-muted">&lt;<span data-mlab-preview="from_email">' . esc_html( $email ) . '</span>&gt;</span></div>';
	mlab_smtp_render_step_foot( mlab_smtp_wizard_url( 2, $provider ), __( 'Weiter', 'mlab-smtp' ) );
	mlab_smtp_ui_form_close();
}

/**
 * Name, address and reply-to fields of wizard step 3.
 *
 * @param string $name  Prefilled name.
 * @param string $email Prefilled address.
 * @param bool   $fixed Whether the address is fixed by the connected mailbox.
 */
function mlab_smtp_render_sender_fields( string $name, string $email, bool $fixed ): void {
	mlab_smtp_ui_field(
		'from_name',
		__( 'Absender-Name', 'mlab-smtp' ),
		array(
			'value' => $name,
			'help'  => __( 'Übernommen aus dem Namen der Website.', 'mlab-smtp' ),
		)
	);
	mlab_smtp_ui_field(
		'from_email',
		__( 'Absender-Adresse', 'mlab-smtp' ),
		array(
			'type'     => 'email',
			'value'    => $email,
			'readonly' => $fixed,
			'help'     => $fixed ? __( 'Das verbundene Postfach. Der Anbieter erlaubt nur dieses oder einen freigegebenen Alias.', 'mlab-smtp' ) : __( 'Sollte zur Domain des Servers passen, sonst landen Mails im Spam.', 'mlab-smtp' ),
		)
	);
	mlab_smtp_ui_field(
		'reply_to',
		__( 'Antworten gehen an (optional)', 'mlab-smtp' ),
		array(
			'type'        => 'email',
			'placeholder' => __( 'z. B. info@ihre-domain.ch', 'mlab-smtp' ),
			'help'        => __( 'Nützlich, wenn das Absender-Postfach niemand liest.', 'mlab-smtp' ),
		)
	);
}

/**
 * Step 4: test mail. Finishing needs a successful one.
 *
 * @param string $provider Provider key.
 */
function mlab_smtp_render_step_test( string $provider ): void {
	mlab_smtp_wizard_form_open( 4, $provider, '', array( 'data-mlab-test-form' => '1' ) );
	echo '<h2>' . esc_html__( 'Testmail senden', 'mlab-smtp' ) . '</h2><p class="mlab-muted">' . esc_html__( 'Erst wenn diese Mail ankommt, ist alles richtig eingerichtet.', 'mlab-smtp' ) . '</p>';
	echo '<div class="mlab-fields is-open"><div class="mlab-field"><label for="mlab-wizard-to">' . esc_html__( 'An', 'mlab-smtp' ) . '</label><input type="email" id="mlab-wizard-to" name="to" required value="' . esc_attr( wp_get_current_user()->user_email ) . '"></div></div>';
	echo '<div class="mlab-row"><button type="submit" name="do" value="test" class="button button-primary" data-mlab-test>' . esc_html__( 'Testmail senden', 'mlab-smtp' ) . '</button></div>';
	mlab_smtp_ui_result_area();
	mlab_smtp_render_step_foot( mlab_smtp_wizard_url( 3, $provider ), __( 'Einrichtung abschliessen', 'mlab-smtp' ), mlab_smtp_wizard_flag( 'tested' ) ? '' : __( 'Zuerst eine Testmail senden', 'mlab-smtp' ), 'finish', 'data-mlab-finish' );
	mlab_smtp_ui_form_close();
}
