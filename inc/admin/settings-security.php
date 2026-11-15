<?php
/**
 * Settings sections for staging protection, alerting, support and privacy, and the encryption status.
 *
 * The settings page is one form. Buttons that start their own action (confirm, test alert, download, re-encrypt)
 * belong to small forms printed after it and point to them with the form attribute, so no JavaScript is needed.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hidden single-button forms of the settings page (printed after the main form).
 */
function mlab_smtp_render_set_action_forms(): void {
	foreach ( mlab_smtp_set_actions() as $id => $action ) {
		echo '<form method="post" id="' . esc_attr( 'mlab-act-' . $id ) . '" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" hidden>';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
		echo '</form>';
	}
}

/**
 * Form id => admin-post action (also the nonce action).
 *
 * @return array<string,string>
 */
function mlab_smtp_set_actions(): array {
	return array(
		'staging-confirm' => 'mlab_smtp_staging_confirm',
		'alert-test'      => 'mlab_smtp_alert_test',
		'support-report'  => 'mlab_smtp_support_report',
		'reencrypt'       => 'mlab_smtp_reencrypt',
	);
}

/**
 * Button that submits one of the forms from mlab_smtp_set_actions().
 *
 * @param string $form    Form id key.
 * @param string $label   Button text.
 * @param bool   $primary Primary style.
 * @param bool   $disabled Disabled.
 */
function mlab_smtp_set_action_button( string $form, string $label, bool $primary = false, bool $disabled = false ): void {
	echo '<button type="submit" form="' . esc_attr( 'mlab-act-' . $form ) . '" class="button' . ( $primary ? ' button-primary' : '' ) . '"' . ( $disabled ? ' disabled' : '' ) . '>' . esc_html( $label ) . '</button>';
}

/**
 * Saves the staging and alert fields of the settings form. Called by the settings save handler.
 *
 * @param array $input Raw unslashed POST data.
 * @return string[] Error messages.
 */
function mlab_smtp_save_security_forms( array $input ): array {
	$errors = array();
	if ( isset( $input['staging_form'] ) ) {
		$mode   = isset( $input['staging_mode'] ) ? (string) $input['staging_mode'] : (string) mlab_smtp_staging_get()['mode'];
		$errors = array_merge(
			$errors,
			mlab_smtp_staging_save(
				array(
					'mode'       => $mode,
					'test_email' => (string) ( $input['staging_test_email'] ?? '' ),
				)
			)
		);
	}
	if ( isset( $input['alert_form'] ) ) {
		$errors = array_merge(
			$errors,
			mlab_smtp_alerts_save(
				array(
					'url'          => (string) ( $input['alert_url'] ?? '' ),
					'secret'       => (string) ( $input['alert_secret'] ?? '' ),
					'clear_secret' => ! empty( $input['alert_clear_secret'] ),
					'events'       => is_array( $input['alert_events'] ?? null ) ? array_map( 'boolval', $input['alert_events'] ) : array(),
				)
			)
		);
	}
	return $errors;
}

/**
 * Staging section.
 */
function mlab_smtp_render_set_staging(): void {
	$status = mlab_smtp_staging_status();
	mlab_smtp_ui_row_open( __( 'Schutz vor ungewollten Mails (Staging)', 'mlab-smtp' ), __( 'Verhindert, dass eine Kopie der Website (Test- oder Entwicklungsumgebung) echten Kunden Mails schickt.', 'mlab-smtp' ) );
	$states = array(
		'off'      => array( 'ok', __( 'Versand frei', 'mlab-smtp' ) ),
		'block'    => array( 'bad', __( 'Versand blockiert', 'mlab-smtp' ) ),
		'redirect' => array( 'warn', __( 'Mails werden umgeleitet', 'mlab-smtp' ) ),
	);
	mlab_smtp_ui_hint( $states[ $status['effective'] ][0], $states[ $status['effective'] ][1], $status['text'] );
	if ( '' !== $status['warning'] ) {
		mlab_smtp_ui_hint( 'warn', __( 'Hinweis', 'mlab-smtp' ), $status['warning'] );
	}
	echo '<input type="hidden" name="staging_form" value="1">';
	mlab_smtp_render_staging_fields( $status );
	mlab_smtp_render_staging_help();
	if ( 'off' !== $status['effective'] && 'constant' !== $status['reason'] ) {
		echo '<p>';
		mlab_smtp_set_action_button( 'staging-confirm', __( 'Das ist die produktive Website', 'mlab-smtp' ), true );
		echo '</p>';
	}
	mlab_smtp_ui_row_close();
}

/**
 * Mode and test address fields of the staging section.
 *
 * @param array $status Result of mlab_smtp_staging_status().
 */
function mlab_smtp_render_staging_fields( array $status ): void {
	$locked  = 'constant' === $status['reason'];
	$modes   = array(
		'auto'     => __( 'Automatisch erkennen (empfohlen)', 'mlab-smtp' ),
		'off'      => __( 'Aus', 'mlab-smtp' ),
		'block'    => __( 'Blockieren', 'mlab-smtp' ),
		'redirect' => __( 'An Testadresse umleiten', 'mlab-smtp' ),
	);
	$current = $locked ? (string) $status['mode'] : (string) mlab_smtp_staging_get()['mode'];
	echo '<div class="mlab-fields is-open"><div class="mlab-field"><label for="mlab-smtp-staging_mode">' . esc_html__( 'Modus', 'mlab-smtp' ) . '</label>';
	echo '<select id="mlab-smtp-staging_mode" name="staging_mode"' . ( $locked ? ' disabled' : '' ) . '>';
	foreach ( $modes as $value => $label ) {
		echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
	if ( $locked ) {
		mlab_smtp_ui_lock_note();
		echo '<span class="description">' . esc_html__( 'Die Konstante MLAB_SMTP_STAGING in der wp-config.php legt den Modus fest. Er lässt sich hier nicht ändern.', 'mlab-smtp' ) . '</span>';
	}
	echo '</div><div class="mlab-field"><label for="mlab-smtp-staging_test_email">' . esc_html__( 'Testadresse', 'mlab-smtp' ) . '</label>';
	echo '<input type="email" id="mlab-smtp-staging_test_email" name="staging_test_email" value="' . esc_attr( (string) mlab_smtp_staging_get()['test_email'] ) . '" placeholder="test@ihre-domain.ch" autocomplete="off">';
	echo '<span class="description">' . esc_html__( 'Nur nötig für «An Testadresse umleiten». Dorthin gehen dann alle Mails.', 'mlab-smtp' ) . '</span></div></div>';
}

/**
 * What each staging mode does.
 */
function mlab_smtp_render_staging_help(): void {
	echo '<ul class="mlab-steps mlab-muted">';
	$items = array(
		__( 'Automatisch erkennen: Sieht die Website aus wie eine Kopie (andere Adresse als bei der Einrichtung oder keine produktive Umgebung), werden Mails blockiert, bis ein Administrator bestätigt.', 'mlab-smtp' ),
		__( 'Aus: Mails werden immer normal versendet.', 'mlab-smtp' ),
		__( 'Blockieren: Es wird nichts versendet. Das Protokoll zeigt «blockiert», und WordPress meldet den Versand als fehlgeschlagen.', 'mlab-smtp' ),
		__( 'An Testadresse umleiten: Alle Mails gehen an die Testadresse. Kopien (Cc, Bcc) entfallen, der Betreff beginnt mit «[Staging]», unten steht die Liste der ursprünglichen Empfänger.', 'mlab-smtp' ),
	);
	foreach ( $items as $item ) {
		echo '<li>' . esc_html( $item ) . '</li>';
	}
	echo '</ul>';
}

/**
 * Alert section.
 */
function mlab_smtp_render_set_alerts(): void {
	$alerts = mlab_smtp_alerts_get();
	mlab_smtp_ui_row_open( __( 'Alarmierung (Webhook)', 'mlab-smtp' ), __( 'Meldet Störungen an eine Adresse Ihrer Wahl, zum Beispiel an Slack, Teams oder einen Überwachungsdienst.', 'mlab-smtp' ) );
	echo '<input type="hidden" name="alert_form" value="1"><div class="mlab-fields is-open">';
	echo '<div class="mlab-field"><label for="mlab-smtp-alert_url">' . esc_html__( 'Webhook-Adresse', 'mlab-smtp' ) . '</label>';
	echo '<input type="url" id="mlab-smtp-alert_url" name="alert_url" value="' . esc_attr( $alerts['url'] ) . '" placeholder="https://…" autocomplete="off">';
	echo '<span class="description">' . esc_html__( 'Nur https. Leer lassen, um die Alarmierung auszuschalten.', 'mlab-smtp' ) . '</span></div>';
	echo '<div class="mlab-field"><label for="mlab-smtp-alert_secret">' . esc_html__( 'Signatur-Secret (optional)', 'mlab-smtp' ) . '</label>';
	echo '<input type="password" id="mlab-smtp-alert_secret" name="alert_secret" value="" autocomplete="new-password" placeholder="' . esc_attr( $alerts['has_secret'] ? __( 'gespeichert', 'mlab-smtp' ) : '' ) . '">';
	echo '<span class="description">' . esc_html__( 'Damit kann der Empfänger prüfen, dass der Alarm von dieser Website kommt (Signatur im Header X-Mlab-Signature). Leer lassen, um ein gespeichertes Secret zu behalten.', 'mlab-smtp' ) . '</span>';
	if ( $alerts['has_secret'] ) {
		echo '<label class="mlab-check"><input type="checkbox" name="alert_clear_secret" value="1"> ' . esc_html__( 'Gespeichertes Secret entfernen', 'mlab-smtp' ) . '</label>';
	}
	echo '</div></div>';
	mlab_smtp_render_alert_events( $alerts );
	mlab_smtp_render_alert_last( $alerts );
	echo '<p class="mlab-muted">' . esc_html__( 'Der Alarm läuft unabhängig vom Mailweg: Er kommt auch an, wenn Hauptweg und Ersatzweg ausgefallen sind. Gesendet werden nur der Name der Website, das Ereignis, die Zeit, eine Anzahl und ein kurzer, bereinigter Text. Nie Empfänger, Betreffe oder Zugangsdaten.', 'mlab-smtp' ) . '</p>';
	mlab_smtp_ui_row_close();
}

/**
 * Event checkboxes.
 *
 * @param array $alerts Result of mlab_smtp_alerts_get().
 */
function mlab_smtp_render_alert_events( array $alerts ): void {
	echo '<fieldset class="mlab-fields is-open"><legend>' . esc_html__( 'Alarm auslösen bei', 'mlab-smtp' ) . '</legend>';
	foreach ( mlab_smtp_alert_events() as $key => $label ) {
		echo '<label class="mlab-check"><input type="checkbox" name="' . esc_attr( 'alert_events[' . $key . ']' ) . '" value="1"' . checked( ! empty( $alerts['events'][ $key ] ), true, false ) . '> ' . esc_html( $label ) . '</label><br>';
	}
	echo '</fieldset>';
}

/**
 * Last alert result and the test button.
 *
 * @param array $alerts Result of mlab_smtp_alerts_get().
 */
function mlab_smtp_render_alert_last( array $alerts ): void {
	$last = mlab_smtp_alerts_last();
	if ( $last['time'] > 0 ) {
		$state = $last['ok'] ? __( 'gesendet', 'mlab-smtp' ) : __( 'fehlgeschlagen', 'mlab-smtp' );
		$code  = $last['code'] > 0 ? ' (HTTP ' . (int) $last['code'] . ')' : '';
		/* translators: 1: time, 2: result, 3: HTTP code, 4: explanation */
		$line = sprintf( __( 'Letzter Alarm: %1$s, %2$s%3$s. %4$s', 'mlab-smtp' ), mlab_smtp_ui_time( (int) $last['time'] ), $state, $code, mlab_smtp_redact( (string) $last['message'] ) );
		mlab_smtp_ui_hint( $last['ok'] ? 'ok' : 'bad', __( 'Letztes Ergebnis', 'mlab-smtp' ), $line );
	} else {
		echo '<p class="mlab-muted">' . esc_html__( 'Es wurde noch kein Alarm gesendet.', 'mlab-smtp' ) . '</p>';
	}
	echo '<p>';
	mlab_smtp_set_action_button( 'alert-test', __( 'Alarm testen', 'mlab-smtp' ), false, '' === $alerts['url'] );
	echo ' <span class="mlab-muted">' . esc_html__( 'Zuerst speichern, dann testen. Das Ergebnis erscheint oben auf der Seite.', 'mlab-smtp' ) . '</span></p>';
}

/**
 * Support and privacy section.
 */
function mlab_smtp_render_set_privacy(): void {
	mlab_smtp_ui_row_open( __( 'Support und Datenschutz', 'mlab-smtp' ), __( 'Was für den Support bereitsteht und was das Plugin über Mails speichert.', 'mlab-smtp' ) );
	echo '<div class="mlab-fields is-open"><strong>' . esc_html__( 'Supportbericht', 'mlab-smtp' ) . '</strong>';
	echo '<p class="mlab-muted">' . esc_html__( 'Eine Textdatei mit Versionen, Zustand der Wege, Zahlen und den letzten 20 Protokolleinträgen. Passwörter, Schlüssel und Tokens fehlen, E-Mail-Adressen sind gekürzt, Betreffe und Inhalte fehlen. Die Datei wird beim Klick erzeugt und nirgends gespeichert.', 'mlab-smtp' ) . '</p><p>';
	mlab_smtp_set_action_button( 'support-report', __( 'Supportbericht herunterladen', 'mlab-smtp' ) );
	echo '</p></div>';
	mlab_smtp_render_privacy_block();
	mlab_smtp_ui_row_close();
}

/**
 * Privacy explanation: log content, retention, tools, queue payload.
 */
function mlab_smtp_render_privacy_block(): void {
	$days = max( 1, (int) mlab_smtp_get( 'log_retention' ) );
	$next = (int) wp_next_scheduled( MLAB_SMTP_CRON_HOOK );
	echo '<div class="mlab-fields is-open"><strong>' . esc_html__( 'Datenschutz', 'mlab-smtp' ) . '</strong>';
	echo '<p class="mlab-muted">' . esc_html__( 'Das Protokoll speichert je Mail Empfänger, Betreff, Status und eine eventuelle Fehlermeldung. Nachrichteninhalt und Anhänge werden standardmässig nicht gespeichert.', 'mlab-smtp' ) . '</p>';
	/* translators: 1: days, 2: next cleanup */
	echo '<p class="mlab-muted">' . esc_html( sprintf( __( 'Einträge werden nach %1$d Tagen automatisch gelöscht (Einstellung «Protokoll»). Nächste automatische Bereinigung: %2$s.', 'mlab-smtp' ), $days, $next > 0 ? wp_date( 'd.m.Y H:i', $next ) : __( 'noch nicht geplant', 'mlab-smtp' ) ) ) . '</p>';
	if ( 'queue' === mlab_smtp_get( 'delivery_mode' ) || (int) mlab_smtp_get( 'retry_in_background' ) ) {
		/* translators: %d: days */
		echo '<p class="mlab-muted">' . esc_html( sprintf( __( 'Mails in der Warteschlange liegen verschlüsselt in der Datenbank, bis der Anbieter sie annimmt. Endgültig fehlgeschlagene Mails behalten ihren Inhalt %d Tage (Einstellung «Zustellung»), dann wird er gelöscht.', 'mlab-smtp' ), (int) mlab_smtp_get( 'payload_days' ) ) ) . '</p>';
	}
	echo '<p><a href="' . esc_url( admin_url( 'export-personal-data.php' ) ) . '">' . esc_html__( 'Personenbezogene Daten exportieren', 'mlab-smtp' ) . '</a> · <a href="' . esc_url( admin_url( 'erase-personal-data.php' ) ) . '">' . esc_html__( 'Personenbezogene Daten löschen', 'mlab-smtp' ) . '</a> · <a href="' . esc_url( admin_url( 'options-privacy.php?tab=policyguide' ) ) . '">' . esc_html__( 'Textvorschlag für die Datenschutzerklärung', 'mlab-smtp' ) . '</a></p></div>';
}

/**
 * Encryption section.
 */
function mlab_smtp_render_set_encryption(): void {
	$items  = mlab_smtp_secret_inventory();
	$bad    = array_filter( $items, static fn( array $i ): bool => ! in_array( $i['status'], array( 'ok', 'old_key', 'plain' ), true ) );
	$todo   = array_filter( $items, static fn( array $i ): bool => in_array( $i['status'], array( 'old_key', 'plain' ), true ) );
	$crypto = mlab_smtp_crypto_available();
	mlab_smtp_ui_row_open( __( 'Verschlüsselung', 'mlab-smtp' ), __( 'Passwörter und Schlüssel sind in der Datenbank verschlüsselt gespeichert.', 'mlab-smtp' ) );
	echo '<ul class="mlab-steps">';
	echo '<li>' . esc_html( $crypto ? __( 'Verschlüsselung verfügbar: ja', 'mlab-smtp' ) : __( 'Verschlüsselung verfügbar: nein. Auf diesem Server fehlt libsodium, Zugangsdaten lassen sich nicht speichern.', 'mlab-smtp' ) ) . '</li>';
	if ( $crypto ) {
		$source = defined( 'MLAB_SMTP_KEY' ) && '' !== (string) constant( 'MLAB_SMTP_KEY' ) ? __( 'Konstante MLAB_SMTP_KEY aus der wp-config.php', 'mlab-smtp' ) : __( 'Sicherheitsschlüssel (Salts) der Website', 'mlab-smtp' );
		/* translators: 1: key source, 2: short key id */
		echo '<li>' . esc_html( sprintf( __( 'Schlüsselquelle: %1$s (Kennung %2$s)', 'mlab-smtp' ), $source, mlab_smtp_key_id( mlab_smtp_key() ) ) ) . '</li>';
	}
	/* translators: 1: readable count, 2: unreadable count */
	echo '<li>' . esc_html( sprintf( __( 'Gespeicherte Zugangsdaten: %1$d lesbar, %2$d nicht lesbar', 'mlab-smtp' ), count( $items ) - count( $bad ), count( $bad ) ) ) . '</li></ul>';
	mlab_smtp_render_unreadable( $bad );
	echo '<p>';
	mlab_smtp_set_action_button( 'reencrypt', __( 'Zugangsdaten mit aktuellem Schlüssel neu verschlüsseln', 'mlab-smtp' ), false, ! $crypto || ! $todo );
	echo ' <span class="mlab-muted">' . esc_html( $todo ? __( 'Lesbare Zugangsdaten werden mit dem aktuellen Schlüssel neu gespeichert. Nicht lesbare bleiben unverändert.', 'mlab-smtp' ) : __( 'Alle lesbaren Zugangsdaten sind schon mit dem aktuellen Schlüssel verschlüsselt.', 'mlab-smtp' ) ) . '</span></p>';
	echo '<p class="mlab-muted">' . esc_html__( 'Hilfe zu MLAB_SMTP_KEY: Mit dieser Konstante in der wp-config.php wählen Sie einen eigenen Schlüssel, unabhängig von den WordPress-Salts. Ändern Sie sie oder die Salts, ohne den alten Wert aufzubewahren, werden die gespeicherten Zugangsdaten unlesbar und müssen neu eingegeben werden. Das Plugin erzeugt nie von sich aus einen neuen Schlüssel. Die Schritte zur Wiederherstellung stehen in der Datei docs/upgrade-and-recovery.md des Plugins.', 'mlab-smtp' ) . '</p>';
	mlab_smtp_ui_row_close();
}

/**
 * List of unreadable secrets with reason and next step.
 *
 * @param array $bad Entries from mlab_smtp_secret_inventory().
 */
function mlab_smtp_render_unreadable( array $bad ): void {
	if ( ! $bad ) {
		return;
	}
	$reasons = array(
		'key_changed' => __( 'Der Schlüssel hat sich geändert.', 'mlab-smtp' ),
		'damaged'     => __( 'Die gespeicherten Daten sind beschädigt.', 'mlab-smtp' ),
		'no_crypto'   => __( 'Die Verschlüsselung ist auf diesem Server nicht verfügbar.', 'mlab-smtp' ),
	);
	echo '<ul class="mlab-locked">';
	foreach ( $bad as $item ) {
		echo '<li><strong>' . esc_html( mlab_smtp_secret_label( $item['id'] ) ) . '</strong>: ' . esc_html( $reasons[ $item['status'] ] ?? '' ) . ' ' . esc_html__( 'Bitte neu eingeben.', 'mlab-smtp' ) . ' <a href="' . esc_url( mlab_smtp_secret_link( $item['id'] ) ) . '">' . esc_html__( 'Zur Eingabe', 'mlab-smtp' ) . '</a></li>';
	}
	echo '</ul>';
}

/**
 * Plain name of a stored secret.
 *
 * @param string $id Secret id.
 */
function mlab_smtp_secret_label( string $id ): string {
	$labels = array(
		'm365_client_secret'       => __( 'Microsoft 365: Client-Secret', 'mlab-smtp' ),
		'google_client_secret'     => __( 'Google: Client-Secret', 'mlab-smtp' ),
		'google_refresh_token'     => __( 'Google: Anmeldung', 'mlab-smtp' ),
		'resend_api_key'           => __( 'Resend: API-Key', 'mlab-smtp' ),
		'resend_webhook_secret'    => __( 'Resend: Signing Secret (Hauptkonto)', 'mlab-smtp' ),
		'fb_resend_api_key'        => __( 'Ersatzweg Resend: API-Key', 'mlab-smtp' ),
		'fb_resend_webhook_secret' => __( 'Ersatzweg Resend: Signing Secret', 'mlab-smtp' ),
		'smtp_pass'                => __( 'SMTP: Passwort', 'mlab-smtp' ),
		'fb_smtp_pass'             => __( 'Ersatzweg SMTP: Passwort', 'mlab-smtp' ),
		'alert_secret'             => __( 'Alarmierung: Signatur-Secret', 'mlab-smtp' ),
	);
	return $labels[ $id ] ?? $id;
}

/**
 * Where a secret can be entered again.
 *
 * @param string $id Secret id.
 */
function mlab_smtp_secret_link( string $id ): string {
	$settings = mlab_smtp_page_url( array( 'view' => 'settings' ) );
	if ( 'alert_secret' === $id ) {
		return $settings . '#mlab-s-alerts';
	}
	if ( str_ends_with( $id, 'webhook_secret' ) ) {
		return $settings . '#mlab-s-webhooks';
	}
	if ( str_starts_with( $id, 'fb_' ) ) {
		return mlab_smtp_fallback_url( 1 );
	}
	$provider = strtok( $id, '_' );
	return mlab_smtp_wizard_url( 2, (string) $provider );
}
