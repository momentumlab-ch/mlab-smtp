<?php
/**
 * Sicherheitsbausteine: Maskierung sensibler Daten, sichere ausgehende URLs (SSRF), Verfügbarkeit der Verschlüsselung.
 *
 * Alles, was in Protokolle, Meldungen, Supportberichte, Alarmierung oder REST-Antworten gelangt, läuft durch
 * mlab_smtp_redact(). Ausgehende URLs, die ein Administrator eingibt, laufen durch mlab_smtp_safe_url().
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_MASK = '[maskiert]';

/**
 * Secret values that are known to the plugin (stored or from wp-config.php), for exact replacement.
 * Values shorter than 6 characters are skipped: replacing them would mangle ordinary text.
 *
 * @return string[]
 */
function mlab_smtp_known_secrets(): array {
	static $cache = array();
	$out = array();
	foreach ( mlab_smtp_secret_keys() as $key ) {
		$value = (string) mlab_smtp_get( $key );
		if ( strlen( $value ) >= 6 ) {
			$out[] = $value;
		}
	}
	// Longest first, so a secret that contains another one is replaced as a whole.
	usort(
		$out,
		static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a )
	);
	$cache = $out;
	return $cache;
}

/**
 * Masks secrets and token-like strings in a text. Safe to call on any text; text without secrets stays unchanged.
 *
 * @param string $text        Text that may contain secrets (error message, log line, response body).
 * @param bool   $mask_emails True to also shorten e-mail addresses (support reports).
 * @return string
 */
function mlab_smtp_redact( string $text, bool $mask_emails = false ): string {
	if ( '' === $text ) {
		return '';
	}
	foreach ( mlab_smtp_known_secrets() as $secret ) {
		$text = str_replace( $secret, MLAB_SMTP_MASK, $text );
	}
	foreach ( mlab_smtp_redact_patterns() as $pattern => $replacement ) {
		$text = (string) preg_replace( $pattern, $replacement, $text );
	}
	return $mask_emails ? mlab_smtp_mask_emails_in( $text ) : $text;
}

/**
 * Patterns of common secret formats. Pattern => replacement.
 *
 * @return array<string, string>
 */
function mlab_smtp_redact_patterns(): array {
	$mask = MLAB_SMTP_MASK;
	return array(
		'/\bre_[A-Za-z0-9_\-]{10,}/'                    => 're_' . $mask,
		'/\bwhsec_[A-Za-z0-9+\/=_\-]{8,}/'              => 'whsec_' . $mask,
		'/\b(Bearer|Basic|Token)\s+[A-Za-z0-9._~+\/\-=]{8,}/i' => '$1 ' . $mask,
		'/\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}/' => '[JWT ' . $mask . ']',
		'/\b1\/\/[A-Za-z0-9_\-]{20,}/'                  => $mask,
		'#(\b[a-z][a-z0-9+.\-]*://)([^/\s:@]+):([^/\s@]+)@#i' => '$1$2:' . $mask . '@',
		'/("?(?:client_secret|refresh_token|access_token|id_token|code_verifier|api[_-]?key|authorization|passwd|password|pwd|secret|token)"?\s*[:=]\s*"?)([^\s",&;}\]]{4,})/i' => '$1' . $mask,
		'/\b(?=[A-Za-z0-9_\-]*[A-Za-z])(?=[A-Za-z0-9_\-]*\d)[A-Za-z0-9_\-]{40,}\b/' => $mask,
	);
}

/**
 * Shortens an e-mail address: first letter of the name and of the domain stay, the top level domain stays.
 *
 * @param string $email E-mail address.
 * @return string For example a***@e***.ch
 */
function mlab_smtp_mask_email( string $email ): string {
	$email = trim( $email );
	$at    = strrpos( $email, '@' );
	if ( false === $at || 0 === $at ) {
		return $email;
	}
	$local  = substr( $email, 0, $at );
	$domain = substr( $email, $at + 1 );
	$dot    = strrpos( $domain, '.' );
	$tld    = false === $dot ? '' : substr( $domain, $dot );
	$name   = false === $dot ? $domain : substr( $domain, 0, $dot );
	return mb_substr( $local, 0, 1 ) . '***@' . mb_substr( $name, 0, 1 ) . '***' . $tld;
}

/**
 * Shortens every e-mail address in a text.
 *
 * @param string $text Text.
 */
function mlab_smtp_mask_emails_in( string $text ): string {
	return (string) preg_replace_callback(
		'/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
		static fn( array $m ): string => mlab_smtp_mask_email( $m[0] ),
		$text
	);
}

/**
 * True when the encryption of stored secrets can be used. WordPress ships a polyfill for libsodium, so this is
 * normally true; the check keeps an unusual host from ending in a fatal error.
 */
function mlab_smtp_crypto_available(): bool {
	return function_exists( 'sodium_crypto_secretbox' )
		&& function_exists( 'sodium_crypto_secretbox_open' )
		&& function_exists( 'sodium_crypto_generichash' )
		&& defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' )
		&& defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' );
}

/**
 * Error text for a host without usable encryption.
 */
function mlab_smtp_crypto_error(): WP_Error {
	return new WP_Error( 'mlab_smtp_no_crypto', __( 'Auf diesem Server ist die Verschlüsselung (libsodium) nicht verfügbar. Zugangsdaten werden deshalb nicht gespeichert. Bitte beim Hoster die PHP-Erweiterung «sodium» aktivieren lassen.', 'mlab-smtp' ) );
}

/**
 * True when an IP address belongs to a range that must not be reached from the server on behalf of an admin
 * (private, loopback, link-local, carrier-grade NAT, metadata, unique local, reserved).
 *
 * @param string $ip IPv4 or IPv6 address.
 */
function mlab_smtp_ip_is_internal( string $ip ): bool {
	if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return true;
	}
	if ( str_contains( $ip, ':' ) ) {
		$packed = inet_pton( $ip );
		// IPv4-mapped (::ffff:a.b.c.d): judge the embedded IPv4 address.
		if ( false !== $packed && 16 === strlen( $packed ) && str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			return mlab_smtp_ip_is_internal( (string) inet_ntop( substr( $packed, 12 ) ) );
		}
		$lower = strtolower( $ip );
		return '::' === $lower || '::1' === $lower || str_starts_with( $lower, 'fc' ) || str_starts_with( $lower, 'fd' ) || str_starts_with( $lower, 'fe8' ) || str_starts_with( $lower, 'fe9' ) || str_starts_with( $lower, 'fea' ) || str_starts_with( $lower, 'feb' ) || str_starts_with( $lower, 'ff' );
	}
	if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
		return true;
	}
	// 100.64.0.0/10 (carrier-grade NAT) and 169.254.0.0/16 (link-local, cloud metadata) are not always flagged above.
	$long = ip2long( $ip );
	return false !== $long && ( ( $long & 0xFFC00000 ) === ip2long( '100.64.0.0' ) || ( $long & 0xFFFF0000 ) === ip2long( '169.254.0.0' ) || 0 === ( $long & 0xFF000000 ) );
}

/**
 * IP addresses a host name resolves to.
 *
 * @param string $host Host name or IP literal.
 * @return string[] Empty when the name does not resolve.
 */
function mlab_smtp_resolve_host( string $host ): array {
	if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return array( $host );
	}
	/**
	 * Filters the addresses of a host name before the plugin resolves it itself (tests, special resolvers).
	 *
	 * @param string[]|null $ips  Addresses, null to let the plugin resolve.
	 * @param string        $host Host name.
	 */
	$pre = apply_filters( 'mlab_smtp_resolve_host', null, $host );
	if ( is_array( $pre ) ) {
		return $pre;
	}
	$ips = array();
	foreach ( array( DNS_A => 'ip', DNS_AAAA => 'ipv6' ) as $type => $field ) {
		$records = function_exists( 'dns_get_record' ) ? @dns_get_record( $host, $type ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failing lookup means "does not resolve".
		foreach ( (array) $records as $record ) {
			if ( ! empty( $record[ $field ] ) ) {
				$ips[] = (string) $record[ $field ];
			}
		}
	}
	return array_values( array_unique( $ips ) );
}

/**
 * Checks a URL that an administrator entered before the server calls it (alert webhook): https only, no
 * credentials in the URL, a normal port, no host that resolves to an internal address, no IP literal of an
 * internal range. A host can be allowed explicitly with the filter mlab_smtp_allowed_url_hosts (internal gateway).
 *
 * The check and the later request are separate steps (DNS can change in between). The request therefore also uses
 * wp_safe_remote_post() and never follows redirects, see mlab_smtp_safe_post().
 *
 * @param string $url URL as entered.
 * @return string|WP_Error The URL (trimmed) or a German error message.
 */
function mlab_smtp_safe_url( string $url ) {
	$url   = trim( $url );
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
		return new WP_Error( 'mlab_smtp_url', __( 'Das ist keine gültige Adresse. Bitte mit https:// beginnen, zum Beispiel https://alarm.beispiel.ch/hook.', 'mlab-smtp' ) );
	}
	if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
		return new WP_Error( 'mlab_smtp_url', __( 'Aus Sicherheitsgründen sind nur Adressen mit https:// erlaubt.', 'mlab-smtp' ) );
	}
	if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return new WP_Error( 'mlab_smtp_url', __( 'Die Adresse darf keinen Benutzernamen und kein Passwort enthalten.', 'mlab-smtp' ) );
	}
	$port = isset( $parts['port'] ) ? (int) $parts['port'] : 443;
	if ( ! in_array( $port, (array) apply_filters( 'mlab_smtp_allowed_url_ports', array( 443, 8443 ) ), true ) ) {
		return new WP_Error( 'mlab_smtp_url', __( 'Dieser Port ist nicht erlaubt. Erlaubt sind 443 und 8443.', 'mlab-smtp' ) );
	}
	$host = strtolower( trim( (string) $parts['host'], '[]' ) );
	if ( in_array( $host, array_map( 'strtolower', (array) apply_filters( 'mlab_smtp_allowed_url_hosts', array() ) ), true ) ) {
		return $url;
	}
	$ips = mlab_smtp_resolve_host( $host );
	if ( ! $ips ) {
		return new WP_Error( 'mlab_smtp_url', __( 'Der Servername lässt sich nicht auflösen. Bitte Schreibweise prüfen.', 'mlab-smtp' ) );
	}
	foreach ( $ips as $ip ) {
		if ( mlab_smtp_ip_is_internal( $ip ) ) {
			return new WP_Error( 'mlab_smtp_url', __( 'Die Adresse zeigt auf ein internes Netz. Aus Sicherheitsgründen sind nur öffentlich erreichbare Server erlaubt.', 'mlab-smtp' ) );
		}
	}
	return $url;
}

/**
 * POST to a URL that passed mlab_smtp_safe_url(). No redirects, short timeout, WordPress' own "unsafe URL" check on top.
 *
 * @param string               $url     URL.
 * @param string               $body    Body.
 * @param array<string,string> $headers Extra headers.
 * @return array|WP_Error Response or error.
 */
function mlab_smtp_safe_post( string $url, string $body, array $headers = array() ) {
	$safe = mlab_smtp_safe_url( $url );
	if ( is_wp_error( $safe ) ) {
		return $safe;
	}
	return wp_safe_remote_post(
		$safe,
		array(
			'timeout'     => 5,
			'redirection' => 0,
			'body'        => $body,
			'headers'     => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
			'user-agent'  => 'mlab-smtp/' . MLAB_SMTP_VERSION,
		)
	);
}
