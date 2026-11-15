<?php
// Independent backup identity, advisory DNS and existing license-server integration.
require dirname( __DIR__ ) . '/bootstrap-wp.php';

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
delete_option( MLAB_SMTP_OPTION );
delete_option( MLAB_SMTP_READINESS_OPTION );
mlab_smtp_store( array( 'provider' => 'm365', 'from_email' => 'info@example.ch', 'from_name' => 'Primary', 'reply_to' => 'reply@example.ch', 'fb_provider' => 'smtp', 'fb_smtp_host' => 'smtp.example.net', 'fb_from_email' => 'website@send.example.ch', 'fb_from_name' => 'Backup', 'fb_reply_to' => 'info@example.ch', 'fb_dkim_selector' => 'web' ) );
assert_same( 'info@example.ch', mlab_smtp_sender_for_route( 'primary' )['email'], 'primary sender remains Exchange mailbox' );
assert_same( 'website@send.example.ch', mlab_smtp_sender_for_route( 'fallback' )['email'], 'backup has its own sender domain' );
$primary = mlab_smtp_route_fingerprint( 'primary' );
$backup = mlab_smtp_route_fingerprint( 'fallback' );
mlab_smtp_store( array( 'fb_from_email' => 'site@send.example.ch' ) );
assert_same( $primary, mlab_smtp_route_fingerprint( 'primary' ), 'backup sender does not invalidate primary readiness' );
assert_true( $backup !== mlab_smtp_route_fingerprint( 'fallback' ), 'backup sender invalidates its own test' );
assert_same( 4, mlab_smtp_fallback_post_sender( array( 'fb_from_email' => 'invalid' ) ), 'invalid backup sender cannot be saved' );
assert_same( 'info@example.ch', mlab_smtp_get( 'from_email' ), 'backup form cannot modify primary sender' );

mlab_smtp_store( array( 'fb_from_name' => 'Backup', 'fb_reply_to' => 'info@example.ch', 'fb_dkim_selector' => 'web' ) );
require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
$mailer = new \PHPMailer\PHPMailer\PHPMailer( true );
$mailer->setFrom( 'info@example.ch', 'Primary' );
$mailer->addReplyTo( 'visitor@example.com' );
mlab_smtp_apply_fallback_sender( $mailer );
assert_same( 'site@send.example.ch', $mailer->From, 'SMTP From uses backup identity' );
assert_same( 'site@send.example.ch', $mailer->Sender, 'SMTP envelope uses backup identity' );
assert_same( 'Backup', $mailer->FromName, 'backup name applied' );
assert_same( array( 'info@example.ch' ), array_column( $mailer->getReplyToAddresses(), 0 ), 'explicit backup reply address overrides form' );
mlab_smtp_store( array( 'fb_reply_to' => '' ) );
$mailer->clearReplyTos();
$mailer->addReplyTo( 'visitor@example.com' );
mlab_smtp_apply_fallback_sender( $mailer );
assert_same( array( 'visitor@example.com' ), array_column( $mailer->getReplyToAddresses(), 0 ), 'empty backup reply preserves contact-form address' );
mlab_smtp_store( array( 'fb_from_email' => '', 'fb_from_name' => '' ) );
assert_same( 'info@example.ch', mlab_smtp_sender_for_route( 'fallback' )['email'], 'legacy backup inherits primary address' );
mlab_smtp_store( array( 'fb_from_email' => 'site@send.example.ch', 'fb_from_name' => 'Backup' ) );

// Exercise the actual SMTP route without network I/O: inspect the rebuilt MIME and envelope.
require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
require_once MLAB_SMTP_DIR . 'inc/class-mlab-smtp-mailer.php';
class SDL_Mailer extends Mlab_Smtp_Mailer {
	public $captured = array();
	protected function smtpSend( $header, $body ) {
		$this->captured = array( 'header' => $header, 'sender' => $this->Sender, 'from' => $this->From );
		return true;
	}
}
$smtp = new SDL_Mailer( true );
$smtp->setFrom( 'info@example.ch', 'Primary' );
$smtp->Sender = 'info@example.ch';
$smtp->addAddress( 'person@example.com' );
$smtp->Body = 'Independent backup';
$smtp->preSend();
mlab_smtp_flag( 'fallback_test', true );
$smtp->send_route( 'fallback' );
mlab_smtp_flag( 'fallback_test', false );
assert_same( 'site@send.example.ch', $smtp->captured['sender'], 'actual SMTP route uses backup envelope' );
assert_contains( 'site@send.example.ch', $smtp->captured['header'], 'actual SMTP route rebuilds MIME From' );
assert_true( ! str_contains( $smtp->captured['header'], 'info@example.ch' ), 'primary mailbox does not leak into backup MIME sender' );
assert_same( 'info@example.ch', mlab_smtp_sender_for_route( 'primary' )['email'], 'SMTP backup leaves primary settings untouched' );

// Seed native cache without using the network.
function sdl_dns( string $host, int $type, ?array $records ): void {
	set_transient( 'mlab_smtp_dns_' . md5( $type . '|' . strtolower( $host ) ), null === $records ? array( 'status' => 'fail' ) : array( 'status' => 'ok', 'records' => $records ), 600 );
}
sdl_dns( 'send.example.ch', DNS_TXT, array( array( 'entries' => array( 'v=spf1 ', 'include:hoster.example -all' ) ) ) );
sdl_dns( 'web._domainkey.send.example.ch', DNS_TXT, array( array( 'txt' => 'v=DKIM1; p=YWJjZA==' ) ) );
sdl_dns( '_dmarc.send.example.ch', DNS_TXT, array() );
sdl_dns( '_dmarc.example.ch', DNS_TXT, array( array( 'txt' => 'v=DMARC1; p=reject; sp=quarantine' ) ) );
$readiness = mlab_smtp_readiness_get();
$report = mlab_smtp_domain_report( 'fallback' );
assert_same( 'send.example.ch', $report['domain'], 'DNS checks backup domain, not primary or SMTP host' );
assert_same( 'found', $report['SPF'], 'SPF split TXT chunks joined' );
assert_same( 'found', $report['DKIM'], 'provided selector finds DKIM public key' );
assert_same( 'found', $report['DMARC'], 'DMARC inherits organization policy' );
assert_same( 'example.ch', $report['dmarc_domain'], 'inherited DMARC domain identified' );
assert_same( $readiness, mlab_smtp_readiness_get(), 'DNS inspection does not change readiness' );
assert_same( 'example.co.uk', mlab_smtp_organization_domain( 'send.example.co.uk' ), 'multi-label public suffix handled' );
assert_same( 'city.kawasaki.jp', mlab_smtp_organization_domain( 'send.city.kawasaki.jp' ), 'PSL exception handled' );
assert_same( 'test.foo.ck', mlab_smtp_organization_domain( 'send.test.foo.ck' ), 'PSL wildcard handled' );
sdl_dns( 'send.example.ch', DNS_TXT, array( array( 'txt' => 'v=spf1 -all' ), array( 'txt' => 'v=spf1 ~all' ) ) );
assert_same( 'warning', mlab_smtp_domain_report( 'fallback' )['SPF'], 'multiple SPF records warn' );
sdl_dns( 'send.example.ch', DNS_TXT, array( array( 'txt' => 'v=spf1 +all' ) ) );
assert_same( 'warning', mlab_smtp_domain_report( 'fallback' )['SPF'], 'permissive SPF warns' );
mlab_smtp_store( array( 'fb_dkim_selector' => '' ) );
assert_same( 'unknown', mlab_smtp_domain_report( 'fallback' )['DKIM'], 'unknown selector is not called missing' );
sdl_dns( 'alias._domainkey.send.example.ch', DNS_TXT, array() );
sdl_dns( 'alias._domainkey.send.example.ch', DNS_CNAME, array( array( 'target' => 'key.hoster.example.' ) ) );
sdl_dns( 'key.hoster.example', DNS_TXT, array( array( 'txt' => 'v=DKIM1; p=YWJjZA==' ) ) );
assert_same( 'found', mlab_smtp_domain_dkim( 'alias._domainkey.send.example.ch', false ), 'DKIM CNAME delegation followed' );
sdl_dns( 'key.hoster.example', DNS_TXT, array() );
sdl_dns( 'key.hoster.example', DNS_CNAME, array( array( 'target' => 'alias._domainkey.send.example.ch' ) ) );
assert_same( 'unknown', mlab_smtp_domain_dkim( 'alias._domainkey.send.example.ch', false ), 'CNAME cycle bounded' );

// Recheck must invalidate the underlying native cache. A pre-transient fixture avoids real DNS.
$cache = 'mlab_smtp_dns_' . md5( DNS_TXT . '|send.example.ch' );
$refresh = static fn() => array( 'status' => 'ok', 'records' => array( array( 'txt' => 'fresh' ) ) );
add_filter( 'pre_transient_' . $cache, $refresh );
assert_same( array( array( 'txt' => 'fresh' ) ), mlab_smtp_domain_lookup( 'send.example.ch', DNS_TXT, true ), 'explicit refresh uses fresh lookup' );
remove_filter( 'pre_transient_' . $cache, $refresh );
assert_same( false, get_transient( $cache ), 'old underlying DNS cache removed by recheck' );

// Resend uses backup key and effective backup sender. Sending-only does not imply DNS failure.
mlab_smtp_store( array( 'fb_provider' => 'resend', 'fb_resend_api_key' => 're_backup_license_dns_test' ) );
mlab_http_reset();
mlab_http_mock( '#api\.resend\.com/domains#', 401, array( 'name' => 'restricted_api_key', 'message' => 'This API key is restricted to only send emails' ) );
$report = mlab_smtp_domain_report( 'fallback' );
assert_same( 'unknown', $report['SPF'], 'Resend restricted key gives unknown SPF, not false missing' );
assert_same( 'unknown', $report['DKIM'], 'Resend restricted key gives unknown DKIM' );
assert_same( 'Bearer re_backup_license_dns_test', mlab_http_requests()[0]['args']['headers']['Authorization'], 'backup DNS uses backup API key' );
mlab_http_reset();
mlab_http_mock( '#api\.resend\.com/emails#', 200, array( 'id' => 'backup-sent' ) );
$mailer->addAddress( 'recipient@example.com' );
$mailer->Body = 'Backup message';
assert_same( true, mlab_smtp_send_resend_fallback( $mailer ), 'Resend backup submits message' );
$payload = json_decode( mlab_http_requests()[0]['args']['body'], true );
assert_same( 'Backup <site@send.example.ch>', $payload['from'], 'Resend sends from independent backup identity' );

// Existing server contract: strict product grants, secret headers, bounded offline grace.
mlab_smtp_store( array( 'license_url' => 'https://license.example.com', 'license_key' => 'MLAB-test-license-12345' ) );
mlab_http_reset();
mlab_http_mock( '#license\.example\.com/wp-json/mlab-ls/v1/catalog#', 200, array( 'products' => array( array( 'slug' => 'mlab-smtp-pro', 'unlocked' => true ) ) ) );
assert_same( true, mlab_smtp_license_refresh(), 'catalog grants existing product' );
assert_true( mlab_smtp_has_pro(), 'valid local grant unlocks Pro' );
$request = mlab_http_requests()[0];
assert_same( 'MLAB-test-license-12345', $request['args']['headers']['X-MLAB-License'], 'key sent in header' );
assert_true( ! str_contains( $request['url'], 'MLAB-test' ), 'key absent from URL' );
assert_same( 0, $request['args']['redirection'], 'no license redirects' );
$raw = get_option( MLAB_SMTP_OPTION );
assert_true( ! str_contains( wp_json_encode( $raw ), 'MLAB-test-license-12345' ), 'license key encrypted at rest' );
mlab_http_reset();
assert_true( is_wp_error( mlab_smtp_license_refresh() ), 'offline response reported' );
assert_true( mlab_smtp_has_pro(), 'temporary outage preserves recent grant' );
$grant = get_option( 'mlab_smtp_license_grant' );
$grant['checked_at'] = time() - 8 * DAY_IN_SECONDS;
update_option( 'mlab_smtp_license_grant', $grant );
assert_true( ! mlab_smtp_has_pro(), 'offline grace expires after seven days' );
mlab_http_mock( '#license\.example\.com/#', 200, array( 'products' => array( array( 'slug' => 'mlab-smtp-pro', 'unlocked' => true ) ) ) );
mlab_smtp_license_refresh();
mlab_smtp_store( array( 'license_key' => 'changed-key' ) );
assert_true( ! mlab_smtp_has_pro(), 'grant cannot survive a changed key' );
mlab_smtp_store( array( 'license_key' => 'MLAB-test-license-12345' ) );
mlab_http_mock( '#license\.example\.com/#', 200, array( 'products' => array() ) );
mlab_smtp_license_refresh();
assert_true( ! mlab_smtp_has_pro(), 'authoritative missing grant revokes Pro' );
mlab_http_mock( '#license\.example\.com/#', 403, array() );
assert_true( is_wp_error( mlab_smtp_license_refresh() ), 'denied site produces error' );
assert_same( false, get_option( 'mlab_smtp_license_grant' ), 'denied site clears cached grant' );
assert_same( 'info@example.ch', mlab_smtp_get( 'from_email' ), 'license changes never alter primary sender' );
assert_same( 'site@send.example.ch', mlab_smtp_get( 'fb_from_email' ), 'license changes never alter backup sender' );
delete_option( MLAB_SMTP_OPTION );
delete_option( MLAB_SMTP_READINESS_OPTION );
delete_option( 'mlab_smtp_license_grant' );
t_finish();
