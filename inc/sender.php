<?php
/**
 * Sender identities resolved separately for primary and backup routes.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Effective sender; empty backup fields inherit existing primary values.
 *
 * @param string $route primary or fallback.
 * @return array<string,string>
 */
function mlab_smtp_sender_for_route( string $route ): array {
	$out = array();
	foreach ( array(
		'email'    => 'from_email',
		'name'     => 'from_name',
		'reply_to' => 'reply_to',
	) as $field => $key ) {
		$value         = 'fallback' === $route ? (string) mlab_smtp_get( 'fb_' . $key ) : '';
		$out[ $field ] = '' !== $value ? $value : (string) mlab_smtp_get( $key );
	}
	return $out;
}

/**
 * Applies the backup identity before either SMTP MIME rebuilding or API submission.
 * An explicit backup reply address overrides message replies; otherwise form replies survive.
 *
 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Prepared message.
 * @throws \PHPMailer\PHPMailer\Exception When the backup sender is invalid.
 */
function mlab_smtp_apply_fallback_sender( $mailer ): void {
	$sender = mlab_smtp_sender_for_route( 'fallback' );
	if ( ! is_email( $sender['email'] ) ) {
		throw new \PHPMailer\PHPMailer\Exception( esc_html__( 'Es ist keine gültige Absender-Adresse für den Ersatzweg hinterlegt.', 'mlab-smtp' ) );
	}
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
	$mailer->setFrom( $sender['email'], $sender['name'], false );
	$mailer->Sender = $sender['email'];
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	if ( '' !== (string) mlab_smtp_get( 'fb_reply_to' ) ) {
		$mailer->clearReplyTos();
		$mailer->addReplyTo( $sender['reply_to'] );
	}
}
