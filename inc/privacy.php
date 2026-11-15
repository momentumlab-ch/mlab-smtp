<?php
/**
 * Datenschutz: Exporter und Eraser für personenbezogene Daten (Suche über den HMAC-Empfängerindex) und Textvorschlag
 * für die Datenschutzerklärung.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_PRIVACY_BATCH = 50;

add_filter( 'wp_privacy_personal_data_exporters', 'mlab_smtp_privacy_register_exporter' );
add_filter( 'wp_privacy_personal_data_erasers', 'mlab_smtp_privacy_register_eraser' );
add_action( 'admin_init', 'mlab_smtp_privacy_policy_content' );

/**
 * Registers the exporter.
 *
 * @param array $exporters Exporters.
 * @return array
 */
function mlab_smtp_privacy_register_exporter( $exporters ): array {
	$exporters['mlab-smtp'] = array(
		'exporter_friendly_name' => __( 'MomentumLab SMTP: Versandprotokoll', 'mlab-smtp' ),
		'callback'               => 'mlab_smtp_privacy_export',
	);
	return (array) $exporters;
}

/**
 * Registers the eraser.
 *
 * @param array $erasers Erasers.
 * @return array
 */
function mlab_smtp_privacy_register_eraser( $erasers ): array {
	$erasers['mlab-smtp'] = array(
		'eraser_friendly_name' => __( 'MomentumLab SMTP: Versandprotokoll', 'mlab-smtp' ),
		'callback'             => 'mlab_smtp_privacy_erase',
	);
	return (array) $erasers;
}

/**
 * Exporter: the messages sent to an address (recipient, subject, status, times, route).
 *
 * @param string $email Address of the person.
 * @param int    $page  Page, starting at 1.
 * @return array{data:array, done:bool}
 */
function mlab_smtp_privacy_export( $email, $page = 1 ): array {
	$page  = max( 1, (int) $page );
	$ids   = mlab_smtp_store_ids_by_email( (string) $email, MLAB_SMTP_PRIVACY_BATCH, ( $page - 1 ) * MLAB_SMTP_PRIVACY_BATCH );
	$items = array();
	foreach ( $ids as $id ) {
		$row = mlab_smtp_store_get( $id );
		if ( $row ) {
			$items[] = mlab_smtp_privacy_item( $row, (string) $email );
		}
	}
	return array(
		'data' => $items,
		'done' => count( $ids ) < MLAB_SMTP_PRIVACY_BATCH,
	);
}

/**
 * Export group item of one message.
 *
 * @param array  $row   Message row.
 * @param string $email Address of the person (not the other recipients).
 * @return array
 */
function mlab_smtp_privacy_item( array $row, string $email ): array {
	$when = static function ( $time ): string {
		return $time ? gmdate( 'Y-m-d H:i:s', (int) $time ) . ' UTC' : '';
	};
	return array(
		'group_id'          => 'mlab-smtp-messages',
		'group_label'       => __( 'E-Mail-Versand', 'mlab-smtp' ),
		'group_description' => __( 'Nachrichten, die über MomentumLab SMTP an diese Adresse gesendet wurden (ohne Inhalt).', 'mlab-smtp' ),
		'item_id'           => 'mlab-smtp-message-' . (int) $row['id'],
		'data'              => array(
			array(
				'name'  => __( 'Empfänger', 'mlab-smtp' ),
				'value' => $email,
			),
			array(
				'name'  => __( 'Betreff', 'mlab-smtp' ),
				'value' => (string) $row['subject'],
			),
			array(
				'name'  => __( 'Status', 'mlab-smtp' ),
				'value' => (string) $row['status'],
			),
			array(
				'name'  => __( 'Erstellt', 'mlab-smtp' ),
				'value' => $when( $row['created_at'] ),
			),
			array(
				'name'  => __( 'Abgeschlossen', 'mlab-smtp' ),
				'value' => $when( $row['completed_at'] ),
			),
			array(
				'name'  => __( 'Versandweg', 'mlab-smtp' ),
				'value' => trim( $row['route'] . ' ' . $row['via'] ),
			),
		),
	);
}

/**
 * Eraser: deletes message, attempts, events, recipient index and payload of every message to the address.
 *
 * @param string $email Address of the person.
 * @param int    $page  Page (unused, deleted rows drop out of the lookup).
 * @return array{items_removed:int, items_retained:bool, messages:string[], done:bool}
 */
function mlab_smtp_privacy_erase( $email, $page = 1 ): array {
	unset( $page );
	$ids     = mlab_smtp_store_ids_by_email( (string) $email, MLAB_SMTP_PRIVACY_BATCH );
	$removed = mlab_smtp_store_delete_ids( $ids );
	return array(
		'items_removed'  => $removed,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => count( $ids ) < MLAB_SMTP_PRIVACY_BATCH || 0 === $removed,
	);
}

/**
 * Suggested text for the privacy policy.
 */
function mlab_smtp_privacy_policy_content(): void {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}
	$days = max( 1, (int) mlab_smtp_get( 'log_retention' ) );
	$text = '<h2>' . esc_html__( 'E-Mail-Versand (MomentumLab SMTP)', 'mlab-smtp' ) . '</h2>'
		. '<p class="privacy-policy-tutorial">' . esc_html__( 'Vorschlag für Ihre Datenschutzerklärung. Passen Sie den Text an Ihre Verhältnisse an.', 'mlab-smtp' ) . '</p>'
		. '<p>' . esc_html__( 'Unsere Website versendet E-Mails (zum Beispiel Bestätigungen, Passwort-Zurücksetzung und Benachrichtigungen) über den von uns gewählten Mailserver oder E-Mail-Dienst. Dabei werden die Empfängeradresse und die Nachricht an diesen Dienst übermittelt.', 'mlab-smtp' ) . '</p>'
		. '<p>' . sprintf(
			/* translators: %d: number of days */
			esc_html__( 'Im Versandprotokoll speichern wir je Nachricht die Empfängeradresse, den Betreff, den Versandstatus, Zeitpunkte und gegebenenfalls eine Fehlermeldung. Den Nachrichteninhalt und Anhänge speichern wir standardmässig nicht. Die Protokolleinträge werden nach %d Tagen automatisch gelöscht.', 'mlab-smtp' ),
			$days
		) . '</p>'
		. '<p>' . esc_html__( 'Nur wenn Nachrichten im Hintergrund versendet oder wiederholt werden, liegt die fertige Nachricht samt Anhängen bis zur Annahme durch den Dienst verschlüsselt in der Datenbank und wird danach gelöscht. Auskunft und Löschung erfolgen über die Werkzeuge von WordPress für den Export und das Löschen personenbezogener Daten.', 'mlab-smtp' ) . '</p>';
	wp_add_privacy_policy_content( 'MomentumLab SMTP', wp_kses_post( $text ) );
}
