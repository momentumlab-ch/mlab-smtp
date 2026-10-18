<?php
/**
 * Verschlüsselung für gespeicherte Passwörter und Tokens (libsodium, Schlüssel aus den WordPress-Salts).
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_CIPHER_PREFIX = 'enc:';

/**
 * Encryption key derived from the site's auth salt.
 */
function mlab_smtp_key(): string {
	return sodium_crypto_generichash( wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
}

/**
 * Encrypts a value for storage.
 *
 * @param string $plain Plain text.
 */
function mlab_smtp_encrypt( string $plain ): string {
	if ( '' === $plain ) {
		return '';
	}
	$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext for storage, not obfuscation.
	return MLAB_SMTP_CIPHER_PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, mlab_smtp_key() ) );
}

/**
 * Decrypts a stored value. Values without the prefix are returned unchanged. A value that no longer
 * decrypts (for example after the salts changed) returns an empty string.
 *
 * @param string $stored Stored value.
 */
function mlab_smtp_decrypt( string $stored ): string {
	if ( ! str_starts_with( $stored, MLAB_SMTP_CIPHER_PREFIX ) ) {
		return $stored;
	}
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary ciphertext from storage, not obfuscation.
	$raw = base64_decode( substr( $stored, strlen( MLAB_SMTP_CIPHER_PREFIX ) ), true );
	if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
		return '';
	}
	$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, mlab_smtp_key() );
	return false === $plain ? '' : $plain;
}
