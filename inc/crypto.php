<?php
/**
 * Verschlüsselung für gespeicherte Passwörter und Tokens (libsodium).
 *
 * Schlüssel: Standard aus den WordPress-Salts, optional aus der Konstante MLAB_SMTP_KEY in der wp-config.php
 * (entkoppelt die Zugangsdaten von den Salts). Das Format `enc2:<Schlüssel-Kennung>:<Daten>` nennt den Schlüssel,
 * mit dem verschlüsselt wurde. Das ältere Format `enc:` bleibt lesbar. Es wird nie automatisch ein neuer Schlüssel
 * erzeugt: Ein unlesbares Secret bleibt als solches erkennbar und wird zur Neueingabe angeboten.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_CIPHER_PREFIX    = 'enc:';
const MLAB_SMTP_CIPHER_PREFIX_V2 = 'enc2:';

/**
 * Key from the salts (the way versions before 1.5 and the default of 1.5 derive it).
 */
function mlab_smtp_salt_key(): string {
	return sodium_crypto_generichash( wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
}

/**
 * Current encryption key: from MLAB_SMTP_KEY when it is set, otherwise from the salts.
 */
function mlab_smtp_key(): string {
	if ( defined( 'MLAB_SMTP_KEY' ) && '' !== (string) constant( 'MLAB_SMTP_KEY' ) ) {
		return sodium_crypto_generichash( 'mlab-smtp-key|' . (string) constant( 'MLAB_SMTP_KEY' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
	return mlab_smtp_salt_key();
}

/**
 * Short identifier of a key (not reversible), stored with the ciphertext.
 *
 * @param string $key Binary key.
 */
function mlab_smtp_key_id( string $key ): string {
	return substr( bin2hex( sodium_crypto_generichash( 'mlab-smtp-id|' . $key, '', 16 ) ), 0, 8 );
}

/**
 * Encrypts a value for storage. An empty string stays empty.
 *
 * @param string $plain Plain text.
 * @return string Ciphertext, empty for an empty value or when encryption is not available.
 */
function mlab_smtp_encrypt( string $plain ): string {
	if ( '' === $plain || ! mlab_smtp_crypto_available() ) {
		return '';
	}
	$key   = mlab_smtp_key();
	$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext for storage, not obfuscation.
	return MLAB_SMTP_CIPHER_PREFIX_V2 . mlab_smtp_key_id( $key ) . ':' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) );
}

/**
 * True when the value is already a ciphertext of this site.
 *
 * @param string $value Value to check.
 */
function mlab_smtp_is_encrypted( string $value ): bool {
	return mlab_smtp_is_cipher( $value ) && '' !== mlab_smtp_decrypt( $value );
}

/**
 * True when the value has the format of a ciphertext (readable or not).
 *
 * @param string $value Value to check.
 */
function mlab_smtp_is_cipher( string $value ): bool {
	return str_starts_with( $value, MLAB_SMTP_CIPHER_PREFIX_V2 ) || str_starts_with( $value, MLAB_SMTP_CIPHER_PREFIX );
}

/**
 * Keys that may have encrypted a stored value: the current one, then the key from the salts (a site that
 * switched to MLAB_SMTP_KEY can still read what was encrypted before).
 *
 * @return string[] Binary keys.
 */
function mlab_smtp_candidate_keys(): array {
	$keys = array( mlab_smtp_key() );
	$salt = mlab_smtp_salt_key();
	if ( $salt !== $keys[0] ) {
		$keys[] = $salt;
	}
	return $keys;
}

/**
 * Decrypts a stored value. Values without the prefix are returned unchanged. A value that no longer decrypts
 * (salts or key changed, damaged data, no libsodium) returns an empty string.
 *
 * @param string $stored Stored value.
 */
function mlab_smtp_decrypt( string $stored ): string {
	if ( ! mlab_smtp_is_cipher( $stored ) ) {
		return $stored;
	}
	if ( ! mlab_smtp_crypto_available() ) {
		return '';
	}
	$v2   = str_starts_with( $stored, MLAB_SMTP_CIPHER_PREFIX_V2 );
	$body = substr( $stored, strlen( $v2 ? MLAB_SMTP_CIPHER_PREFIX_V2 : MLAB_SMTP_CIPHER_PREFIX ) );
	$id   = '';
	if ( $v2 ) {
		$split = strpos( $body, ':' );
		if ( false === $split ) {
			return '';
		}
		$id   = substr( $body, 0, $split );
		$body = substr( $body, $split + 1 );
	}
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary ciphertext from storage, not obfuscation.
	$raw = base64_decode( $body, true );
	if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
		return '';
	}
	$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	foreach ( mlab_smtp_candidate_keys() as $key ) {
		if ( '' !== $id && mlab_smtp_key_id( $key ) !== $id ) {
			continue;
		}
		$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
		if ( false !== $plain ) {
			return $plain;
		}
	}
	return '';
}

/**
 * Why a stored secret cannot be read: key_changed (the key it was encrypted with is not available any more),
 * damaged, or no_crypto. Empty when it is readable or not encrypted.
 *
 * @param string $stored Stored value.
 */
function mlab_smtp_cipher_problem( string $stored ): string {
	if ( ! mlab_smtp_is_cipher( $stored ) || '' !== mlab_smtp_decrypt( $stored ) ) {
		return '';
	}
	if ( ! mlab_smtp_crypto_available() ) {
		return 'no_crypto';
	}
	if ( str_starts_with( $stored, MLAB_SMTP_CIPHER_PREFIX_V2 ) ) {
		$parts = explode( ':', $stored, 3 );
		foreach ( mlab_smtp_candidate_keys() as $key ) {
			if ( isset( $parts[1] ) && mlab_smtp_key_id( $key ) === $parts[1] ) {
				return 'damaged';
			}
		}
	}
	return 'key_changed';
}

/**
 * Every stored secret with its state, for the settings page and the re-encryption. Nothing secret is returned.
 *
 * Status: ok (readable, encrypted with the current key), old_key (readable, but not encrypted with the current
 * key or in the legacy format), plain (stored without encryption), key_changed, damaged or no_crypto (unreadable).
 *
 * @return array<int, array{id:string, where:string, key:string, status:string}> where is options or alerts.
 */
function mlab_smtp_secret_inventory(): array {
	$out    = array();
	$stored = get_option( MLAB_SMTP_OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();
	foreach ( mlab_smtp_secret_keys() as $key ) {
		$value = (string) ( $stored[ $key ] ?? '' );
		if ( '' !== $value ) {
			$out[] = array(
				'id'     => $key,
				'where'  => 'options',
				'key'    => $key,
				'status' => mlab_smtp_secret_status( $value ),
			);
		}
	}
	$alerts = get_option( MLAB_SMTP_ALERTS_OPTION, array() );
	$value  = is_array( $alerts ) ? (string) ( $alerts['secret'] ?? '' ) : '';
	if ( '' !== $value ) {
		$out[] = array(
			'id'     => 'alert_secret',
			'where'  => 'alerts',
			'key'    => 'secret',
			'status' => mlab_smtp_secret_status( $value ),
		);
	}
	return $out;
}

/**
 * State of one stored value, see mlab_smtp_secret_inventory().
 *
 * @param string $stored Stored value.
 */
function mlab_smtp_secret_status( string $stored ): string {
	if ( ! mlab_smtp_is_cipher( $stored ) ) {
		return 'plain';
	}
	$problem = mlab_smtp_cipher_problem( $stored );
	if ( '' !== $problem ) {
		return $problem;
	}
	$current = MLAB_SMTP_CIPHER_PREFIX_V2 . mlab_smtp_key_id( mlab_smtp_key() ) . ':';
	return str_starts_with( $stored, $current ) ? 'ok' : 'old_key';
}

/**
 * Stores every readable secret again with the current key. Secrets that cannot be read stay untouched and are
 * reported. Never creates a key. Safe to run repeatedly.
 *
 * @return array{reencrypted:int, current:int, unreadable:int, ids:string[]} Counts and the ids of the unreadable ones.
 */
function mlab_smtp_reencrypt_secrets(): array {
	$result = array(
		'reencrypted' => 0,
		'current'     => 0,
		'unreadable'  => 0,
		'ids'         => array(),
	);
	if ( ! mlab_smtp_crypto_available() ) {
		$result['unreadable'] = count( mlab_smtp_secret_inventory() );
		return $result;
	}
	$options = array(
		'options' => MLAB_SMTP_OPTION,
		'alerts'  => MLAB_SMTP_ALERTS_OPTION,
	);
	foreach ( $options as $where => $option ) {
		$data  = get_option( $option, array() );
		$data  = is_array( $data ) ? $data : array();
		$dirty = false;
		foreach ( mlab_smtp_secret_inventory() as $item ) {
			if ( $item['where'] !== $where ) {
				continue;
			}
			$dirty = mlab_smtp_reencrypt_one( $data, $item, $result ) || $dirty;
		}
		if ( $dirty ) {
			update_option( $option, $data, false );
		}
	}
	return $result;
}

/**
 * Re-encrypts one entry inside $data and counts it.
 *
 * @param array $data   Option array (changed in place).
 * @param array $item   Entry from mlab_smtp_secret_inventory().
 * @param array $result Counters (changed in place).
 * @return bool True when $data changed.
 */
function mlab_smtp_reencrypt_one( array &$data, array $item, array &$result ): bool {
	if ( 'ok' === $item['status'] ) {
		++$result['current'];
		return false;
	}
	if ( ! in_array( $item['status'], array( 'old_key', 'plain' ), true ) ) {
		++$result['unreadable'];
		$result['ids'][] = $item['id'];
		return false;
	}
	$plain = mlab_smtp_decrypt( (string) $data[ $item['key'] ] );
	$fresh = '' !== $plain ? mlab_smtp_encrypt( $plain ) : '';
	if ( '' === $fresh ) {
		++$result['unreadable'];
		$result['ids'][] = $item['id'];
		return false;
	}
	$data[ $item['key'] ] = $fresh;
	++$result['reencrypted'];
	return true;
}
