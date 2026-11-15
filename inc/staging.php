<?php
/**
 * Staging-Schutz: verhindert, dass eine kopierte Website echte Mails an echte Empfänger schickt.
 *
 * Modi: off | block | redirect | auto (Standard). Die Konstante MLAB_SMTP_STAGING ('off', 'block', 'redirect')
 * übersteuert die Einstellung. Einstellungen liegen in der eigenen Option mlab_smtp_staging (kein Autoload).
 *
 * API für die Oberfläche: mlab_smtp_staging_get(), mlab_smtp_staging_save(), mlab_smtp_staging_status(),
 * mlab_smtp_staging_confirm_production(), mlab_smtp_staging_banner(). Admin-Aktionen: admin-post.php?action=
 * mlab_smtp_staging_confirm und mlab_smtp_staging_save (Nonce-Aktion gleich dem Aktionsnamen).
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_STAGING_OPTION = 'mlab_smtp_staging';

add_filter( 'pre_wp_mail', 'mlab_smtp_staging_pre_wp_mail', 5, 2 );
add_filter( 'wp_mail', 'mlab_smtp_staging_redirect_mail', PHP_INT_MAX ); // Last word: later filters (BCC plugins) cannot add recipients.
add_action( 'phpmailer_init', 'mlab_smtp_staging_lock_recipients', PHP_INT_MAX );
add_action( 'init', 'mlab_smtp_staging_init' );
add_action( 'admin_notices', 'mlab_smtp_staging_notice', 1 );
add_action( 'network_admin_notices', 'mlab_smtp_staging_notice', 1 );
add_action( 'admin_post_mlab_smtp_staging_confirm', 'mlab_smtp_staging_handle_confirm' );
add_action( 'admin_post_mlab_smtp_staging_save', 'mlab_smtp_staging_handle_save' );

/**
 * Stored settings with defaults.
 *
 * @return array{mode:string, test_email:string, fingerprint:string, env_hash:string, confirmed_env:string}
 */
function mlab_smtp_staging_get(): array {
	$stored = get_option( MLAB_SMTP_STAGING_OPTION, array() );
	return wp_parse_args(
		is_array( $stored ) ? $stored : array(),
		array(
			'mode'          => 'auto',
			'test_email'    => '',
			'fingerprint'   => '',
			'env_hash'      => '',
			'confirmed_env' => '',
		)
	);
}

/**
 * Writes settings (merged into the stored ones).
 *
 * @param array $changes Key => value.
 */
function mlab_smtp_staging_put( array $changes ): void {
	update_option( MLAB_SMTP_STAGING_OPTION, array_merge( mlab_smtp_staging_get(), $changes ), false );
}

/**
 * Hash of the site address. A copy under another address gets another hash.
 */
function mlab_smtp_staging_fingerprint(): string {
	return hash( 'sha256', untrailingslashit( strtolower( home_url() ) ) );
}

/**
 * Origin marker of a stored message: the hash of the site address that created it. A cloned database brings rows with another one.
 */
function mlab_smtp_staging_origin_id(): string {
	return mlab_smtp_staging_fingerprint();
}

/**
 * True when the worker must not send a stored message in this environment (a copy of a production database must never mail customers).
 * Mode off sends. Block blocks everything. Redirect lets only messages through that this site created while it redirected (they are rewritten already).
 *
 * @param array $snapshot Snapshot with the origin marker and the staging state at enqueue time.
 */
function mlab_smtp_staging_blocks_stored( array $snapshot ): bool {
	$effective = mlab_smtp_staging_status()['effective'];
	if ( 'off' === $effective ) {
		return false;
	}
	if ( 'redirect' === $effective ) {
		$origin = (string) ( $snapshot['origin'] ?? '' );
		return '' === $origin || ! hash_equals( mlab_smtp_staging_origin_id(), $origin ) || 'redirect' !== (string) ( $snapshot['staging'] ?? '' );
	}
	return true;
}

/**
 * Hash of the server environment (path, database host and name). A change is only a warning, never a block.
 */
function mlab_smtp_staging_env_hash(): string {
	$host = defined( 'DB_HOST' ) ? (string) DB_HOST : '';
	$name = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
	return hash( 'sha256', ABSPATH . '|' . $host . '|' . $name );
}

/**
 * Environment type of WordPress. The filter mlab_smtp_environment_type lets tests and hosts override it.
 */
function mlab_smtp_staging_env_type(): string {
	return (string) apply_filters( 'mlab_smtp_environment_type', wp_get_environment_type() );
}

/**
 * First run: remember the installation.
 */
function mlab_smtp_staging_init(): void {
	if ( '' === mlab_smtp_staging_get()['fingerprint'] ) {
		mlab_smtp_staging_put(
			array(
				'fingerprint' => mlab_smtp_staging_fingerprint(),
				'env_hash'    => mlab_smtp_staging_env_hash(),
			)
		);
	}
}

/**
 * Validates and stores the settings.
 *
 * @param array $input mode (off|block|redirect|auto), test_email.
 * @return string[] German error messages, empty when saved.
 */
function mlab_smtp_staging_save( array $input ): array {
	$errors = array();
	$mode   = sanitize_key( (string) ( $input['mode'] ?? 'auto' ) );
	$email  = sanitize_email( trim( (string) ( $input['test_email'] ?? '' ) ) );
	if ( ! in_array( $mode, array( 'off', 'block', 'redirect', 'auto' ), true ) ) {
		$errors[] = __( 'Dieser Modus ist unbekannt.', 'mlab-smtp' );
	}
	if ( '' !== trim( (string) ( $input['test_email'] ?? '' ) ) && ! is_email( $email ) ) {
		$errors[] = __( 'Die Testadresse ist keine gültige E-Mail-Adresse.', 'mlab-smtp' );
	}
	if ( 'redirect' === $mode && ! is_email( $email ) ) {
		$errors[] = __( 'Zum Umleiten wird eine gültige Testadresse gebraucht.', 'mlab-smtp' );
	}
	if ( ! $errors ) {
		mlab_smtp_staging_put(
			array(
				'mode'       => $mode,
				'test_email' => $email,
			)
		);
	}
	return $errors;
}

/**
 * Configured mode: constant first, then the setting.
 *
 * @return array{0:string,1:bool} Mode and whether the constant set it.
 */
function mlab_smtp_staging_configured_mode(): array {
	if ( defined( 'MLAB_SMTP_STAGING' ) && in_array( MLAB_SMTP_STAGING, array( 'off', 'block', 'redirect' ), true ) ) {
		return array( (string) MLAB_SMTP_STAGING, true );
	}
	return array( (string) mlab_smtp_staging_get()['mode'], false );
}

/**
 * Detection in auto mode.
 *
 * @param array $stored Stored settings.
 * @return array{blocked:bool, reason:string, text:string}
 */
function mlab_smtp_staging_detect( array $stored ): array {
	if ( '' !== $stored['fingerprint'] && ! hash_equals( (string) $stored['fingerprint'], mlab_smtp_staging_fingerprint() ) ) {
		return array(
			'blocked' => true,
			'reason'  => 'url_changed',
			'text'    => __( 'Die Adresse der Website hat sich seit der Einrichtung geändert. Das sieht nach einer Kopie aus.', 'mlab-smtp' ),
		);
	}
	$type = mlab_smtp_staging_env_type();
	if ( 'production' !== $type && $stored['confirmed_env'] !== $type ) {
		return array(
			'blocked' => true,
			'reason'  => 'environment',
			/* translators: %s: environment type such as staging */
			'text'    => sprintf( __( 'WordPress meldet die Umgebung «%s» und nicht «production».', 'mlab-smtp' ), $type ),
		);
	}
	return array(
		'blocked' => false,
		'reason'  => '',
		'text'    => '',
	);
}

/**
 * Current protection state for the UI and the mail hooks.
 *
 * @return array{mode:string, effective:string, reason:string, warning:string, redirect_to:string, text:string}
 */
function mlab_smtp_staging_status(): array {
	$stored                     = mlab_smtp_staging_get();
	list( $mode, $by_constant ) = mlab_smtp_staging_configured_mode();
	$redirect_to                = is_email( $stored['test_email'] ) ? (string) $stored['test_email'] : '';
	$detected                   = array(
		'blocked' => false,
		'reason'  => '',
		'text'    => '',
	);
	$effective                  = $mode;
	$reason                     = $by_constant ? 'constant' : 'setting';
	if ( 'auto' === $mode ) {
		$detected  = mlab_smtp_staging_detect( $stored );
		$effective = $detected['blocked'] ? 'block' : 'off';
		$reason    = $detected['blocked'] ? $detected['reason'] : 'none';
	} elseif ( 'redirect' === $mode && '' === $redirect_to ) {
		$effective = 'block';
		$reason    = 'no_test_address';
	}
	$warning = '';
	if ( '' !== $stored['env_hash'] && ! hash_equals( (string) $stored['env_hash'], mlab_smtp_staging_env_hash() ) ) {
		$warning = __( 'Pfad oder Datenbank des Servers haben sich geändert. Mails werden weiter versendet. Bitte prüfen, ob das gewollt ist.', 'mlab-smtp' );
	}
	return array(
		'mode'        => $mode,
		'effective'   => $effective,
		'reason'      => $reason,
		'warning'     => $warning,
		'redirect_to' => $redirect_to,
		'text'        => mlab_smtp_staging_text( $effective, $reason, $detected['text'], $redirect_to ),
	);
}

/**
 * German explanation of the state.
 *
 * @param string $effective   off, block or redirect.
 * @param string $reason      Reason code.
 * @param string $detected    Text of the detection.
 * @param string $redirect_to Test address.
 */
function mlab_smtp_staging_text( string $effective, string $reason, string $detected, string $redirect_to ): string {
	if ( 'block' === $effective ) {
		$why = '' !== $detected ? $detected . ' ' : '';
		if ( 'no_test_address' === $reason ) {
			$why = __( 'Es ist keine gültige Testadresse eingetragen. ', 'mlab-smtp' );
		}
		return $why . __( 'Es werden keine E-Mails versendet, bis ein Administrator bestätigt, dass dies die produktive Website ist, oder das Umleiten wählt.', 'mlab-smtp' );
	}
	if ( 'redirect' === $effective ) {
		/* translators: %s: test address */
		return sprintf( __( 'Alle E-Mails gehen an die Testadresse %s. Kopien (Cc, Bcc) entfallen.', 'mlab-smtp' ), $redirect_to );
	}
	return __( 'Der Versand ist nicht eingeschränkt.', 'mlab-smtp' );
}

/**
 * The administrator confirms that this is the production site (or accepts the non-production environment once).
 */
function mlab_smtp_staging_confirm_production(): bool {
	mlab_smtp_staging_put(
		array(
			'fingerprint'   => mlab_smtp_staging_fingerprint(),
			'env_hash'      => mlab_smtp_staging_env_hash(),
			'confirmed_env' => mlab_smtp_staging_env_type(),
		)
	);
	return true;
}

/**
 * Filter pre_wp_mail at priority 5: blocks the mail before the plugin's own handler runs.
 *
 * @param mixed $pre  Short circuit value.
 * @param array $atts wp_mail() arguments.
 * @return mixed false when blocked, otherwise unchanged.
 */
function mlab_smtp_staging_pre_wp_mail( $pre, $atts = array() ) {
	if ( null !== $pre || 'block' !== mlab_smtp_staging_status()['effective'] ) {
		return $pre;
	}
	$atts  = (array) $atts;
	$to    = (array) ( $atts['to'] ?? array() );
	$error = new WP_Error(
		'mlab_smtp_blocked',
		__( 'Der Versand ist durch den Staging-Schutz blockiert. Es wurde nichts gesendet.', 'mlab-smtp' ),
		array(
			'to'      => $to,
			'subject' => (string) ( $atts['subject'] ?? '' ),
		)
	);
	mlab_smtp_staging_record_blocked( $to, (string) ( $atts['subject'] ?? '' ), $error->get_error_message() );
	// The plugin's own failure listeners would log a second row and count a delivery failure.
	remove_action( 'wp_mail_failed', 'mlab_smtp_log_failure' );
	remove_action( 'wp_mail_failed', 'mlab_smtp_state_on_failure' );
	do_action( 'wp_mail_failed', $error ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook, deliberately fired for other listeners.
	add_action( 'wp_mail_failed', 'mlab_smtp_log_failure' );
	add_action( 'wp_mail_failed', 'mlab_smtp_state_on_failure' );
	return false;
}

/**
 * Records a blocked message when the tables exist. Never fatal.
 *
 * @param array  $to      Recipients.
 * @param string $subject Subject.
 * @param string $text    Reason.
 */
function mlab_smtp_staging_record_blocked( array $to, string $subject, string $text ): void {
	if ( ! function_exists( 'mlab_smtp_store_create' ) || ! mlab_smtp_get( 'log_enabled' ) ) {
		return;
	}
	try {
		mlab_smtp_store_create(
			array(
				'recipients' => array_map( 'strval', $to ),
				'subject'    => $subject,
				'status'     => 'blocked',
				'last_error' => $text,
				'mode'       => 'sync',
			)
		);
	} catch ( Throwable $e ) {
		unset( $e );
	}
}

/**
 * Filter wp_mail: in redirect mode the mail goes to the test address only.
 *
 * @param array $atts to, subject, message, headers, attachments.
 * @return array
 */
function mlab_smtp_staging_redirect_mail( $atts ) {
	$status = mlab_smtp_staging_status();
	if ( 'redirect' !== $status['effective'] || ! is_array( $atts ) ) {
		return $atts;
	}
	$original = mlab_smtp_staging_original_recipients( $atts );
	$html     = mlab_smtp_staging_is_html( (array) ( $atts['headers'] ?? array() ) );
	// Masked: the mail reaches the provider and the test address, real customer addresses must not.
	$list            = mlab_smtp_mask_emails_in( implode( ', ', $original ) );
	$footer          = $html
		? '<hr><p>' . esc_html__( 'Staging-Umleitung. Ursprüngliche Empfänger:', 'mlab-smtp' ) . ' ' . esc_html( $list ) . '</p>'
		: "\n\n-- \n" . __( 'Staging-Umleitung. Ursprüngliche Empfänger:', 'mlab-smtp' ) . ' ' . $list;
	$atts['to']      = $status['redirect_to'];
	$atts['headers'] = mlab_smtp_staging_strip_copies( $atts['headers'] ?? array() );
	$atts['subject'] = '[Staging] ' . (string) ( $atts['subject'] ?? '' );
	$atts['message'] = (string) ( $atts['message'] ?? '' ) . $footer;
	return $atts;
}

/**
 * Action phpmailer_init at the last priority: in redirect mode only the test address stays a recipient, whatever a later hook added to the mailer.
 *
 * @param object $mailer PHPMailer of wp_mail().
 */
function mlab_smtp_staging_lock_recipients( $mailer ): void {
	if ( ! $mailer instanceof Mlab_Smtp_Mailer ) {
		return;
	}
	$status = mlab_smtp_staging_status();
	if ( 'redirect' !== $status['effective'] || '' === $status['redirect_to'] ) {
		return;
	}
	$to = array_map( static fn( $pair ) => strtolower( (string) $pair[0] ), $mailer->getToAddresses() );
	if ( array( strtolower( $status['redirect_to'] ) ) === $to && ! $mailer->getCcAddresses() && ! $mailer->getBccAddresses() ) {
		return;
	}
	$mailer->clearAllRecipients();
	$mailer->addAddress( $status['redirect_to'] );
}

/**
 * Original to, cc and bcc addresses (raw strings) of a wp_mail() call.
 *
 * @param array $atts wp_mail() arguments.
 * @return string[]
 */
function mlab_smtp_staging_original_recipients( array $atts ): array {
	$out = array();
	foreach ( (array) ( $atts['to'] ?? array() ) as $to ) {
		$out[] = 'To: ' . trim( (string) $to );
	}
	foreach ( mlab_smtp_staging_header_lines( $atts['headers'] ?? array() ) as $line ) {
		if ( preg_match( '/^(cc|bcc)\s*:\s*(.+)$/i', $line, $m ) ) {
			$out[] = ucfirst( strtolower( $m[1] ) ) . ': ' . trim( $m[2] );
		}
	}
	return $out;
}

/**
 * Header lines of a string or array headers argument.
 *
 * @param string|array $headers Headers.
 * @return string[]
 */
function mlab_smtp_staging_header_lines( $headers ): array {
	if ( ! is_array( $headers ) ) {
		$headers = explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
	}
	return array_values( array_filter( array_map( static fn( $h ) => trim( (string) $h ), $headers ), 'strlen' ) );
}

/**
 * Headers without Cc and Bcc. The result keeps the type (string or array) of the input.
 *
 * @param string|array $headers Headers.
 * @return string|array
 */
function mlab_smtp_staging_strip_copies( $headers ) {
	$keep = array_values(
		array_filter(
			mlab_smtp_staging_header_lines( $headers ),
			static fn( $line ) => ! preg_match( '/^(cc|bcc)\s*:/i', $line )
		)
	);
	return is_array( $headers ) ? $keep : implode( "\n", $keep );
}

/**
 * True when the headers ask for HTML.
 *
 * @param array $headers Headers.
 */
function mlab_smtp_staging_is_html( array $headers ): bool {
	foreach ( mlab_smtp_staging_header_lines( $headers ) as $line ) {
		if ( preg_match( '/^content-type\s*:.*text\/html/i', $line ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Banner HTML for the current state, empty when nothing needs attention.
 *
 * @param bool $output True to print as well.
 * @return string HTML (already escaped).
 */
function mlab_smtp_staging_banner( bool $output = false ): string {
	$status = mlab_smtp_staging_status();
	$html   = '';
	if ( 'off' !== $status['effective'] ) {
		$title = 'block' === $status['effective'] ? __( 'E-Mail-Versand blockiert (Staging-Schutz).', 'mlab-smtp' ) : __( 'E-Mails werden umgeleitet (Staging-Schutz).', 'mlab-smtp' );
		$html  = '<div class="notice notice-error mlab-smtp-staging-banner"><p><strong>' . esc_html( $title ) . '</strong> ' . esc_html( $status['text'] ) . '</p>'
			. mlab_smtp_staging_buttons( $status ) . '</div>';
	} elseif ( '' !== $status['warning'] ) {
		$html = '<div class="notice notice-warning mlab-smtp-staging-banner"><p>' . esc_html( $status['warning'] ) . '</p>' . mlab_smtp_staging_buttons( $status ) . '</div>';
	}
	if ( $output ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped when built.
	}
	return $html;
}

/**
 * Buttons of the banner: confirm (form with nonce) and open settings.
 *
 * @param array $status Result of mlab_smtp_staging_status().
 */
function mlab_smtp_staging_buttons( array $status ): string {
	$confirm = '';
	if ( 'constant' !== $status['reason'] ) {
		$confirm = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">'
			. '<input type="hidden" name="action" value="mlab_smtp_staging_confirm">'
			. wp_nonce_field( 'mlab_smtp_staging_confirm', '_wpnonce', true, false )
			. '<button type="submit" class="button button-primary">' . esc_html__( 'Das ist die produktive Website', 'mlab-smtp' ) . '</button></form> ';
	}
	return '<p>' . $confirm . '<a class="button" href="' . esc_url( mlab_smtp_staging_settings_url() ) . '">' . esc_html__( 'Einstellungen öffnen', 'mlab-smtp' ) . '</a></p>';
}

/**
 * Settings page URL (fallback while the admin page module is not loaded).
 */
function mlab_smtp_staging_settings_url(): string {
	return function_exists( 'mlab_smtp_page_url' ) ? mlab_smtp_page_url( array( 'view' => 'settings' ) ) : admin_url( 'admin.php?page=mlab-smtp&view=settings' );
}

/**
 * Notice on every admin screen for administrators.
 */
function mlab_smtp_staging_notice(): void {
	if ( current_user_can( 'manage_options' ) ) {
		mlab_smtp_staging_banner( true );
	}
}

/**
 * Permission and nonce check of the admin-post handlers.
 *
 * @param string $action Nonce action.
 */
function mlab_smtp_staging_guard( string $action ): void {
	if ( function_exists( 'mlab_smtp_guard' ) ) {
		mlab_smtp_guard( $action );
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Dafür fehlt die Berechtigung.', 'mlab-smtp' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( $action );
}

/**
 * Redirects back to the settings and stops.
 */
function mlab_smtp_staging_back(): void {
	wp_safe_redirect( mlab_smtp_staging_settings_url() );
	exit;
}

/**
 * Admin-post handler: confirm production.
 */
function mlab_smtp_staging_handle_confirm(): void {
	mlab_smtp_staging_guard( 'mlab_smtp_staging_confirm' );
	mlab_smtp_staging_confirm_production();
	if ( function_exists( 'mlab_smtp_flash' ) ) {
		mlab_smtp_flash( 'success', __( 'Bestätigt. Der Versand ist freigegeben.', 'mlab-smtp' ) );
	}
	mlab_smtp_staging_back();
}

/**
 * Admin-post handler: save the settings.
 */
function mlab_smtp_staging_handle_save(): void {
	mlab_smtp_staging_guard( 'mlab_smtp_staging_save' );
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in mlab_smtp_staging_guard().
	$input  = array_map( 'wp_unslash', array_map( 'strval', array_intersect_key( $_POST, array_flip( array( 'mode', 'test_email' ) ) ) ) );
	$errors = mlab_smtp_staging_save( $input );
	if ( function_exists( 'mlab_smtp_flash' ) ) {
		$errors ? mlab_smtp_flash( 'error', implode( ' ', $errors ) ) : mlab_smtp_flash( 'success', __( 'Der Staging-Schutz ist gespeichert.', 'mlab-smtp' ) );
	}
	mlab_smtp_staging_back();
}
