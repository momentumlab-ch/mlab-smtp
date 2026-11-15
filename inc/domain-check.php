<?php
/**
 * Advisory DNS inspection. Never called by delivery or readiness gates.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Looks up records, bypassing the shared DNS cache on an explicit recheck.
 *
 * @param string $host Name.
 * @param int    $type Record type.
 * @param bool   $fresh Bypass local cache.
 * @return array|null
 */
function mlab_smtp_domain_lookup( string $host, int $type, bool $fresh ): ?array {
	if ( $fresh ) {
		delete_transient( 'mlab_smtp_dns_' . md5( $type . '|' . strtolower( $host ) ) );
	}
	return mlab_smtp_dns_lookup( $host, $type );
}

/**
 * Joins split TXT chunks, preserving record boundaries.
 *
 * @param array|null $records DNS result.
 * @return string[]
 */
function mlab_smtp_domain_txt( ?array $records ): array {
	return array_map( static fn( array $record ): string => isset( $record['entries'] ) ? implode( '', $record['entries'] ) : (string) ( $record['txt'] ?? '' ), $records ?? array() );
}

/**
 * Registrable domain using the bundled Public Suffix List, including wildcard and exception rules.
 *
 * @param string $domain ASCII domain.
 */
function mlab_smtp_organization_domain( string $domain ): string {
	static $rules = null;
	if ( null === $rules ) {
		$rules = array();
		$lines = file( MLAB_SMTP_DIR . 'assets/public-suffix-list.dat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			if ( ! str_starts_with( $line, '//' ) ) {
				$rules[ trim( $line ) ] = true;
			}
		}
	}
	$labels = explode( '.', strtolower( rtrim( $domain, '.' ) ) );
	$count  = count( $labels );
	$length = 1;
	for ( $i = 0; $i < $count; ++$i ) {
		$tail = implode( '.', array_slice( $labels, $i ) );
		if ( isset( $rules[ '!' . $tail ] ) ) {
			$length = $count - $i - 1;
			break;
		}
		if ( isset( $rules[ $tail ] ) || ( $i > 0 && isset( $rules[ '*.' . $tail ] ) ) ) {
			$length = max( $length, $count - $i + ( isset( $rules[ '*.' . $tail ] ) && $i > 0 ? 1 : 0 ) );
		}
	}
	return $count > $length ? implode( '.', array_slice( $labels, -$length - 1 ) ) : '';
}

/**
 * Reads a DKIM key or follows at most three public CNAME delegations.
 * Presence does not prove that outgoing messages are signed.
 *
 * @param string $host Selector name.
 * @param bool   $fresh Bypass cache.
 * @return string found, missing or unknown.
 */
function mlab_smtp_domain_dkim( string $host, bool $fresh ): string {
	$seen = array();
	for ( $hop = 0; $hop < 4; ++$hop ) {
		if ( isset( $seen[ $host ] ) ) {
			return 'unknown';
		}
		$seen[ $host ] = true;
		$txt           = mlab_smtp_domain_lookup( $host, DNS_TXT, $fresh );
		if ( null === $txt ) {
			return 'unknown';
		}
		foreach ( mlab_smtp_domain_txt( $txt ) as $value ) {
			if ( preg_match( '/(?:^|;)\s*p\s*=\s*([A-Za-z0-9+\/=]+)/i', $value, $match ) && false !== base64_decode( $match[1], true ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- DNS public key validation.
				return 'found';
			}
		}
		$cname = mlab_smtp_domain_lookup( $host, DNS_CNAME, $fresh );
		if ( null === $cname ) {
			return 'unknown';
		}
		$host = strtolower( rtrim( (string) ( $cname[0]['target'] ?? '' ), '.' ) );
		if ( '' === $host ) {
			return 'missing';
		}
	}
	return 'unknown';
}

/**
 * Route-specific advisory report. No sending flags are changed, even for missing DNS records.
 *
 * @param string $route primary or fallback.
 * @param bool   $fresh Bypass local DNS and provider caches.
 * @return array
 */
function mlab_smtp_domain_report( string $route, bool $fresh = false ): array {
	$route    = 'fallback' === $route ? 'fallback' : 'primary';
	$sender   = mlab_smtp_sender_for_route( $route );
	$domain   = mlab_smtp_domain_of( $sender['email'] );
	$provider = (string) mlab_smtp_get( 'fallback' === $route ? 'fb_provider' : 'provider' );
	$selector = (string) mlab_smtp_get( 'fallback' === $route ? 'fb_dkim_selector' : 'dkim_selector' );
	$result   = array(
		'domain'       => $domain,
		'SPF'          => 'unknown',
		'DKIM'         => 'unknown',
		'DMARC'        => 'unknown',
		'dmarc_domain' => $domain,
		'checked_at'   => time(),
	);
	if ( ! is_email( $sender['email'] ) || ! preg_match( '/^[a-z0-9.-]+$/i', $domain ) ) {
		return $result;
	}
	$txt           = mlab_smtp_domain_lookup( $domain, DNS_TXT, $fresh );
	$spf           = array_values( array_filter( mlab_smtp_domain_txt( $txt ), static fn( string $value ): bool => 1 === preg_match( '/^v=spf1(?:\s|$)/i', $value ) ) );
	$result['SPF'] = null === $txt ? 'unknown' : ( 1 === count( $spf ) && ! preg_match( '/(?:^|\s)\+?all(?:\s|$)/i', $spf[0] ) ? 'found' : 'warning' );
	if ( preg_match( '/^[a-z0-9_-]{1,63}$/i', $selector ) ) {
		$result['DKIM'] = mlab_smtp_domain_dkim( $selector . '._domainkey.' . $domain, $fresh );
	}
	$org     = mlab_smtp_organization_domain( $domain );
	$domains = array_unique( array_filter( array( $domain, $org ) ) );
	foreach ( $domains as $candidate ) {
		$records = mlab_smtp_domain_lookup( '_dmarc.' . $candidate, DNS_TXT, $fresh );
		if ( null === $records ) {
			$result['DMARC'] = 'unknown';
			break;
		}
		$dmarc           = array_values( array_filter( mlab_smtp_domain_txt( $records ), static fn( string $value ): bool => 1 === preg_match( '/^v=DMARC1\s*;/i', $value ) ) );
		$result['DMARC'] = 'missing';
		if ( $dmarc ) {
			$result['dmarc_domain'] = $candidate;
			$result['DMARC']        = 1 === count( $dmarc ) && preg_match( '/;\s*p=(none|quarantine|reject)\s*(;|$)/i', $dmarc[0] ) ? 'found' : 'warning';
			break;
		}
	}
	if ( 'resend' === $provider ) {
		// Resend manages SPF on its return-path domain, which need not match the visible From domain.
		$callback       = static function () use ( $domain, $fresh ): array {
			$state = mlab_smtp_resend_domain_state( $domain, $fresh, true );
			if ( $fresh && '' !== $state['id'] && in_array( $state['state'], array( 'pending', 'failed', 'partial' ), true ) ) {
				mlab_smtp_resend_verify( $state['id'] );
			}
			return $state;
		};
		$state          = 'fallback' === $route ? mlab_smtp_with_resend_key( 'fb_resend_api_key', $callback ) : $callback();
		$status         = 'verified' === $state['state'] ? 'provider_verified' : ( in_array( $state['state'], array( 'missing', 'pending', 'failed', 'partial' ), true ) ? 'warning' : 'unknown' );
		$result['SPF']  = $status;
		$result['DKIM'] = $status;
	}
	return $result;
}
