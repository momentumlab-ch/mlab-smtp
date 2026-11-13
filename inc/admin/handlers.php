<?php
/**
 * Admin-post handlers: wizard steps, settings, test mail, clearing the log.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_mlab_smtp_wizard', 'mlab_smtp_handle_wizard' );
add_action( 'admin_post_mlab_smtp_save_settings', 'mlab_smtp_handle_save_settings' );
add_action( 'admin_post_mlab_smtp_test', 'mlab_smtp_handle_test' );
add_action( 'admin_post_mlab_smtp_clear_log_confirmed', 'mlab_smtp_handle_clear_log' );

/**
 * Setting keys the UI may save, grouped by form section. Every save names its group explicitly.
 *
 * @return array<string, string[]>
 */
function mlab_smtp_key_groups(): array {
	$sender   = array( 'from_name', 'from_email', 'reply_to' );
	$fallback = array( 'fallback_enabled', 'fb_smtp_host', 'fb_smtp_port', 'fb_smtp_secure', 'fb_smtp_auth', 'fb_smtp_user', 'fb_smtp_pass' );
	return array(
		'smtp'     => array( 'provider', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_auth', 'smtp_user', 'smtp_pass' ),
		'm365'     => array( 'provider', 'm365_tenant', 'm365_client_id', 'm365_client_secret', 'm365_secret_expires', 'from_email' ),
		'google'   => array( 'provider', 'google_client_id', 'google_client_secret' ),
		'sender'   => $sender,
		'fallback' => $fallback,
		'settings' => array_merge( $sender, $fallback, array( 'force_from', 'notify_enabled', 'notify_email', 'notify_mode', 'log_enabled', 'log_retention' ) ),
	);
}

/**
 * Saves one group of settings. A changed connection invalidates an earlier check through its fingerprint.
 *
 * @param string $group Group name from mlab_smtp_key_groups().
 * @param array  $input Raw, unslashed form data.
 * @return string[] Error messages, empty on success.
 */
function mlab_smtp_save_group( string $group, array $input ): array {
	$groups = mlab_smtp_key_groups();
	if ( ! isset( $groups[ $group ] ) ) {
		return array( __( 'Unbekannte Eingabe.', 'mlab-smtp' ) );
	}
	return mlab_smtp_save_fields( $input, $groups[ $group ] );
}

/**
 * Raw POST data, unslashed. Values are sanitized per key by mlab_smtp_save_fields().
 *
 * @return array<string, mixed>
 */
function mlab_smtp_post_input(): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in mlab_smtp_guard(); secrets must not be trimmed or altered here.
	return (array) wp_unslash( $_POST );
}

/**
 * Fingerprint of the stored main connection. A check counts only as long as the connection is unchanged.
 */
function mlab_smtp_connection_fingerprint(): string {
	$provider = (string) mlab_smtp_get( 'provider' );
	$keys     = mlab_smtp_key_groups()[ $provider ] ?? array();
	$values   = array_map( 'mlab_smtp_get', $keys );
	return hash_hmac( 'sha256', $provider . wp_json_encode( $values ), wp_salt( 'auth' ) );
}

/**
 * One-hour flag per user that remembers a successful check or test mail of the wizard.
 * The check flag is bound to the connection fingerprint, so editing the connection resets it.
 *
 * @param string    $name verified or tested.
 * @param bool|null $set  true or false to change the flag, null to read it.
 */
function mlab_smtp_wizard_flag( string $name, ?bool $set = null ): bool {
	$key   = 'mlab_smtp_' . $name . '_' . get_current_user_id();
	$value = 'verified' === $name ? mlab_smtp_connection_fingerprint() : '1';
	if ( null === $set ) {
		return get_transient( $key ) === $value;
	}
	if ( $set ) {
		set_transient( $key, $value, HOUR_IN_SECONDS );
	} else {
		delete_transient( $key );
	}
	return $set;
}

/**
 * Wizard URL.
 *
 * @param int    $step     Step 1 to 4.
 * @param string $provider Provider key.
 */
function mlab_smtp_wizard_url( int $step, string $provider ): string {
	return mlab_smtp_page_url(
		array(
			'view'     => 'wizard',
			'step'     => $step,
			'provider' => $provider,
		)
	);
}

/**
 * Dispatches a wizard form post to the handler of its step.
 */
function mlab_smtp_handle_wizard(): void {
	mlab_smtp_guard( 'mlab_smtp_wizard' );
	$input    = mlab_smtp_post_input();
	$step     = max( 1, min( 4, (int) ( $input['step'] ?? 1 ) ) );
	$provider = mlab_smtp_pick( $input['provider'] ?? '', array( 'smtp', 'm365', 'google' ), 'smtp' );
	$action   = (string) ( $input['do'] ?? 'next' );
	$steps    = array(
		1 => 'mlab_smtp_wizard_step_provider',
		2 => 'mlab_smtp_wizard_step_connect',
		3 => 'mlab_smtp_wizard_step_sender',
		4 => 'mlab_smtp_wizard_step_test',
	);
	call_user_func( $steps[ $step ], $input, $provider, $action );
	mlab_smtp_back( array( 'view' => 'wizard' ) );
}

/**
 * Wizard step 1: remember the choice in the URL, nothing is stored yet.
 *
 * @param array  $input    Form data.
 * @param string $provider Chosen provider.
 */
function mlab_smtp_wizard_step_provider( array $input, string $provider ): void {
	wp_safe_redirect( mlab_smtp_wizard_url( 2, $provider ) );
	exit;
}

/**
 * Wizard step 2: save the connection data, then check, sign in with Google, or continue.
 *
 * @param array  $input    Form data.
 * @param string $provider Chosen provider.
 * @param string $action   do value: check, google or next.
 */
function mlab_smtp_wizard_step_connect( array $input, string $provider, string $action ): void {
	$input['provider'] = $provider;
	$errors            = mlab_smtp_save_group( $provider, $input );
	if ( $errors ) {
		mlab_smtp_flash( 'error', implode( ' ', $errors ) );
	} elseif ( 'google' === $action ) {
		wp_safe_redirect( mlab_smtp_google_connect_url() );
		exit;
	} elseif ( 'check' === $action ) {
		mlab_smtp_flash_check( 'primary' );
	} elseif ( mlab_smtp_wizard_flag( 'verified' ) ) {
		wp_safe_redirect( mlab_smtp_wizard_url( 3, $provider ) );
		exit;
	} else {
		mlab_smtp_flash( 'error', __( 'Bitte prüfen Sie zuerst die Verbindung.', 'mlab-smtp' ) );
	}
	wp_safe_redirect( mlab_smtp_wizard_url( 2, $provider ) );
	exit;
}

/**
 * Runs a connection check without JavaScript and leaves the result as a notice.
 *
 * @param string $route primary or fallback.
 */
function mlab_smtp_flash_check( string $route ): void {
	$result = mlab_smtp_run_check( $route );
	if ( $result['ok'] ) {
		mlab_smtp_flash( 'success', $result['message'] );
		return;
	}
	mlab_smtp_flash( 'error', $result['message'], $result['detail'] );
}

/**
 * Wizard step 3: save the sender and continue to the test mail.
 *
 * @param array  $input    Form data.
 * @param string $provider Chosen provider.
 */
function mlab_smtp_wizard_step_sender( array $input, string $provider ): void {
	$errors = mlab_smtp_save_group( 'sender', $input );
	if ( $errors ) {
		mlab_smtp_flash( 'error', implode( ' ', $errors ) );
	}
	wp_safe_redirect( mlab_smtp_wizard_url( $errors ? 3 : 4, $provider ) );
	exit;
}

/**
 * Wizard step 4: send the test mail without JavaScript, or finish the setup after a successful one.
 *
 * @param array  $input    Form data.
 * @param string $provider Chosen provider.
 * @param string $action   do value: test or finish.
 */
function mlab_smtp_wizard_step_test( array $input, string $provider, string $action ): void {
	if ( 'finish' === $action && mlab_smtp_wizard_flag( 'tested' ) ) {
		mlab_smtp_update( 'setup_done', 1 );
		mlab_smtp_wizard_flag( 'tested', false );
		mlab_smtp_flash( 'success', __( 'Einrichtung abgeschlossen. Mails werden jetzt zugestellt.', 'mlab-smtp' ) );
		mlab_smtp_back();
	}
	if ( 'finish' === $action ) {
		mlab_smtp_flash( 'error', __( 'Bitte senden Sie zuerst eine erfolgreiche Testmail.', 'mlab-smtp' ) );
	} else {
		mlab_smtp_flash_test( (string) ( $input['to'] ?? '' ) );
	}
	wp_safe_redirect( mlab_smtp_wizard_url( 4, $provider ) );
	exit;
}

/**
 * Sends a test mail without JavaScript and leaves the result as a notice.
 *
 * @param string $to Recipient as typed.
 */
function mlab_smtp_flash_test( string $to ): void {
	$result = mlab_smtp_run_test_request( $to );
	mlab_smtp_flash( $result['ok'] ? 'success' : 'error', $result['message'], $result['detail'] );
}

/**
 * Saves the settings form.
 */
function mlab_smtp_handle_save_settings(): void {
	mlab_smtp_guard( 'mlab_smtp_save_settings' );
	$errors = mlab_smtp_save_group( 'settings', mlab_smtp_post_input() );
	if ( $errors ) {
		mlab_smtp_flash( 'error', implode( ' ', $errors ) );
	} else {
		mlab_smtp_flash( 'success', __( 'Die Einstellungen wurden gespeichert.', 'mlab-smtp' ) );
	}
	mlab_smtp_back( array( 'view' => 'settings' ) );
}

/**
 * Sends the quick test mail of the overview without JavaScript.
 */
function mlab_smtp_handle_test(): void {
	mlab_smtp_guard( 'mlab_smtp_test' );
	$input = mlab_smtp_post_input();
	mlab_smtp_flash_test( (string) ( $input['to'] ?? '' ) );
	mlab_smtp_back();
}

/**
 * Clears the log after the in-page confirmation.
 */
function mlab_smtp_handle_clear_log(): void {
	mlab_smtp_guard( 'mlab_smtp_clear_log_confirmed' );
	mlab_smtp_log_clear();
	mlab_smtp_flash( 'success', __( 'Das Protokoll wurde geleert.', 'mlab-smtp' ) );
	mlab_smtp_back( array( 'view' => 'log' ) );
}
