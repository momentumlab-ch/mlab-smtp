<?php
/**
 * Versandprotokoll: die letzten Nachrichten mit Ergebnis, ohne Inhalt.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_LOG_OPTION = 'mlab_smtp_log';
const MLAB_SMTP_LOG_LIMIT  = 50;

add_action( 'wp_mail_succeeded', 'mlab_smtp_log_success' );
add_action( 'wp_mail_failed', 'mlab_smtp_log_failure' );
add_action( 'admin_post_mlab_smtp_clear_log', 'mlab_smtp_clear_log' );

/**
 * Entries, newest first.
 *
 * @return array<int, array{time:int, to:string, subject:string, ok:bool, error:string}>
 */
function mlab_smtp_log_get(): array {
	$log = get_option( MLAB_SMTP_LOG_OPTION, array() );
	return is_array( $log ) ? $log : array();
}

/**
 * Adds one entry and trims the log.
 *
 * @param array       $mail_data Data wp_mail() passes to its hooks (to, subject).
 * @param string|null $error     Error message, or null on success.
 */
function mlab_smtp_log_add( array $mail_data, ?string $error ): void {
	if ( ! mlab_smtp_get( 'log_enabled' ) ) {
		return;
	}
	$log = mlab_smtp_log_get();
	array_unshift(
		$log,
		array(
			'time'    => time(),
			'to'      => sanitize_text_field( implode( ', ', (array) ( $mail_data['to'] ?? array() ) ) ),
			'subject' => sanitize_text_field( (string) ( $mail_data['subject'] ?? '' ) ),
			'ok'      => null === $error,
			'error'   => sanitize_text_field( (string) $error ),
		)
	);
	update_option( MLAB_SMTP_LOG_OPTION, array_slice( $log, 0, MLAB_SMTP_LOG_LIMIT ), false );
}

/**
 * Logs a sent message.
 *
 * @param array $mail_data Data of the sent message.
 */
function mlab_smtp_log_success( $mail_data ): void {
	mlab_smtp_log_add( (array) $mail_data, null );
}

/**
 * Logs a failed message.
 *
 * @param WP_Error $error Error of wp_mail().
 */
function mlab_smtp_log_failure( $error ): void {
	if ( is_wp_error( $error ) ) {
		mlab_smtp_log_add( (array) $error->get_error_data(), $error->get_error_message() );
	}
}

/**
 * Empties the log.
 */
function mlab_smtp_clear_log(): void {
	mlab_smtp_guard( 'mlab_smtp_clear_log' );
	delete_option( MLAB_SMTP_LOG_OPTION );
	mlab_smtp_flash( 'success', __( 'Das Protokoll wurde geleert.', 'mlab-smtp' ) );
	mlab_smtp_back();
}
