<?php
/**
 * Anbieter-Erkennung über den MX-Eintrag der Domain und Vorlagen für Schweizer Hoster.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * DNS lookup that never throws or warns. Results are cached for one day, failures for one hour.
 *
 * @param string $host Host name.
 * @param int    $type DNS_MX or DNS_TXT.
 * @return array|null Records, or null when the lookup is not possible.
 */
function mlab_smtp_dns_lookup( string $host, int $type ): ?array {
	$key    = 'mlab_smtp_dns_' . md5( $type . '|' . strtolower( $host ) );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return 'fail' === ( $cached['status'] ?? '' ) ? null : $cached['records'];
	}
	$records = '' !== $host && function_exists( 'dns_get_record' ) ? @dns_get_record( $host, $type ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failing lookup is handled below.
	if ( ! is_array( $records ) ) {
		set_transient( $key, array( 'status' => 'fail' ), HOUR_IN_SECONDS );
		return null;
	}
	set_transient(
		$key,
		array(
			'status'  => 'ok',
			'records' => $records,
		),
		DAY_IN_SECONDS
	);
	return $records;
}

/**
 * Domain part of an e-mail address.
 *
 * @param string $email E-mail address.
 */
function mlab_smtp_domain_of( string $email ): string {
	$at = strrpos( $email, '@' );
	return false === $at ? '' : strtolower( substr( $email, $at + 1 ) );
}

/**
 * Guesses the mail provider of a domain from its MX records.
 *
 * @param string $domain Domain, empty for the domain of the admin e-mail address.
 * @return array{provider:string, mx:string}
 */
function mlab_smtp_detect_provider( string $domain = '' ): array {
	$domain = '' !== $domain ? strtolower( $domain ) : mlab_smtp_domain_of( (string) get_option( 'admin_email' ) );
	$result = array(
		'provider' => 'smtp',
		'mx'       => '',
	);
	$mx     = mlab_smtp_dns_lookup( $domain, DNS_MX );
	if ( ! $mx ) {
		return $result;
	}
	usort(
		$mx,
		static function ( array $a, array $b ): int {
			return (int) ( $a['pri'] ?? 0 ) <=> (int) ( $b['pri'] ?? 0 );
		}
	);
	$target       = strtolower( rtrim( (string) ( $mx[0]['target'] ?? '' ), '.' ) );
	$result['mx'] = $target;
	if ( preg_match( '/(^|\.)(outlook\.com|protection\.outlook\.com)$/', $target ) ) {
		$result['provider'] = 'm365';
	} elseif ( preg_match( '/(^|\.)(google\.com|googlemail\.com)$/', $target ) ) {
		$result['provider'] = 'google';
	}
	return $result;
}

/**
 * Server settings of common Swiss hosters.
 *
 * @return array<string, array{label:string, host:string, port:int, secure:string}>
 */
function mlab_smtp_smtp_presets(): array {
	return array(
		'infomaniak' => array(
			'label'  => 'Infomaniak',
			'host'   => 'mail.infomaniak.com',
			'port'   => 587,
			'secure' => 'tls',
		),
		'hostpoint'  => array(
			'label'  => 'Hostpoint',
			'host'   => 'asmtp.mail.hostpoint.ch',
			'port'   => 587,
			'secure' => 'tls',
		),
		'cyon'       => array(
			'label'  => 'cyon',
			'host'   => 'mail.cyon.ch',
			'port'   => 587,
			'secure' => 'tls',
		),
		'metanet'    => array(
			'label'  => 'Metanet',
			'host'   => 'smtp.metanet.ch',
			'port'   => 587,
			'secure' => 'tls',
		),
		'other'      => array(
			'label'  => __( 'Anderer Anbieter', 'mlab-smtp' ),
			'host'   => '',
			'port'   => 587,
			'secure' => 'tls',
		),
	);
}
