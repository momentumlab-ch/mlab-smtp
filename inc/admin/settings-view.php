<?php
/**
 * Settings view: connection, fallback, notification, sender, log, constants from wp-config.php.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings tab.
 */
function mlab_smtp_render_settings(): void {
	echo '<div class="mlab-card">';
	mlab_smtp_ui_form_open( 'mlab_smtp_save_settings', 'mlab-settings' );
	mlab_smtp_render_set_connection();
	mlab_smtp_render_set_fallback();
	mlab_smtp_render_set_notify();
	mlab_smtp_render_set_sender();
	mlab_smtp_render_set_log();
	mlab_smtp_render_set_locked();
	echo '<div class="mlab-savebar"><button type="submit" class="button button-primary">' . esc_html__( 'Änderungen speichern', 'mlab-smtp' ) . '</button></div>';
	mlab_smtp_ui_form_close();
	echo '</div>';
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
		echo '<a class="button button-primary" href="' . esc_url( mlab_smtp_page_url( array( 'view' => 'wizard' ) ) ) . '">' . esc_html__( 'Einrichtung starten', 'mlab-smtp' ) . '</a>';
	} else {
		echo '<div class="mlab-conn">';
		mlab_smtp_ui_logo( $provider );
		echo '<div class="mlab-grow"><strong>' . esc_html( $meta['name'] ) . '</strong><div class="mlab-muted mlab-small">' . esc_html( (string) mlab_smtp_get( 'from_email' ) ) . '</div></div>';
		echo '<a class="button" href="' . esc_url( mlab_smtp_wizard_url( 2, $provider ) ) . '">' . esc_html__( 'Ändern', 'mlab-smtp' ) . '</a></div>';
	}
	mlab_smtp_ui_row_close();
}

/**
 * Fallback row: switch, SMTP fields and its own connection check.
 */
function mlab_smtp_render_set_fallback(): void {
	mlab_smtp_ui_row_open( __( 'Ersatzweg', 'mlab-smtp' ), __( 'Springt ein, wenn die Hauptverbindung ausfällt oder eine Mail ablehnt.', 'mlab-smtp' ) );
	echo '<div data-mlab-fields data-group="fallback">';
	mlab_smtp_ui_switch( 'fallback_enabled', __( 'Ersatzweg verwenden', 'mlab-smtp' ), 'mlab-fallback' );
	echo '<div class="mlab-fields" id="mlab-fallback">';
	echo '<p class="mlab-muted mlab-small">' . esc_html__( 'Der Ersatzweg ist immer ein eigener Mailserver (SMTP).', 'mlab-smtp' ) . '</p>';
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
	mlab_smtp_ui_switch( 'fb_smtp_auth', __( 'Der Server verlangt Benutzername und Passwort', 'mlab-smtp' ) );
	mlab_smtp_ui_field( 'fb_smtp_user', __( 'Benutzername', 'mlab-smtp' ) );
	mlab_smtp_ui_field( 'fb_smtp_pass', __( 'Passwort', 'mlab-smtp' ), array( 'type' => 'password' ) );
	echo '<div class="mlab-row"><button type="submit" name="do" value="check" class="button" data-mlab-check data-route="fallback" data-mlab-js-only hidden>' . esc_html__( 'Verbindung prüfen', 'mlab-smtp' ) . '</button></div>';
	mlab_smtp_ui_result_area();
	echo '</div></div>';
	mlab_smtp_ui_row_close();
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
	$fixed = 'smtp' !== mlab_smtp_get( 'provider' ) && '' !== mlab_smtp_get( 'provider' );
	mlab_smtp_ui_row_open( __( 'Absender', 'mlab-smtp' ), __( 'Name und Adresse, die Empfänger sehen.', 'mlab-smtp' ) );
	echo '<div class="mlab-fields is-open">';
	mlab_smtp_ui_field( 'from_name', __( 'Absender-Name', 'mlab-smtp' ), array( 'placeholder' => get_bloginfo( 'name' ) ) );
	mlab_smtp_ui_field(
		'from_email',
		__( 'Absender-Adresse', 'mlab-smtp' ),
		array(
			'type'     => 'email',
			'readonly' => $fixed,
			'help'     => $fixed ? __( 'Vorgegeben durch das verbundene Postfach.', 'mlab-smtp' ) : '',
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
