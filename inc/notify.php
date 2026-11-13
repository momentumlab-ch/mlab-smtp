<?php
/**
 * Benachrichtigungen: bei Fehlern, vor Ablauf des M365-Secrets, Admin-Hinweise und täglicher Cron.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_CRON_HOOK = 'mlab_smtp_daily';

add_action( 'mlab_smtp_failure', 'mlab_smtp_notify_failure', 10, 2 );
add_action( 'shutdown', 'mlab_smtp_notify_flush' );
add_action( MLAB_SMTP_CRON_HOOK, 'mlab_smtp_daily' );
add_action( 'admin_init', 'mlab_smtp_schedule' );
add_action( 'admin_notices', 'mlab_smtp_admin_notice' );
add_action( 'admin_post_mlab_smtp_dismiss_notice', 'mlab_smtp_dismiss_notice' );

/**
 * Plugin activation: plans the daily job.
 */
function mlab_smtp_activate(): void {
	mlab_smtp_schedule();
}

/**
 * Plugin deactivation: removes the daily job.
 */
function mlab_smtp_deactivate(): void {
	wp_clear_scheduled_hook( MLAB_SMTP_CRON_HOOK );
}

/**
 * Plans the daily job when it is missing.
 */
function mlab_smtp_schedule(): void {
	if ( ! wp_next_scheduled( MLAB_SMTP_CRON_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', MLAB_SMTP_CRON_HOOK );
	}
}

/**
 * Recipient of notifications: the configured address, otherwise the admin e-mail.
 */
function mlab_smtp_notify_recipient(): string {
	$email = (string) mlab_smtp_get( 'notify_email' );
	return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
}

/**
 * Sends a notification. Never goes through a failing route: with an active fallback only that is used and,
 * when it fails too, PHP mail() (bypass) gets a try; without a fallback the plugin is bypassed right away.
 * Results of these mails are neither logged nor counted.
 *
 * @param string $subject       Subject.
 * @param string $message       Plain text body.
 * @param bool   $avoid_primary True when the primary route is failing.
 * @return bool True when sent.
 */
function mlab_smtp_send_notification( string $subject, string $message, bool $avoid_primary ): bool {
	$modes = array( '' );
	if ( $avoid_primary ) {
		$modes = mlab_smtp_fallback_ready() ? array( 'fallback_only', 'bypass' ) : array( 'bypass' );
	}
	foreach ( $modes as $mode ) {
		if ( '' !== $mode ) {
			mlab_smtp_flag( $mode, true );
		}
		$sent = wp_mail( mlab_smtp_notify_recipient(), $subject, $message );
		mlab_smtp_flag( 'fallback_only', false );
		mlab_smtp_bypass( false );
		if ( $sent ) {
			return true;
		}
	}
	return false;
}

/**
 * Notification waiting to be sent at the end of the request.
 *
 * @param array|null $set Array with error and fallback to queue one, empty array to clear, null to only read.
 * @return array{error?:string, fallback?:bool} Queued notification, empty when none.
 */
function mlab_smtp_notify_queue( ?array $set = null ): array {
	static $queue = array();
	if ( null !== $set ) {
		$queue = $set;
	}
	return $queue;
}

/**
 * Reacts to a failure of the primary route: queues at most one mail per hour in instant mode. It never sends
 * here, because this runs inside the wp_mail() hooks of the mail that just failed.
 *
 * @param string $error    Error message.
 * @param bool   $fallback True when the fallback delivered the message anyway.
 */
function mlab_smtp_notify_failure( $error, $fallback ): void {
	if ( ! mlab_smtp_get( 'notify_enabled' ) || 'instant' !== mlab_smtp_get( 'notify_mode' ) || get_transient( 'mlab_smtp_notify_throttle' ) || mlab_smtp_notify_queue() ) {
		return;
	}
	mlab_smtp_notify_queue(
		array(
			'error'    => (string) $error,
			'fallback' => (bool) $fallback,
		)
	);
}

/**
 * Sends the queued failure notification at the end of the request (shutdown). The one-hour throttle starts only
 * after a successful send; when every attempt failed, a short 10 minute pause avoids hammering a dead route.
 */
function mlab_smtp_notify_flush(): void {
	$queued = mlab_smtp_notify_queue();
	mlab_smtp_notify_queue( array() );
	if ( ! $queued || get_transient( 'mlab_smtp_notify_throttle' ) ) {
		return;
	}
	$sent = mlab_smtp_send_notification(
		mlab_smtp_notify_subject( $queued['fallback'] ),
		mlab_smtp_notify_body( $queued['error'], $queued['fallback'] ),
		true
	);
	set_transient( 'mlab_smtp_notify_throttle', 1, $sent ? HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
}

/**
 * Subject of a failure notification.
 *
 * @param bool $fallback True when the fallback is delivering.
 */
function mlab_smtp_notify_subject( bool $fallback ): string {
	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	/* translators: %s: site name */
	$format = $fallback ? __( '[%s] E-Mail-Versand: Hauptweg gestört', 'mlab-smtp' ) : __( '[%s] E-Mail-Versand: Mails werden nicht zugestellt', 'mlab-smtp' );
	return sprintf( $format, $site );
}

/**
 * Body of a failure notification.
 *
 * @param string $error    Error message.
 * @param bool   $fallback True when the fallback is delivering.
 */
function mlab_smtp_notify_body( string $error, bool $fallback ): string {
	$intro = $fallback
		? __( 'Der Hauptweg für den E-Mail-Versand Ihrer Website ist gestört. Der Ersatzweg stellt die Mails derzeit zu.', 'mlab-smtp' )
		: __( 'Mails Ihrer Website werden zurzeit nicht zugestellt.', 'mlab-smtp' );
	$hint  = mlab_smtp_explain_error( $error );
	$lines = array( $intro, '', __( 'Fehler:', 'mlab-smtp' ) . ' ' . $error );
	if ( '' !== $hint ) {
		$lines[] = __( 'Was zu tun ist:', 'mlab-smtp' ) . ' ' . $hint;
	}
	$lines[] = '';
	$lines[] = __( 'Details finden Sie hier:', 'mlab-smtp' ) . ' ' . mlab_smtp_page_url();
	return implode( "\n", $lines );
}

/**
 * Daily job: trims the log, reminds about the expiring secret, sends the daily summary.
 */
function mlab_smtp_daily(): void {
	mlab_smtp_log_purge();
	if ( ! mlab_smtp_is_configured() || ! mlab_smtp_get( 'notify_enabled' ) ) {
		return;
	}
	mlab_smtp_notify_expiry();
	$state = mlab_smtp_state_get();
	if ( 'daily' === mlab_smtp_get( 'notify_mode' ) && $state['failing_since'] ) {
		mlab_smtp_send_notification(
			mlab_smtp_notify_subject( (bool) $state['last_fallback'] ),
			mlab_smtp_notify_body( (string) $state['last_error'], (bool) $state['last_fallback'] ),
			true
		);
	}
}

/**
 * Days until the Microsoft 365 secret expires, null when not applicable.
 */
function mlab_smtp_secret_days_left(): ?int {
	$expires = (string) mlab_smtp_get( 'm365_secret_expires' );
	if ( 'm365' !== mlab_smtp_get( 'provider' ) || '' === $expires ) {
		return null;
	}
	$date = DateTime::createFromFormat( '!Y-m-d', $expires, wp_timezone() );
	if ( ! $date ) {
		return null;
	}
	return (int) floor( ( $date->getTimestamp() - time() ) / DAY_IN_SECONDS );
}

/**
 * Sends one reminder per threshold (30, 7, 1 day, expired) for the current expiry date.
 */
function mlab_smtp_notify_expiry(): void {
	$days = mlab_smtp_secret_days_left();
	if ( null === $days || $days > 30 ) {
		return;
	}
	$level = 0;
	foreach ( array( 30, 7, 1 ) as $threshold ) {
		$level = $days <= $threshold ? $threshold : $level;
	}
	$level = $days < 0 ? 0 : $level;
	$state = mlab_smtp_state_get();
	$seen  = $state['expiry_notified'];
	$date  = (string) mlab_smtp_get( 'm365_secret_expires' );
	if ( ( $seen['date'] ?? '' ) === $date && (int) ( $seen['level'] ?? 99 ) <= $level ) {
		return;
	}
	mlab_smtp_state_set(
		array(
			'expiry_notified' => array(
				'date'  => $date,
				'level' => $level,
			),
		)
	);
	mlab_smtp_send_notification(
		/* translators: %s: site name */
		sprintf( __( '[%s] Microsoft-365-Secret läuft ab', 'mlab-smtp' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
		mlab_smtp_expiry_body( $days ),
		false
	);
}

/**
 * Body of the expiry reminder.
 *
 * @param int $days Days left, negative when expired.
 */
function mlab_smtp_expiry_body( int $days ): string {
	$when = $days < 0
		? __( 'Das Client-Secret für Microsoft 365 ist abgelaufen. Mails werden nicht mehr zugestellt.', 'mlab-smtp' )
		/* translators: %d: number of days */
		: sprintf( _n( 'Das Client-Secret für Microsoft 365 läuft in %d Tag ab.', 'Das Client-Secret für Microsoft 365 läuft in %d Tagen ab.', $days, 'mlab-smtp' ), $days );
	return $when . "\n\n" . __( 'Erstellen Sie in Microsoft Entra ein neues Secret und tragen Sie es samt Ablaufdatum hier ein:', 'mlab-smtp' ) . ' ' . mlab_smtp_page_url( array( 'view' => 'settings' ) );
}

/**
 * True when the current admin screen is the plugin page.
 */
function mlab_smtp_is_plugin_screen(): bool {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	return $screen && 'settings_page_mlab-smtp' === $screen->id;
}

/**
 * Shows a notice while the plugin is not set up (Dashboard and Plugins page, can be dismissed)
 * or while the send route has a problem (everywhere).
 */
function mlab_smtp_admin_notice(): void {
	if ( ! current_user_can( 'manage_options' ) || mlab_smtp_is_plugin_screen() ) {
		return;
	}
	$status = mlab_smtp_status();
	if ( 'none' === $status['state'] ) {
		mlab_smtp_notice_setup();
	} elseif ( in_array( $status['state'], array( 'bad', 'warn' ), true ) ) {
		printf(
			'<div class="notice notice-%1$s"><p><strong>%2$s</strong> %3$s <a href="%4$s">%5$s</a></p></div>',
			'bad' === $status['state'] ? 'error' : 'warning',
			esc_html( $status['title'] ) . '.',
			esc_html( $status['text'] ),
			esc_url( mlab_smtp_page_url() ),
			esc_html__( 'Details ansehen', 'mlab-smtp' )
		);
	}
}

/**
 * Notice for the not yet set up plugin.
 */
function mlab_smtp_notice_setup(): void {
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) || get_user_meta( get_current_user_id(), 'mlab_smtp_dismissed', true ) ) {
		return;
	}
	$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=mlab_smtp_dismiss_notice' ), 'mlab_smtp_dismiss_notice' );
	printf(
		'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a> | <a href="%4$s">%5$s</a></p></div>',
		esc_html__( 'E-Mail-Versand: MomentumLab SMTP ist noch nicht eingerichtet. Bis dahin versendet WordPress Mails über den Webserver, und viele landen im Spam.', 'mlab-smtp' ),
		esc_url( mlab_smtp_page_url( array( 'view' => 'wizard' ) ) ),
		esc_html__( 'Jetzt einrichten', 'mlab-smtp' ),
		esc_url( $dismiss ),
		esc_html__( 'Hinweis ausblenden', 'mlab-smtp' )
	);
}

/**
 * Hides the setup notice for the current user.
 */
function mlab_smtp_dismiss_notice(): void {
	mlab_smtp_guard( 'mlab_smtp_dismiss_notice' );
	update_user_meta( get_current_user_id(), 'mlab_smtp_dismissed', 1 );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
}
