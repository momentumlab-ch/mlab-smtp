<?php
/**
 * Einstellungen: Schlüssel, Standardwerte, Lesen und Speichern.
 *
 * Jeder Schlüssel lässt sich in wp-config.php mit der Konstante MLAB_SMTP_<SCHLÜSSEL> überschreiben,
 * z. B. MLAB_SMTP_SMTP_PASS.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_OPTION = 'mlab_smtp_options';

/**
 * Keys that are stored encrypted.
 *
 * @return string[]
 */
function mlab_smtp_secret_keys(): array {
	return array( 'smtp_pass', 'm365_client_secret', 'google_client_secret', 'google_refresh_token' );
}

/**
 * Default settings.
 *
 * @return array<string, string|int>
 */
function mlab_smtp_defaults(): array {
	return array(
		'provider'             => 'smtp',
		'from_email'           => '',
		'from_name'            => '',
		'force_from'           => 1,
		'smtp_host'            => '',
		'smtp_port'            => 587,
		'smtp_secure'          => 'tls',
		'smtp_auth'            => 1,
		'smtp_user'            => '',
		'smtp_pass'            => '',
		'm365_tenant'          => '',
		'm365_client_id'       => '',
		'm365_client_secret'   => '',
		'google_client_id'     => '',
		'google_client_secret' => '',
		'google_refresh_token' => '',
		'log_enabled'          => 1,
	);
}

/**
 * All stored settings merged with the defaults. Secrets stay encrypted.
 *
 * @return array<string, string|int>
 */
function mlab_smtp_get_all(): array {
	$stored = get_option( MLAB_SMTP_OPTION, array() );
	return wp_parse_args( is_array( $stored ) ? $stored : array(), mlab_smtp_defaults() );
}

/**
 * One setting. A constant in wp-config.php wins over the stored value; secrets come back decrypted.
 *
 * @param string $key Setting key.
 * @return string|int
 */
function mlab_smtp_get( string $key ) {
	$constant = 'MLAB_SMTP_' . strtoupper( $key );
	if ( defined( $constant ) ) {
		return constant( $constant );
	}
	$value = mlab_smtp_get_all()[ $key ] ?? '';
	if ( in_array( $key, mlab_smtp_secret_keys(), true ) ) {
		return mlab_smtp_decrypt( (string) $value );
	}
	return $value;
}

/**
 * Saves a single setting without touching the others.
 *
 * @param string $key   Setting key.
 * @param string $value New value. Secrets are encrypted here.
 */
function mlab_smtp_update( string $key, string $value ): void {
	$options = mlab_smtp_get_all();
	if ( in_array( $key, mlab_smtp_secret_keys(), true ) ) {
		$value = mlab_smtp_encrypt( $value );
	}
	$options[ $key ] = $value;
	update_option( MLAB_SMTP_OPTION, $options, false );
}

add_action( 'admin_init', 'mlab_smtp_register_settings' );

/**
 * Registers the option with its sanitize callback.
 */
function mlab_smtp_register_settings(): void {
	register_setting(
		'mlab_smtp',
		MLAB_SMTP_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'mlab_smtp_sanitize',
			'default'           => mlab_smtp_defaults(),
		)
	);
}

/**
 * Sanitizes the submitted form. Empty secret fields keep the stored secret.
 *
 * @param mixed $input Raw form data.
 * @return array<string, string|int>
 */
function mlab_smtp_sanitize( $input ): array {
	$input = is_array( $input ) ? $input : array();
	$new   = mlab_smtp_get_all();

	$text_fields = array(
		'from_name'        => 'sanitize_text_field',
		'from_email'       => 'sanitize_email',
		'smtp_host'        => 'sanitize_text_field',
		'smtp_user'        => 'sanitize_text_field',
		'm365_tenant'      => 'sanitize_text_field',
		'm365_client_id'   => 'sanitize_text_field',
		'google_client_id' => 'sanitize_text_field',
	);
	foreach ( $text_fields as $key => $callback ) {
		$new[ $key ] = $callback( (string) ( $input[ $key ] ?? '' ) );
	}

	$new['provider']    = mlab_smtp_pick( $input['provider'] ?? '', array( 'smtp', 'm365', 'google' ), 'smtp' );
	$new['smtp_secure'] = mlab_smtp_pick( $input['smtp_secure'] ?? '', array( 'tls', 'ssl', 'none' ), 'tls' );
	$new['smtp_port']   = absint( $input['smtp_port'] ?? 0 ) ? absint( $input['smtp_port'] ) : 587;
	foreach ( array( 'force_from', 'smtp_auth', 'log_enabled' ) as $flag ) {
		$new[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
	}

	foreach ( array( 'smtp_pass', 'm365_client_secret', 'google_client_secret' ) as $secret ) {
		$value = trim( (string) ( $input[ $secret ] ?? '' ) );
		if ( '' !== $value ) {
			$new[ $secret ] = mlab_smtp_encrypt( $value );
		}
	}
	return $new;
}

/**
 * Returns the value when it is one of the allowed ones, otherwise the fallback.
 *
 * @param mixed    $value    Submitted value.
 * @param string[] $allowed  Allowed values.
 * @param string   $fallback Fallback value.
 */
function mlab_smtp_pick( $value, array $allowed, string $fallback ): string {
	return in_array( $value, $allowed, true ) ? (string) $value : $fallback;
}
