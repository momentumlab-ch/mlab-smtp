<?php
/**
 * Geführter Ablauf für den Ersatzweg: sieben Schritte, je eine eigene Adresse (view=fallback&step=N).
 *
 * Jeder Schritt funktioniert ohne JavaScript. Jede Änderung läuft über ein POST mit Nonce und Berechtigung
 * (mlab_smtp_guard). Die Testmail geht ausschliesslich über den Ersatzweg.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_RESEND_KEYS = 'https://resend.com/api-keys';

add_action( 'admin_post_mlab_smtp_fallback', 'mlab_smtp_handle_fallback' );

/**
 * Labels of the seven steps.
 *
 * @return string[] Step number => label.
 */
function mlab_smtp_fallback_steps(): array {
	return array(
		1 => __( 'Anbieter', 'mlab-smtp' ),
		2 => __( 'Voraussetzungen', 'mlab-smtp' ),
		3 => __( 'Zugangsdaten', 'mlab-smtp' ),
		4 => __( 'Absender', 'mlab-smtp' ),
		5 => __( 'Domain', 'mlab-smtp' ),
		6 => __( 'Testmail', 'mlab-smtp' ),
		7 => __( 'Zusammenfassung', 'mlab-smtp' ),
	);
}

/**
 * URL of a step.
 *
 * @param int $step Step 1 to 7.
 */
function mlab_smtp_fallback_url( int $step = 1 ): string {
	return mlab_smtp_page_url(
		array(
			'view' => 'fallback',
			'step' => max( 1, min( 7, $step ) ),
		)
	);
}

/**
 * Provider of the fallback that the flow works on: the stored one, as far as it can be a fallback here.
 */
function mlab_smtp_fallback_provider(): string {
	$stored = (string) mlab_smtp_get( 'fb_provider' );
	return isset( mlab_smtp_fallback_options()[ $stored ] ) ? $stored : 'smtp';
}

/**
 * What the fallback key of Resend may do: full, sending (restricted to sending mails), error or none.
 */
function mlab_smtp_fallback_resend_scope(): string {
	if ( '' === (string) mlab_smtp_get( 'fb_resend_api_key' ) ) {
		return 'none';
	}
	$info = mlab_smtp_with_resend_key( 'fb_resend_api_key', 'mlab_smtp_resend_domains' );
	return is_wp_error( $info ) ? 'error' : (string) $info['scope'];
}

/**
 * Explanation of a key that may only send mails.
 */
function mlab_smtp_fallback_sending_text(): string {
	return __( 'Der Key darf nur Mails senden («Sending access»). Das ist die sicherste Einstellung. Weil er die Domain-Liste nicht lesen darf, kann das Plugin den Domain-Status nicht anzeigen. Ob die Domain verifiziert ist, zeigt die Testmail.', 'mlab-smtp' );
}

/**
 * Page of the flow: heading, stepper, current step.
 */
function mlab_smtp_render_fallback_flow(): void {
	$step  = max( 1, min( 7, (int) mlab_smtp_query( 'step', '1' ) ) );
	$views = array(
		1 => 'mlab_smtp_render_fb_provider',
		2 => 'mlab_smtp_render_fb_prerequisites',
		3 => 'mlab_smtp_render_fb_credentials',
		4 => 'mlab_smtp_render_fb_sender',
		5 => 'mlab_smtp_render_fb_domain',
		6 => 'mlab_smtp_render_fb_test',
		7 => 'mlab_smtp_render_fb_summary',
	);
	echo '<hr class="wp-header-end">';
	mlab_smtp_render_flash();
	echo '<div class="mlab-wizard mlab-fbflow"><div class="mlab-card mlab-wiz"><div class="mlab-wiz-top"><span class="mlab-caption">' . esc_html__( 'Ersatzweg', 'mlab-smtp' ) . '</span>';
	echo '<a class="mlab-close" href="' . esc_url( mlab_smtp_page_url( array( 'view' => 'settings' ) ) ) . '" aria-label="' . esc_attr__( 'Schliessen', 'mlab-smtp' ) . '">' . mlab_smtp_ui_svg( 'close' ) . '</a></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
	echo '<h1>' . esc_html__( 'Ersatzweg einrichten', 'mlab-smtp' ) . '</h1>';
	mlab_smtp_render_fb_stepper( $step );
	if ( $step > 3 && ! mlab_smtp_fallback_filled() ) {
		mlab_smtp_ui_hint( 'warn', __( 'Zuerst die Zugangsdaten eintragen', 'mlab-smtp' ), __( 'Der Ersatzweg ist noch nicht eingetragen. Bitte zuerst Schritt 3 abschliessen.', 'mlab-smtp' ) );
		echo '<div class="mlab-foot"><a class="button button-primary" href="' . esc_url( mlab_smtp_fallback_url( 3 ) ) . '">' . esc_html__( 'Zu Schritt 3', 'mlab-smtp' ) . '</a></div>';
	} else {
		call_user_func( $views[ $step ] );
	}
	echo '</div></div>';
}

/**
 * Step indicator of the seven steps (same markup and classes as the setup wizard).
 *
 * @param int $step Current step.
 */
function mlab_smtp_render_fb_stepper( int $step ): void {
	$labels = mlab_smtp_fallback_steps();
	$tested = mlab_smtp_route_state( 'fallback' )['getestet'];
	/* translators: 1: current step, 2: number of steps */
	echo '<p class="mlab-stepcount">' . esc_html( sprintf( __( 'Schritt %1$d von %2$d: %3$s', 'mlab-smtp' ), $step, count( $labels ), $labels[ $step ] ) ) . '</p>';
	echo '<ol class="mlab-stepper">';
	foreach ( $labels as $number => $label ) {
		$class = $number < $step ? 'is-done' : ( $number === $step ? 'is-now' : '' );
		if ( 6 === $number && $step > 6 && ! $tested ) {
			$class = '';
		}
		echo '<li class="' . esc_attr( $class ) . '"' . ( $number === $step ? ' aria-current="step"' : '' ) . '><span class="mlab-step-label">' . esc_html( $label ) . '</span></li>';
	}
	echo '</ol>';
}

/**
 * Opens the form of a step: nonce, action, step number.
 *
 * @param int $step Step number.
 */
function mlab_smtp_fb_form_open( int $step ): void {
	mlab_smtp_ui_form_open( 'mlab_smtp_fallback' );
	echo '<input type="hidden" name="step" value="' . esc_attr( (string) $step ) . '">';
}

/**
 * Back and forward links of a step without a form.
 *
 * @param int  $step Current step.
 * @param bool $next False hides the forward link.
 */
function mlab_smtp_render_fb_nav( int $step, bool $next = true ): void {
	echo '<div class="mlab-foot"><a class="button button-link" href="' . esc_url( mlab_smtp_fallback_url( $step - 1 ) ) . '">' . esc_html__( 'Zurück', 'mlab-smtp' ) . '</a>';
	if ( $next ) {
		echo '<a class="button button-primary" href="' . esc_url( mlab_smtp_fallback_url( $step + 1 ) ) . '">' . esc_html__( 'Weiter', 'mlab-smtp' ) . mlab_smtp_ui_svg( 'arrow' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
	}
	echo '</div>';
}

/**
 * Step 1: provider. Resend is not offered when it is the main route; Microsoft 365 and Google are no fallback.
 */
function mlab_smtp_render_fb_provider(): void {
	$selected = mlab_smtp_fallback_provider();
	echo '<h2>' . esc_html__( 'Worüber soll der Ersatzweg senden?', 'mlab-smtp' ) . '</h2><p class="mlab-muted">' . esc_html__( 'Der Ersatzweg springt ein, wenn der Hauptweg ausfällt oder eine Mail ablehnt. Er sollte darum unabhängig vom Hauptweg sein.', 'mlab-smtp' ) . '</p>';
	mlab_smtp_fb_form_open( 1 );
	echo '<fieldset class="mlab-providers"><legend class="screen-reader-text">' . esc_html__( 'Anbieter des Ersatzwegs', 'mlab-smtp' ) . '</legend>';
	foreach ( mlab_smtp_fallback_options() as $key => $name ) {
		mlab_smtp_render_provider_card( $key, mlab_smtp_ui_providers()[ $key ], $selected, false );
	}
	echo '</fieldset>';
	if ( 'resend' === (string) mlab_smtp_get( 'provider' ) ) {
		echo '<p class="mlab-muted mlab-small">' . esc_html__( 'Resend ist bereits Ihr Hauptweg und steht darum hier nicht zur Wahl.', 'mlab-smtp' ) . '</p>';
	}
	echo '<div class="mlab-foot"><a class="button button-link" href="' . esc_url( mlab_smtp_page_url( array( 'view' => 'settings' ) ) ) . '">' . esc_html__( 'Abbrechen', 'mlab-smtp' ) . '</a><button type="submit" class="button button-primary">' . esc_html__( 'Weiter', 'mlab-smtp' ) . mlab_smtp_ui_svg( 'arrow' ) . '</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
	mlab_smtp_ui_form_close();
}

/**
 * Step 2: what you need beforehand, with the matching links.
 */
function mlab_smtp_render_fb_prerequisites(): void {
	echo '<h2>' . esc_html__( 'Das brauchen Sie vorab', 'mlab-smtp' ) . '</h2>';
	if ( 'resend' === mlab_smtp_fallback_provider() ) {
		echo '<p class="mlab-muted">' . esc_html__( 'Für Resend brauchen Sie ein Konto, eine bestätigte Domain und einen eigenen API-Key für den Ersatzweg. Verwenden Sie nicht den Key des Hauptwegs: Fällt der Hauptweg aus, soll der Ersatzweg unabhängig davon funktionieren.', 'mlab-smtp' ) . '</p>';
		mlab_smtp_render_guide(
			__( 'So gehen Sie bei Resend vor', 'mlab-smtp' ),
			static function (): void {
				mlab_smtp_render_guide_item( __( 'Domain bestätigen', 'mlab-smtp' ), __( 'Öffnen Sie bei Resend «Domains», legen Sie die Domain Ihrer Absender-Adresse an und tragen Sie die DNS-Einträge bei Ihrem Domain-Anbieter ein.', 'mlab-smtp' ), '', '', MLAB_SMTP_RESEND_DOMAINS, __( 'Domains bei Resend öffnen', 'mlab-smtp' ) );
				mlab_smtp_render_guide_item( __( 'API-Key erstellen', 'mlab-smtp' ), __( 'Öffnen Sie «API Keys» und erstellen Sie einen neuen Key nur für den Ersatzweg. Mit der Berechtigung «Sending access» genügt er zum Senden und ist am sichersten. «Full access» zeigt zusätzlich den Domain-Status an.', 'mlab-smtp' ), '', '', MLAB_SMTP_RESEND_KEYS, __( 'API-Keys bei Resend öffnen', 'mlab-smtp' ) );
			},
			'',
			''
		);
	} else {
		echo '<p class="mlab-muted">' . esc_html__( 'Für den eigenen Mailserver brauchen Sie die Mail-Zugangsdaten Ihres Hosters: Server, Port, Benutzername und Passwort. Sie stehen im Kundencenter Ihres Hosters, meist unter «E-Mail-Programm einrichten». Am besten ist ein Postfach bei einem anderen Anbieter als der Hauptweg.', 'mlab-smtp' ) . '</p>';
	}
	mlab_smtp_render_fb_nav( 2 );
}

/**
 * Step 3: credentials and connection check.
 */
function mlab_smtp_render_fb_credentials(): void {
	$provider = mlab_smtp_fallback_provider();
	echo '<h2>' . esc_html__( 'Zugangsdaten eintragen und prüfen', 'mlab-smtp' ) . '</h2>';
	mlab_smtp_fb_form_open( 3 );
	echo '<div class="mlab-fields is-open">';
	if ( 'resend' === $provider ) {
		mlab_smtp_render_fallback_resend();
		mlab_smtp_ui_hint( 'info', __( 'Eigener Key für den Ersatzweg', 'mlab-smtp' ), __( 'Tragen Sie hier einen eigenen Key ein, nicht den des Hauptwegs. «Sending access» wird unterstützt.', 'mlab-smtp' ) );
	} else {
		mlab_smtp_render_fallback_smtp();
	}
	echo '</div>';
	$state = mlab_smtp_route_state( 'fallback' );
	mlab_smtp_ui_hint( $state['level'], $state['label'], $state['text'] );
	echo '<div class="mlab-row"><button type="submit" name="do" value="check" class="button button-primary">' . mlab_smtp_ui_svg( 'refresh' ) . esc_html__( 'Speichern und Verbindung prüfen', 'mlab-smtp' ) . '</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
	echo '<div class="mlab-foot"><a class="button button-link" href="' . esc_url( mlab_smtp_fallback_url( 2 ) ) . '">' . esc_html__( 'Zurück', 'mlab-smtp' ) . '</a><button type="submit" name="do" value="next" class="button"' . ( $state['geprueft'] ? '' : ' disabled title="' . esc_attr__( 'Zuerst die Verbindung prüfen', 'mlab-smtp' ) . '"' ) . '>' . esc_html__( 'Weiter', 'mlab-smtp' ) . '</button></div>';
	mlab_smtp_ui_form_close();
}

/**
 * Step 4: the sender the fallback really uses.
 */
function mlab_smtp_render_fb_sender(): void {
	$email = (string) mlab_smtp_get( 'from_email' );
	$name  = (string) mlab_smtp_get( 'from_name' );
	echo '<h2>' . esc_html__( 'Absender des Ersatzwegs', 'mlab-smtp' ) . '</h2>';
	echo '<p class="mlab-muted">' . esc_html__( 'Der Ersatzweg sendet immer mit der gespeicherten Absender-Adresse, nicht mit der Adresse, die ein anderes Plugin für eine Mail setzt. Nur so passen Absender und Domain zusammen.', 'mlab-smtp' ) . '</p>';
	$settings = mlab_smtp_page_url( array( 'view' => 'settings' ) );
	if ( ! is_email( $email ) ) {
		mlab_smtp_ui_hint( 'bad', __( 'Keine Absender-Adresse gespeichert', 'mlab-smtp' ), __( 'Ohne Absender-Adresse kann der Ersatzweg keine Mail senden. Tragen Sie sie unter «Einstellungen» ein.', 'mlab-smtp' ) );
		echo '<p><a class="button" href="' . esc_url( $settings ) . '">' . esc_html__( 'Zu den Einstellungen', 'mlab-smtp' ) . '</a></p>';
		mlab_smtp_render_fb_nav( 4, false );
		return;
	}
	echo '<dl class="mlab-facts"><dt>' . esc_html__( 'Absender-Adresse', 'mlab-smtp' ) . '</dt><dd>' . esc_html( '' !== $name ? $name . ' <' . $email . '>' : $email ) . '</dd>';
	echo '<dt>' . esc_html__( 'Domain', 'mlab-smtp' ) . '</dt><dd>' . esc_html( mlab_smtp_domain_of( $email ) ) . '</dd></dl>';
	echo '<p class="mlab-muted">' . esc_html__( 'Ändern Sie die Adresse, muss der Ersatzweg danach erneut geprüft und getestet werden.', 'mlab-smtp' ) . ' <a href="' . esc_url( $settings ) . '">' . esc_html__( 'Absender ändern', 'mlab-smtp' ) . '</a></p>';
	mlab_smtp_render_fb_nav( 4 );
}

/**
 * Step 5: domain status where the provider allows it.
 */
function mlab_smtp_render_fb_domain(): void {
	echo '<h2>' . esc_html__( 'Status der Domain', 'mlab-smtp' ) . '</h2>';
	if ( 'resend' !== mlab_smtp_fallback_provider() ) {
		mlab_smtp_ui_hint( 'info', __( 'Der Mailserver entscheidet', 'mlab-smtp' ), __( 'Beim eigenen Mailserver gibt es keinen Domain-Status, den das Plugin abfragen kann. Der Server entscheidet, ob er die Absender-Adresse annimmt. Die Testmail im nächsten Schritt zeigt es.', 'mlab-smtp' ) );
	} elseif ( 'sending' === mlab_smtp_fallback_resend_scope() ) {
		mlab_smtp_ui_hint( 'info', __( 'Domain-Status nicht lesbar', 'mlab-smtp' ), mlab_smtp_fallback_sending_text() );
	} else {
		mlab_smtp_render_fb_domain_state();
	}
	mlab_smtp_render_fb_nav( 5 );
}

/**
 * Domain status of Resend, asked with the key of the fallback (never the main key).
 */
function mlab_smtp_render_fb_domain_state(): void {
	$state = mlab_smtp_with_resend_key(
		'fb_resend_api_key',
		static function (): array {
			return mlab_smtp_resend_domain_state( mlab_smtp_resend_sender_domain(), false, true );
		}
	);
	$text  = mlab_smtp_resend_state_text( $state );
	if ( '' === $text['message'] ) {
		mlab_smtp_ui_hint( 'info', $text['title'], __( 'Es gibt keinen Domain-Status. Prüfen Sie die Absender-Adresse.', 'mlab-smtp' ) );
		return;
	}
	mlab_smtp_ui_hint( $text['level'], $text['title'], $text['message'] );
	if ( $state['records'] ) {
		mlab_smtp_render_resend_records( $state['records'] );
	}
	if ( '' !== $state['error'] ) {
		mlab_smtp_ui_technical( mlab_smtp_redact( $state['error'] ) );
	}
	if ( in_array( $state['state'], array( 'missing', 'pending', 'failed', 'partial', 'error' ), true ) ) {
		mlab_smtp_fb_form_open( 5 );
		echo '<div class="mlab-row"><button type="submit" name="do" value="recheck" class="button">' . esc_html__( 'Nochmals prüfen', 'mlab-smtp' ) . '</button><a class="button button-link" href="' . esc_url( MLAB_SMTP_RESEND_DOMAINS ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Domains bei Resend öffnen', 'mlab-smtp' ) . mlab_smtp_ui_svg( 'external' ) . '</a></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
		mlab_smtp_ui_form_close();
	}
}

/**
 * Step 6: test mail through the fallback only.
 */
function mlab_smtp_render_fb_test(): void {
	$state = mlab_smtp_route_state( 'fallback' );
	echo '<h2>' . esc_html__( 'Testmail über den Ersatzweg', 'mlab-smtp' ) . '</h2><p class="mlab-muted">' . esc_html__( 'Diese Testmail geht ausschliesslich über den Ersatzweg. Der Hauptweg wird dabei nicht verwendet. Kommt sie an, ist der Ersatzweg getestet.', 'mlab-smtp' ) . '</p>';
	mlab_smtp_fb_form_open( 6 );
	echo '<div class="mlab-fields is-open"><div class="mlab-field"><label for="mlab-fb-to">' . esc_html__( 'An', 'mlab-smtp' ) . '</label><input type="email" id="mlab-fb-to" name="to" required value="' . esc_attr( wp_get_current_user()->user_email ) . '"></div></div>';
	echo '<div class="mlab-row"><button type="submit" name="do" value="test" class="button button-primary">' . esc_html__( 'Testmail über den Ersatzweg senden', 'mlab-smtp' ) . '</button></div>';
	mlab_smtp_ui_form_close();
	if ( $state['tested_at'] ) {
		/* translators: 1: time, 2: masked recipient */
		echo '<p class="mlab-muted mlab-small">' . esc_html( sprintf( __( 'Letzte erfolgreiche Testmail: %1$s an %2$s.', 'mlab-smtp' ), mlab_smtp_ui_time( $state['tested_at'] ), $state['tested_to'] ) ) . '</p>';
	}
	mlab_smtp_render_fb_nav( 6, $state['getestet'] && ! $state['veraltet'] );
}

/**
 * Four states of a route, each with icon and text (never colour alone).
 *
 * @param array $state Result of mlab_smtp_route_state().
 */
function mlab_smtp_render_route_states( array $state ): void {
	$items = array(
		array( __( 'Eingetragen', 'mlab-smtp' ), $state['eingetragen'], false ),
		array( __( 'Verbindung geprüft', 'mlab-smtp' ), $state['geprueft'], false ),
		array( __( 'Testmail erfolgreich', 'mlab-smtp' ), $state['getestet'], false ),
		array( __( 'Erneute Prüfung nötig', 'mlab-smtp' ), $state['veraltet'], true ),
	);
	echo '<ul class="mlab-route-states">';
	foreach ( $items as $item ) {
		list( $label, $on, $warn ) = $item;
		$level                     = $warn ? ( $on ? 'warn' : 'ok' ) : ( $on ? 'ok' : 'info' );
		$word                      = $on ? __( 'Ja', 'mlab-smtp' ) : __( 'Nein', 'mlab-smtp' );
		echo '<li><span class="mlab-ic is-' . esc_attr( $level ) . '" aria-hidden="true">' . esc_html( mlab_smtp_ui_icon( $level ) ) . '</span><span>' . esc_html( $label ) . ': <strong>' . esc_html( $word ) . '</strong></span></li>';
	}
	echo '</ul>';
}

/**
 * Step 7: summary with the four states and the button that switches the fallback on.
 */
function mlab_smtp_render_fb_summary(): void {
	$state   = mlab_smtp_route_state( 'fallback' );
	$answer  = mlab_smtp_fallback_tested_answer();
	$enabled = (bool) mlab_smtp_get( 'fallback_enabled' );
	echo '<h2>' . esc_html__( 'Zusammenfassung', 'mlab-smtp' ) . '</h2>';
	mlab_smtp_ui_hint( $answer['level'], $answer['question'] . ' ' . $answer['label'], $answer['text'] );
	mlab_smtp_render_route_states( $state );
	echo '<p class="mlab-muted">' . esc_html( $enabled ? __( 'Der Ersatzweg ist eingeschaltet.', 'mlab-smtp' ) : __( 'Der Ersatzweg ist noch ausgeschaltet.', 'mlab-smtp' ) ) . '</p>';
	mlab_smtp_fb_form_open( 7 );
	$ready = $state['getestet'] && ! $state['veraltet'];
	if ( ! $enabled && ! $ready ) {
		echo '<p class="mlab-muted">' . esc_html__( 'Zuerst eine Testmail über den Ersatzweg senden', 'mlab-smtp' ) . '</p>';
	}
	echo '<div class="mlab-foot"><a class="button button-link" href="' . esc_url( mlab_smtp_fallback_url( 6 ) ) . '">' . esc_html__( 'Zurück', 'mlab-smtp' ) . '</a>';
	if ( $enabled ) {
		echo '<a class="button button-primary" href="' . esc_url( mlab_smtp_page_url( array( 'view' => 'settings' ) ) ) . '">' . esc_html__( 'Fertig', 'mlab-smtp' ) . '</a>';
	} elseif ( $ready ) {
		echo '<button type="submit" name="do" value="activate" class="button button-primary">' . esc_html__( 'Ersatzweg aktivieren', 'mlab-smtp' ) . '</button>';
	} else {
		echo '<a class="button button-link" href="' . esc_url( mlab_smtp_page_url( array( 'view' => 'settings' ) ) ) . '">' . esc_html__( 'Zu den Einstellungen', 'mlab-smtp' ) . '</a>';
		echo '<a class="button button-primary" href="' . esc_url( mlab_smtp_fallback_url( 6 ) ) . '">' . esc_html__( 'Testmail über den Ersatzweg senden', 'mlab-smtp' ) . '</a>';
	}
	echo '</div>';
	mlab_smtp_ui_form_close();
}

/**
 * Admin-post handler of every step: capability and nonce first, then the step.
 */
function mlab_smtp_handle_fallback(): void {
	mlab_smtp_guard( 'mlab_smtp_fallback' );
	$input  = mlab_smtp_post_input();
	$step   = max( 1, min( 7, (int) ( $input['step'] ?? 1 ) ) );
	$action = (string) ( $input['do'] ?? 'next' );
	$next   = (int) call_user_func(
		array(
			1 => 'mlab_smtp_fallback_post_provider',
			3 => 'mlab_smtp_fallback_post_credentials',
			5 => 'mlab_smtp_fallback_post_domain',
			6 => 'mlab_smtp_fallback_post_test',
			7 => 'mlab_smtp_fallback_post_activate',
		)[ $step ] ?? 'mlab_smtp_fallback_post_none',
		$input,
		$action
	);
	wp_safe_redirect( mlab_smtp_fallback_url( $next > 0 ? $next : $step ) );
	exit;
}

/**
 * Steps without a form.
 *
 * @return int Step 1.
 */
function mlab_smtp_fallback_post_none(): int {
	return 1;
}

/**
 * Step 1: store the provider of the fallback. The fallback stays as switched as it was.
 *
 * @param array $input Form data.
 * @return int Next step.
 */
function mlab_smtp_fallback_post_provider( array $input ): int {
	$choice = mlab_smtp_pick( $input['provider'] ?? '', array_keys( mlab_smtp_fallback_options() ), '' );
	if ( '' === $choice ) {
		mlab_smtp_flash( 'error', __( 'Dieser Anbieter ist als Ersatzweg nicht möglich. Bitte wählen Sie einen eigenen Mailserver oder Resend.', 'mlab-smtp' ) );
		return 1;
	}
	if ( (string) mlab_smtp_get( 'fb_provider' ) !== $choice ) {
		mlab_smtp_store( array( 'fb_provider' => $choice ) );
	}
	return 2;
}

/**
 * Keys that step 3 saves for a provider.
 *
 * @param string $provider smtp or resend.
 * @return string[]
 */
function mlab_smtp_fallback_credential_keys( string $provider ): array {
	return 'resend' === $provider
		? array( 'fb_resend_api_key' )
		: array( 'fb_smtp_host', 'fb_smtp_port', 'fb_smtp_secure', 'fb_smtp_auth', 'fb_smtp_user', 'fb_smtp_pass' );
}

/**
 * Step 3: save the credentials, then check or continue.
 *
 * @param array  $input  Form data.
 * @param string $action check or next.
 * @return int Next step.
 */
function mlab_smtp_fallback_post_credentials( array $input, string $action ): int {
	$errors = mlab_smtp_save_fields( $input, mlab_smtp_fallback_credential_keys( mlab_smtp_fallback_provider() ) );
	if ( $errors ) {
		mlab_smtp_flash( 'error', implode( ' ', $errors ) );
		return 3;
	}
	if ( 'check' === $action ) {
		mlab_smtp_flash_fallback_check();
		return 3;
	}
	if ( mlab_smtp_route_state( 'fallback' )['geprueft'] ) {
		return 4;
	}
	mlab_smtp_flash( 'error', __( 'Bitte prüfen Sie zuerst die Verbindung des Ersatzwegs.', 'mlab-smtp' ) );
	return 3;
}

/**
 * Checks the fallback connection and leaves the result as a notice. A key that may only send gets its explanation.
 */
function mlab_smtp_flash_fallback_check(): void {
	$result = mlab_smtp_run_check( 'fallback' );
	if ( ! $result['ok'] ) {
		mlab_smtp_flash( 'error', $result['message'], $result['detail'] );
		return;
	}
	$extra = 'resend' === mlab_smtp_fallback_provider() && 'sending' === mlab_smtp_fallback_resend_scope() ? ' ' . mlab_smtp_fallback_sending_text() : '';
	mlab_smtp_flash( 'success', $result['message'] . $extra );
}

/**
 * Step 5: ask Resend again with the key of the fallback.
 *
 * @param array  $input  Form data.
 * @param string $action recheck.
 * @return int Next step.
 */
function mlab_smtp_fallback_post_domain( array $input, string $action ): int {
	if ( 'recheck' !== $action || 'resend' !== mlab_smtp_fallback_provider() ) {
		return 6;
	}
	mlab_smtp_with_resend_key(
		'fb_resend_api_key',
		static function (): void {
			$state = mlab_smtp_resend_domain_state( mlab_smtp_resend_sender_domain(), true );
			if ( '' !== $state['id'] && 'verified' !== $state['state'] ) {
				mlab_smtp_resend_verify( $state['id'] );
			}
		}
	);
	mlab_smtp_flash( 'success', __( 'Resend prüft die DNS-Einträge jetzt. Das dauert meist wenige Minuten. Laden Sie die Seite danach neu oder wählen Sie «Nochmals prüfen» erneut.', 'mlab-smtp' ) );
	return 5;
}

/**
 * Step 6: test mail through the fallback only.
 *
 * @param array $input Form data.
 * @return int Next step.
 */
function mlab_smtp_fallback_post_test( array $input ): int {
	$to = sanitize_email( (string) ( $input['to'] ?? '' ) );
	if ( '' === $to ) {
		mlab_smtp_flash( 'error', __( 'Bitte geben Sie eine gültige E-Mail-Adresse ein.', 'mlab-smtp' ) );
		return 6;
	}
	$result = mlab_smtp_run_fallback_test( $to );
	if ( $result['sent'] ) {
		/* translators: %s: recipient address */
		mlab_smtp_flash( 'success', sprintf( __( 'Testmail an %s über den Ersatzweg versendet. Bitte prüfen Sie den Posteingang, auch den Spam-Ordner.', 'mlab-smtp' ), $to ) );
		return 7;
	}
	mlab_smtp_flash( 'error', '' !== $result['hint'] ? $result['hint'] : $result['error'], $result['error'] . "\n" . $result['debug'] );
	return 6;
}

/**
 * Step 7: switch the fallback on, but only when it is tested and nothing changed since.
 *
 * @return int Next step.
 */
function mlab_smtp_fallback_post_activate(): int {
	$state = mlab_smtp_route_state( 'fallback' );
	if ( ! $state['getestet'] || $state['veraltet'] ) {
		mlab_smtp_flash( 'error', __( 'Der Ersatzweg lässt sich erst einschalten, wenn eine aktuelle Testmail vom Ersatzweg angenommen wurde.', 'mlab-smtp' ) );
		return 7;
	}
	mlab_smtp_store( array( 'fallback_enabled' => 1 ) );
	mlab_smtp_flash( 'success', __( 'Der Ersatzweg ist eingeschaltet. Fällt der Hauptweg aus, stellt er die Mails zu.', 'mlab-smtp' ) );
	return 7;
}
