<?php
/**
 * Einstellungen: Schlüssel, Standardwerte, Lesen und Speichern.
 *
 * Jeder Schlüssel lässt sich in wp-config.php mit der Konstante MLAB_SMTP_<SCHLÜSSEL> überschreiben,
 * z. B. MLAB_SMTP_SMTP_PASS. Gespeichert wird bewusst ohne register_setting() und ohne sanitize-Filter:
 * Alle Eingaben laufen über mlab_smtp_save_fields(), alle internen Änderungen über mlab_smtp_update().
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_OPTION     = 'mlab_smtp_options';
const MLAB_SMTP_DB_VERSION = '1.2.0';

add_action( 'admin_init', 'mlab_smtp_maybe_migrate' );

/**
 * Keys that are stored encrypted.
 *
 * @return string[]
 */
function mlab_smtp_secret_keys(): array {
	return array( 'smtp_pass', 'fb_smtp_pass', 'm365_client_secret', 'google_client_secret', 'google_refresh_token' );
}

/**
 * Keys that are checkboxes in a form: a missing field means 0.
 *
 * @return string[]
 */
function mlab_smtp_checkbox_keys(): array {
	return array( 'force_from', 'smtp_auth', 'fallback_enabled', 'fb_smtp_auth', 'notify_enabled', 'log_enabled' );
}

/**
 * Allowed values of keys that are a choice.
 *
 * @return array<string, array<int, string|int>>
 */
function mlab_smtp_choices(): array {
	return array(
		'provider'       => array( 'smtp', 'm365', 'google' ),
		'smtp_secure'    => array( 'tls', 'ssl', 'none' ),
		'fb_smtp_secure' => array( 'tls', 'ssl', 'none' ),
		'notify_mode'    => array( 'instant', 'daily' ),
		'log_retention'  => array( 7, 30, 90 ),
	);
}

/**
 * Default settings.
 *
 * @return array<string, string|int>
 */
function mlab_smtp_defaults(): array {
	return array(
		'provider'             => '',
		'setup_done'           => 0,
		'from_email'           => '',
		'from_name'            => '',
		'reply_to'             => '',
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
		'm365_secret_expires'  => '',
		'google_client_id'     => '',
		'google_client_secret' => '',
		'google_refresh_token' => '',
		'google_account'       => '',
		'fallback_enabled'     => 0,
		'fb_smtp_host'         => '',
		'fb_smtp_port'         => 587,
		'fb_smtp_secure'       => 'tls',
		'fb_smtp_auth'         => 1,
		'fb_smtp_user'         => '',
		'fb_smtp_pass'         => '',
		'notify_enabled'       => 1,
		'notify_email'         => '',
		'notify_mode'          => 'instant',
		'log_enabled'          => 1,
		'log_retention'        => 30,
	);
}

/**
 * Keys that identify the connection. A change makes cached access tokens worthless.
 *
 * @return string[]
 */
function mlab_smtp_credential_keys(): array {
	return array( 'm365_tenant', 'm365_client_id', 'm365_client_secret', 'google_client_id', 'google_client_secret', 'google_refresh_token' );
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
 * True when wp-config.php sets the key with a constant.
 *
 * @param string $key Setting key.
 */
function mlab_smtp_is_locked( string $key ): bool {
	return defined( 'MLAB_SMTP_' . strtoupper( $key ) );
}

/**
 * One setting. A constant in wp-config.php wins over the stored value; secrets come back decrypted.
 * A constant with a value outside the allowed choices is ignored in favour of the default.
 *
 * @param string $key Setting key.
 * @return string|int
 */
function mlab_smtp_get( string $key ) {
	if ( mlab_smtp_is_locked( $key ) ) {
		$value   = constant( 'MLAB_SMTP_' . strtoupper( $key ) );
		$choices = mlab_smtp_choices()[ $key ] ?? null;
		if ( null === $choices || in_array( $value, $choices, true ) ) {
			return $value;
		}
		return mlab_smtp_defaults()[ $key ];
	}
	$value = mlab_smtp_get_all()[ $key ] ?? '';
	if ( in_array( $key, mlab_smtp_secret_keys(), true ) ) {
		return mlab_smtp_decrypt( (string) $value );
	}
	return $value;
}

/**
 * True when a secret is stored but can no longer be decrypted (for example after the salts changed).
 *
 * @param string $key Secret key.
 */
function mlab_smtp_secret_unreadable( string $key ): bool {
	$stored = (string) ( mlab_smtp_get_all()[ $key ] ?? '' );
	return '' !== $stored && '' === mlab_smtp_decrypt( $stored );
}

/**
 * Saves several settings in one write. Secrets are encrypted here. Cached tokens are dropped
 * when a credential changed.
 *
 * @param array<string, mixed> $changes Key => new plain value.
 */
function mlab_smtp_store( array $changes ): void {
	$options = mlab_smtp_get_all();
	$changed = false;
	foreach ( $changes as $key => $value ) {
		if ( in_array( $key, mlab_smtp_credential_keys(), true ) && (string) mlab_smtp_get( $key ) !== (string) $value ) {
			$changed = true;
		}
		$options[ $key ] = in_array( $key, mlab_smtp_secret_keys(), true ) ? mlab_smtp_encrypt( (string) $value ) : $value;
	}
	update_option( MLAB_SMTP_OPTION, $options, false );
	if ( $changed ) {
		mlab_smtp_clear_tokens();
	}
}

/**
 * Saves a single setting without touching the others. No sanitizing: use mlab_smtp_save_fields() for user input.
 *
 * @param string $key   Setting key.
 * @param mixed  $value New value. Secrets are encrypted here.
 */
function mlab_smtp_update( string $key, $value ): void {
	mlab_smtp_store( array( $key => $value ) );
}

/**
 * Sanitizes and saves the given keys of a submitted form. Keys not listed stay untouched.
 *
 * @param array    $input Raw form data (unslashed).
 * @param string[] $keys  Keys this form owns.
 * @return string[] Error messages. Fields with an error keep their old value.
 */
function mlab_smtp_save_fields( array $input, array $keys ): array {
	$errors   = array();
	$changes  = array();
	$defaults = mlab_smtp_defaults();
	foreach ( $keys as $key ) {
		if ( ! array_key_exists( $key, $defaults ) || mlab_smtp_is_locked( $key ) ) {
			continue;
		}
		$value = mlab_smtp_sanitize_field( $key, $input[ $key ] ?? null, $defaults[ $key ] );
		if ( is_wp_error( $value ) ) {
			$errors[] = $value->get_error_message();
		} elseif ( null !== $value ) {
			$changes[ $key ] = $value;
		}
	}
	if ( $changes ) {
		mlab_smtp_store( $changes );
	}
	return $errors;
}

/**
 * Sanitizes one submitted value according to the type of its key.
 *
 * @param string     $key     Setting key.
 * @param mixed      $raw     Submitted value, null when the field was missing.
 * @param string|int $fallback Default value; its type decides the sanitizing.
 * @return string|int|WP_Error|null Clean value, error, or null to keep the stored value.
 */
function mlab_smtp_sanitize_field( string $key, $raw, $fallback ) {
	$raw = is_scalar( $raw ) ? (string) $raw : '';
	if ( in_array( $key, mlab_smtp_secret_keys(), true ) ) {
		// Never trim: passwords may start or end with a space. Empty keeps the stored secret.
		return '' === $raw ? null : $raw;
	}
	if ( in_array( $key, mlab_smtp_checkbox_keys(), true ) ) {
		return '' === $raw || '0' === $raw ? 0 : 1;
	}
	if ( isset( mlab_smtp_choices()[ $key ] ) ) {
		$value = is_int( $fallback ) ? (int) $raw : $raw;
		return in_array( $value, mlab_smtp_choices()[ $key ], true ) ? $value : $fallback;
	}
	if ( str_ends_with( $key, '_port' ) ) {
		return absint( $raw ) > 0 && absint( $raw ) <= 65535 ? absint( $raw ) : $fallback;
	}
	if ( in_array( $key, array( 'from_email', 'reply_to', 'notify_email' ), true ) ) {
		return mlab_smtp_sanitize_email_field( $key, $raw );
	}
	if ( 'm365_secret_expires' === $key ) {
		return mlab_smtp_sanitize_date_field( $raw );
	}
	return sanitize_text_field( $raw );
}

/**
 * Validates an optional e-mail address.
 *
 * @param string $key Setting key, for the error message.
 * @param string $raw Submitted value.
 * @return string|WP_Error
 */
function mlab_smtp_sanitize_email_field( string $key, string $raw ) {
	$raw = trim( $raw );
	if ( '' === $raw ) {
		return '';
	}
	$email = sanitize_email( $raw );
	if ( is_email( $email ) ) {
		return $email;
	}
	$labels = array(
		'from_email'   => __( 'Absender-Adresse', 'mlab-smtp' ),
		'reply_to'     => __( 'Antwortadresse', 'mlab-smtp' ),
		'notify_email' => __( 'Adresse für Benachrichtigungen', 'mlab-smtp' ),
	);
	/* translators: 1: field name, 2: the entered value */
	return new WP_Error( 'mlab_smtp_email', sprintf( __( '«%2$s» ist keine gültige E-Mail-Adresse (%1$s). Der bisherige Wert bleibt erhalten. Bitte im Format name@beispiel.ch eingeben.', 'mlab-smtp' ), $labels[ $key ], sanitize_text_field( $raw ) ) );
}

/**
 * Validates an optional date in the format YYYY-MM-DD.
 *
 * @param string $raw Submitted value.
 * @return string|WP_Error
 */
function mlab_smtp_sanitize_date_field( string $raw ) {
	$raw = trim( $raw );
	if ( '' === $raw ) {
		return '';
	}
	$date = DateTime::createFromFormat( '!Y-m-d', $raw );
	if ( $date && $date->format( 'Y-m-d' ) === $raw ) {
		return $raw;
	}
	return new WP_Error( 'mlab_smtp_date', __( 'Das Ablaufdatum ist ungültig. Bitte im Format JJJJ-MM-TT eingeben, zum Beispiel 2028-11-13. Der bisherige Wert bleibt erhalten.', 'mlab-smtp' ) );
}

/**
 * Keys that must be filled in for a provider to work.
 *
 * @param string $provider smtp, m365 or google.
 * @return string[]
 */
function mlab_smtp_required_keys( string $provider ): array {
	$map = array(
		'smtp'   => array( 'smtp_host' ),
		'm365'   => array( 'm365_tenant', 'm365_client_id', 'm365_client_secret' ),
		'google' => array( 'google_client_id', 'google_client_secret', 'google_refresh_token' ),
	);
	return $map[ $provider ] ?? array();
}

/**
 * One-time upgrade of data from older versions. Runs on admin_init, controlled by mlab_smtp_db_version.
 * Installations of 1.0.x that already have connection data count as set up.
 */
function mlab_smtp_maybe_migrate(): void {
	if ( version_compare( (string) get_option( 'mlab_smtp_db_version', '0' ), MLAB_SMTP_DB_VERSION, '>=' ) ) {
		return;
	}
	if ( false !== get_option( MLAB_SMTP_OPTION ) ) {
		$all      = mlab_smtp_get_all();
		$provider = (string) $all['provider'];
		$ready    = '' !== $provider && ! array_filter(
			mlab_smtp_required_keys( $provider ),
			static function ( string $key ) use ( $all ): bool {
				return '' === (string) $all[ $key ];
			}
		);
		mlab_smtp_store(
			array(
				'provider'   => $ready ? $provider : '',
				'setup_done' => $ready ? 1 : 0,
			)
		);
	}
	mlab_smtp_log_upgrade();
	mlab_smtp_schedule();
	update_option( 'mlab_smtp_db_version', MLAB_SMTP_DB_VERSION, false );
}
