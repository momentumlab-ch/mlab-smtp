<?php
/**
 * Settings view: section cards for connection, fallback, notification, sender, log, constants from wp-config.php.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/settings-security.php';

/**
 * Sections in display order: anchor => array( title, renderer ).
 *
 * @return array<string, array{0:string, 1:callable}>
 */
function mlab_smtp_settings_sections(): array {
	return array(
		'connection' => array( __( 'Verbindung', 'mlab-smtp' ), 'mlab_smtp_render_set_connection' ),
		'fallback'   => array( __( 'Ersatzweg', 'mlab-smtp' ), 'mlab_smtp_render_set_fallback' ),
		'delivery'   => array( __( 'Zustellung', 'mlab-smtp' ), 'mlab_smtp_render_set_delivery' ),
		'webhooks'   => array( __( 'Zustellbestätigung', 'mlab-smtp' ), 'mlab_smtp_render_set_webhooks' ),
		'sender'     => array( __( 'Absender', 'mlab-smtp' ), 'mlab_smtp_render_set_sender' ),
		'staging'    => array( __( 'Staging', 'mlab-smtp' ), 'mlab_smtp_render_set_staging' ),
		'alerts'     => array( __( 'Alarmierung', 'mlab-smtp' ), 'mlab_smtp_render_set_alerts' ),
		'notify'     => array( __( 'Benachrichtigung', 'mlab-smtp' ), 'mlab_smtp_render_set_notify' ),
		'log'        => array( __( 'Protokoll', 'mlab-smtp' ), 'mlab_smtp_render_set_log' ),
		'privacy'    => array( __( 'Support und Datenschutz', 'mlab-smtp' ), 'mlab_smtp_render_set_privacy' ),
		'encryption' => array( __( 'Verschlüsselung', 'mlab-smtp' ), 'mlab_smtp_render_set_encryption' ),
		'locked'     => array( __( 'Aus wp-config.php', 'mlab-smtp' ), 'mlab_smtp_render_set_locked' ),
	);
}

/**
 * Settings tab: intro, index with anchor links, the sections and one save bar.
 */
function mlab_smtp_render_settings(): void {
	echo '<p class="mlab-muted">' . esc_html__( 'Hier stellen Sie ein, wie Mails versendet, geschützt und überwacht werden. Alles auf dieser Seite wird mit «Änderungen speichern» unten gespeichert. Die einzelnen Knöpfe (testen, bestätigen, herunterladen, neu verschlüsseln) wirken sofort.', 'mlab-smtp' ) . '</p>';
	echo '<nav class="mlab-index" aria-label="' . esc_attr__( 'Inhalt dieser Seite', 'mlab-smtp' ) . '"><ul>';
	foreach ( mlab_smtp_settings_sections() as $id => $section ) {
		echo '<li><a href="#' . esc_attr( 'mlab-s-' . $id ) . '">' . esc_html( $section[0] ) . '</a></li>';
	}
	echo '</ul></nav>';
	mlab_smtp_ui_form_open( 'mlab_smtp_save_settings', 'mlab-settings mlab-stack' );
	foreach ( mlab_smtp_settings_sections() as $id => $section ) {
		echo '<div class="mlab-anchor" id="' . esc_attr( 'mlab-s-' . $id ) . '">';
		call_user_func( $section[1] );
		echo '</div>';
	}
	echo '<div class="mlab-savebar"><button type="submit" class="button button-primary">' . esc_html__( 'Änderungen speichern', 'mlab-smtp' ) . '</button></div>';
	mlab_smtp_ui_form_close();
	mlab_smtp_render_set_action_forms();
}

/**
 * Connection row with the change button that leads to wizard step 2.
 */
function mlab_smtp_render_set_connection(): void {
	$provider = (string) mlab_smtp_get( 'provider' );
	$meta     = mlab_smtp_ui_providers()[ $provider ] ?? null;
	mlab_smtp_ui_row_open( __( 'Verbindung', 'mlab-smtp' ), __( 'Über welchen Dienst die Website Mails versendet.', 'mlab-smtp' ) );
	if ( ! $meta ) {
		echo '<p class="mlab-muted">' . esc_html__( 'Noch nicht eingerichtet.', 'mlab-smtp' ) . '</p>';
		echo '<a class="button" href="' . esc_url( mlab_smtp_page_url( array( 'view' => 'wizard' ) ) ) . '">' . esc_html__( 'Einrichtung starten', 'mlab-smtp' ) . '</a>';
	} else {
		echo '<div class="mlab-conn">';
		mlab_smtp_ui_logo( $provider );
		echo '<div class="mlab-li-main"><strong>' . esc_html( $meta['name'] ) . '</strong><span class="mlab-muted">' . esc_html( (string) mlab_smtp_get( 'from_email' ) ) . '</span></div>';
		echo '<a class="button" href="' . esc_url( mlab_smtp_wizard_url( 2, $provider ) ) . '">' . esc_html__( 'Ändern', 'mlab-smtp' ) . '</a></div>';
	}
	mlab_smtp_ui_row_close();
}

/**
 * Fallback row: switch, SMTP fields and its own connection check.
 */
function mlab_smtp_render_set_fallback(): void {
	mlab_smtp_ui_row_open( __( 'Ersatzweg', 'mlab-smtp' ), __( 'Springt ein, wenn die Hauptverbindung ausfällt oder eine Mail ablehnt.', 'mlab-smtp' ) );
	mlab_smtp_render_fallback_status();
	echo '<div data-mlab-fields data-group="fallback">';
	mlab_smtp_ui_switch( 'fallback_enabled', __( 'Ersatzweg verwenden', 'mlab-smtp' ), 'mlab-fallback' );
	echo '<div class="mlab-fields mlab-fb" id="mlab-fallback">';
	mlab_smtp_ui_segmented( 'fb_provider', __( 'Ersatzweg über', 'mlab-smtp' ), mlab_smtp_fallback_options() );
	echo '<div data-fb="smtp">';
	mlab_smtp_render_fallback_smtp();
	echo '</div><div data-fb="resend">';
	mlab_smtp_render_fallback_resend();
	echo '</div>';
	echo '<div class="mlab-row"><button type="submit" name="do" value="check" class="button" data-mlab-check data-route="fallback" data-mlab-js-only hidden>' . esc_html__( 'Verbindung prüfen', 'mlab-smtp' ) . '</button></div>';
	mlab_smtp_ui_result_area();
	echo '</div></div>';
	mlab_smtp_ui_row_close();
}

/**
 * State of the fallback route (four labelled states) and the button into the guided flow.
 */
function mlab_smtp_render_fallback_status(): void {
	$state = mlab_smtp_route_state( 'fallback' );
	mlab_smtp_ui_hint( $state['level'], $state['label'], $state['text'] );
	mlab_smtp_render_route_states( $state );
	$label = $state['eingetragen'] ? __( 'Ersatzweg prüfen und testen', 'mlab-smtp' ) : __( 'Ersatzweg einrichten', 'mlab-smtp' );
	echo '<p><a class="button' . ( $state['getestet'] && ! $state['veraltet'] ? '' : ' button-primary' ) . '" href="' . esc_url( mlab_smtp_fallback_url( 1 ) ) . '">' . esc_html( $label ) . '</a></p>';
}

/**
 * Providers that can serve as fallback, by name. Resend is left out when it is already the main route.
 *
 * @return array<string, string> Provider key => name.
 */
function mlab_smtp_fallback_options(): array {
	$options = array();
	// The own mail server is the default, so it comes first.
	$ids = array_merge( array( 'smtp' ), array_diff( mlab_smtp_choices()['fb_provider'], array( 'smtp' ) ) );
	foreach ( $ids as $id ) {
		if ( mlab_smtp_get( 'provider' ) !== $id || 'smtp' === $id ) {
			$options[ $id ] = mlab_smtp_ui_providers()[ $id ]['name'];
		}
	}
	return $options;
}

/**
 * Fields of the own mail server as fallback.
 */
function mlab_smtp_render_fallback_smtp(): void {
	mlab_smtp_ui_field( 'fb_smtp_host', __( 'Server', 'mlab-smtp' ), array( 'placeholder' => 'mail.ihre-domain.ch' ) );
	mlab_smtp_ui_field(
		'fb_smtp_port',
		__( 'Port', 'mlab-smtp' ),
		array(
			'type'  => 'number',
			'attrs' => array(
				'min' => '1',
				'max' => '65535',
			),
		)
	);
	mlab_smtp_ui_field(
		'fb_smtp_secure',
		__( 'Verschlüsselung', 'mlab-smtp' ),
		array( 'options' => mlab_smtp_secure_options() )
	);
	mlab_smtp_render_insecure_note( 'fb_smtp_secure' );
	mlab_smtp_ui_switch( 'fb_smtp_auth', __( 'Der Server verlangt Benutzername und Passwort', 'mlab-smtp' ) );
	mlab_smtp_ui_field( 'fb_smtp_user', __( 'Benutzername', 'mlab-smtp' ) );
	mlab_smtp_ui_field( 'fb_smtp_pass', __( 'Passwort', 'mlab-smtp' ), array( 'type' => 'password' ) );
}

/**
 * Field of Resend as fallback: its own API key.
 */
function mlab_smtp_render_fallback_resend(): void {
	mlab_smtp_ui_field(
		'fb_resend_api_key',
		__( 'API-Key von Resend', 'mlab-smtp' ),
		array(
			'type'        => 'password',
			'placeholder' => 're_…',
			'help'        => __( 'Beginnt mit «re_». Die Domain Ihrer Absender-Adresse muss bei Resend verifiziert sein, und der Ersatzweg sendet immer mit dieser Adresse.', 'mlab-smtp' ),
		)
	);
}

/**
 * Encryption choices of an SMTP connection.
 *
 * @return array<string, string>
 */
function mlab_smtp_secure_options(): array {
	return array(
		'tls'  => __( 'STARTTLS (Port 587, empfohlen)', 'mlab-smtp' ),
		'ssl'  => __( 'SSL/TLS (Port 465)', 'mlab-smtp' ),
		'none' => __( 'Keine (nicht empfohlen)', 'mlab-smtp' ),
	);
}

/**
 * Notification row.
 */
function mlab_smtp_render_set_notify(): void {
	mlab_smtp_ui_row_open( __( 'Benachrichtigung', 'mlab-smtp' ), __( 'Damit Sie erfahren, wenn Mails nicht ankommen.', 'mlab-smtp' ) );
	mlab_smtp_ui_switch( 'notify_enabled', __( 'Bei Fehlern benachrichtigen', 'mlab-smtp' ), 'mlab-notify' );
	echo '<div class="mlab-fields" id="mlab-notify">';
	mlab_smtp_ui_field(
		'notify_email',
		__( 'An', 'mlab-smtp' ),
		array(
			'type'        => 'email',
			'placeholder' => (string) get_option( 'admin_email' ),
			'help'        => __( 'Leer lassen für die Administrator-Adresse der Website. Die Nachricht geht über den Ersatzweg, falls die Hauptverbindung ausfällt.', 'mlab-smtp' ),
		)
	);
	mlab_smtp_ui_field(
		'notify_mode',
		__( 'Wann', 'mlab-smtp' ),
		array(
			'options' => array(
				'instant' => __( 'Sofort beim ersten Fehler, danach höchstens 1× pro Stunde', 'mlab-smtp' ),
				'daily'   => __( 'Tägliche Zusammenfassung', 'mlab-smtp' ),
			),
		)
	);
	echo '</div>';
	mlab_smtp_ui_row_close();
}

/**
 * Sender row: name, address, reply-to, force.
 */
function mlab_smtp_render_set_sender(): void {
	$meta  = mlab_smtp_ui_providers()[ (string) mlab_smtp_get( 'provider' ) ]['sender'] ?? mlab_smtp_ui_sender( array() );
	$fixed = $meta['fixed'];
	mlab_smtp_ui_row_open( __( 'Absender', 'mlab-smtp' ), __( 'Name und Adresse, die Empfänger sehen.', 'mlab-smtp' ) );
	echo '<div class="mlab-fields is-open">';
	mlab_smtp_ui_field( 'from_name', __( 'Absender-Name', 'mlab-smtp' ), array( 'placeholder' => get_bloginfo( 'name' ) ) );
	mlab_smtp_ui_field(
		'from_email',
		__( 'Absender-Adresse', 'mlab-smtp' ),
		array(
			'type'     => 'email',
			'readonly' => $fixed,
			'help'     => $meta['help_settings'],
		)
	);
	mlab_smtp_ui_field(
		'reply_to',
		__( 'Antworten gehen an (optional)', 'mlab-smtp' ),
		array(
			'type'        => 'email',
			'placeholder' => __( 'z. B. info@ihre-domain.ch', 'mlab-smtp' ),
		)
	);
	mlab_smtp_ui_switch( 'force_from', __( 'Absender auch verwenden, wenn ein anderes Plugin einen eigenen setzt', 'mlab-smtp' ) );
	echo '</div>';
	mlab_smtp_ui_row_close();
}

/**
 * Log row: switch and retention.
 */
function mlab_smtp_render_set_log(): void {
	mlab_smtp_ui_row_open( __( 'Protokoll', 'mlab-smtp' ), __( 'Empfänger, Betreff und Ergebnis, nie der Inhalt.', 'mlab-smtp' ) );
	mlab_smtp_ui_switch( 'log_enabled', __( 'Versand protokollieren', 'mlab-smtp' ) );
	echo '<div class="mlab-fields is-open">';
	mlab_smtp_ui_field(
		'log_retention',
		__( 'Aufbewahren', 'mlab-smtp' ),
		array(
			'options' => array(
				'7'  => __( '7 Tage', 'mlab-smtp' ),
				'30' => __( '30 Tage', 'mlab-smtp' ),
				'90' => __( '90 Tage', 'mlab-smtp' ),
			),
		)
	);
	echo '</div>';
	mlab_smtp_ui_row_close();
}

/**
 * Lists the settings that wp-config.php fixes. Nothing is printed when there are none.
 */
function mlab_smtp_render_set_locked(): void {
	$locked = array_filter( array_keys( mlab_smtp_defaults() ), 'mlab_smtp_is_locked' );
	if ( ! $locked ) {
		return;
	}
	mlab_smtp_ui_row_open( __( 'Aus wp-config.php', 'mlab-smtp' ), __( 'Diese Werte sind in der Datei gesetzt und lassen sich hier nicht ändern.', 'mlab-smtp' ) );
	echo '<ul class="mlab-locked">';
	foreach ( $locked as $key ) {
		echo '<li><span class="dashicons dashicons-lock" aria-hidden="true"></span> <code>' . esc_html( 'MLAB_SMTP_' . strtoupper( $key ) ) . '</code> <span class="mlab-muted">' . esc_html__( 'gesetzt', 'mlab-smtp' ) . '</span></li>';
	}
	echo '</ul>';
	mlab_smtp_ui_row_close();
}

/**
 * Zustellbestätigung (Resend): webhook addresses, signing secrets, instructions and state.
 */
function mlab_smtp_render_set_webhooks(): void {
	mlab_smtp_ui_row_open( __( 'Zustellbestätigung (Resend)', 'mlab-smtp' ), __( 'Resend meldet zurück, ob eine Mail beim Empfänger angekommen ist. Ohne diese Meldung gilt eine Mail nur als «angenommen».', 'mlab-smtp' ) );
	echo '<ol class="mlab-plainlist">';
	foreach ( mlab_smtp_webhook_steps() as $step ) {
		echo '<li>' . esc_html( $step ) . '</li>';
	}
	echo '</ol>';
	echo '<p class="mlab-muted">' . esc_html__( 'Hauptkonto und Ersatzkonto brauchen je einen eigenen Webhook mit eigener Adresse und eigenem Signing Secret.', 'mlab-smtp' ) . '</p>';
	$fields = array(
		'resend'          => array( __( 'Hauptkonto', 'mlab-smtp' ), 'resend_webhook_secret' ),
		'resend-fallback' => array( __( 'Ersatzkonto', 'mlab-smtp' ), 'fb_resend_webhook_secret' ),
	);
	foreach ( $fields as $route => $field ) {
		echo '<div class="mlab-fields is-open"><strong>' . esc_html( $field[0] ) . '</strong>';
		mlab_smtp_ui_copy( mlab_smtp_webhook_url( $route ) );
		mlab_smtp_ui_field(
			$field[1],
			__( 'Signing Secret', 'mlab-smtp' ),
			array(
				'type'        => 'password',
				'placeholder' => 'whsec_…',
			)
		);
		$last = mlab_smtp_webhook_last_event( $route );
		/* translators: %s: time span like "5 Minuten" */
		$state = $last ? sprintf( __( 'Letztes Ereignis vor %s.', 'mlab-smtp' ), human_time_diff( $last ) ) : __( 'Noch kein Ereignis empfangen.', 'mlab-smtp' );
		echo '<p class="mlab-muted">' . esc_html( $state ) . '</p></div>';
	}
	mlab_smtp_ui_row_close();
}

/**
 * Steps in the Resend dashboard.
 *
 * @return string[]
 */
function mlab_smtp_webhook_steps(): array {
	return array(
		__( 'Bei Resend im Menü «Webhooks» öffnen und «Add Webhook» wählen.', 'mlab-smtp' ),
		__( 'Die Adresse des Kontos unten als «Endpoint URL» einfügen (Haupt- und Ersatzkonto je mit ihrer eigenen Adresse).', 'mlab-smtp' ),
		__( 'Diese sechs Ereignisse ankreuzen: email.sent, email.delivered, email.delivery_delayed, email.bounced, email.complained, email.failed. Öffnen und Klicks werden nicht gebraucht.', 'mlab-smtp' ),
		__( 'Webhook speichern, dann auf der Seite des Webhooks das «Signing Secret» (beginnt mit «whsec_») kopieren und hier einfügen.', 'mlab-smtp' ),
		__( 'Änderungen speichern. Beim nächsten Versand erscheint hier das letzte empfangene Ereignis.', 'mlab-smtp' ),
	);
}

/**
 * Delivery row: sync or queue, background retry, unclear policy, attempts, retention of the content.
 */
function mlab_smtp_render_set_delivery(): void {
	mlab_smtp_ui_row_open( __( 'Zustellung', 'mlab-smtp' ), __( 'Wann eine Mail als versendet gilt und was bei Störungen passiert.', 'mlab-smtp' ) );
	echo '<div class="mlab-fields is-open">';
	mlab_smtp_ui_field(
		'delivery_mode',
		__( 'Versandart', 'mlab-smtp' ),
		array(
			'options' => array(
				'sync'  => __( 'Sofort senden (Standard)', 'mlab-smtp' ),
				'queue' => __( 'Über die Warteschlange senden', 'mlab-smtp' ),
			),
			'help'    => __( 'Sofort: wp_mail() meldet «gesendet» nur, wenn der Anbieter die Mail angenommen hat. Warteschlange: wp_mail() meldet «gesendet», sobald die Mail sicher gespeichert ist. Das heisst nur «zum Versand angenommen», nicht «versendet»: Der Versand folgt im Hintergrund, Fehler zeigt erst das Protokoll. Mails mit sehr grossen Anhängen (über 10 MB) werden immer sofort gesendet.', 'mlab-smtp' ),
		)
	);
	mlab_smtp_ui_switch( 'retry_in_background', __( 'Im Hintergrund wiederholen (nur bei «Sofort senden»)', 'mlab-smtp' ) );
	echo '<p class="description">' . esc_html__( 'Schlägt der Versand vorübergehend fehl (Hauptweg und Ersatzweg), legt das Plugin die Mail in die Warteschlange, und wp_mail() meldet «angenommen» statt eines Fehlers. Aus (Standard): wp_mail() meldet den Fehler sofort.', 'mlab-smtp' ) . '</p>';
	mlab_smtp_ui_switch( 'unclear_failover', __( 'Bei unklarem Ergebnis auf den Ersatzweg wechseln (Sofort senden)', 'mlab-smtp' ) );
	mlab_smtp_ui_switch( 'queue_unclear_failover', __( 'Bei unklarem Ergebnis auch für Mails aus der Warteschlange auf den Ersatzweg wechseln', 'mlab-smtp' ) );
	echo '<p class="description">' . esc_html__( 'Unklar heisst: Die Anfrage ging raus, die Antwort fehlt. Der Anbieter kann die Mail trotzdem angenommen haben, ein Wechsel kann dann ein Duplikat erzeugen. Unklare Mails werden nie automatisch erneut gesendet, ausser über Resend mit demselben Idempotenz-Schlüssel innerhalb von 23 Stunden.', 'mlab-smtp' ) . '</p>';
	mlab_smtp_ui_field(
		'max_attempts',
		__( 'Höchstzahl der Versuche', 'mlab-smtp' ),
		array(
			'options' => array_combine( array( '2', '3', '4', '5', '6', '8', '10' ), array( '2', '3', '4', '5', '6', '8', '10' ) ),
			'help'    => __( 'Abstand zwischen den Versuchen: 1 Minute, dann jeweils verdoppelt, höchstens 6 Stunden. Eine Retry-After-Angabe des Anbieters hat Vorrang.', 'mlab-smtp' ),
		)
	);
	mlab_smtp_ui_field(
		'payload_days',
		__( 'Inhalt endgültig fehlgeschlagener Mails aufbewahren', 'mlab-smtp' ),
		array(
			'options' => array(
				'1'  => __( '1 Tag', 'mlab-smtp' ),
				'3'  => __( '3 Tage', 'mlab-smtp' ),
				'7'  => __( '7 Tage', 'mlab-smtp' ),
				'14' => __( '14 Tage (Höchstwert)', 'mlab-smtp' ),
			),
			'help'    => __( 'Verschlüsselt gespeichert, nur damit sich die Mail manuell wiederholen lässt. Nach der Annahme durch den Anbieter wird der Inhalt sofort gelöscht.', 'mlab-smtp' ),
		)
	);
	echo '</div>';
	echo '<p class="mlab-muted">' . esc_html__( 'Grenze: Einstellungen, die ein anderes Plugin nur am Mailer-Objekt setzt (zum Beispiel ein DKIM-Schlüssel), gelten nicht für Mails aus der Warteschlange. Die Warteschlange läuft über WP-Cron; ein System-Cron ist zuverlässiger.', 'mlab-smtp' ) . '</p>';
	mlab_smtp_ui_row_close();
}
