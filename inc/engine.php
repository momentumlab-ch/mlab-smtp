<?php
/**
 * Zustellmotor, Grundlagen: Fehlerklassen, Wiederholungsabstände, Schnappschuss der Nachricht, Identität (UUID, Message-ID, Idempotenz-Key).
 *
 * Versand und Worker liegen in engine-send.php und engine-worker.php.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_ENGINE_LEASE        = 300;
const MLAB_SMTP_ENGINE_BACKOFF_BASE = 60;
const MLAB_SMTP_ENGINE_BACKOFF_CAP  = 6 * HOUR_IN_SECONDS;
const MLAB_SMTP_ENGINE_RETRY_CAP    = DAY_IN_SECONDS;
const MLAB_SMTP_ENGINE_UNCLEAR_MAX  = 23 * HOUR_IN_SECONDS;

add_filter( 'mlab_smtp_failed_payload_days', 'mlab_smtp_engine_payload_days' );

/**
 * Retention of the payload of failed messages in days (setting, 1 to 14).
 *
 * @param int $days Default from the store.
 */
function mlab_smtp_engine_payload_days( $days ): int {
	$set = (int) mlab_smtp_get( 'payload_days' );
	return $set > 0 ? min( 14, $set ) : (int) $days;
}

/**
 * Delivery mode: sync (default) or queue.
 */
function mlab_smtp_engine_mode(): string {
	return 'queue' === mlab_smtp_get( 'delivery_mode' ) ? 'queue' : 'sync';
}

/**
 * HTTP status => class. Everything else: 5xx temporary, other statuses permanent.
 *
 * @return array<int, string>
 */
function mlab_smtp_engine_http_table(): array {
	return array(
		400 => 'permanent',
		401 => 'permanent',
		403 => 'permanent',
		404 => 'permanent',
		408 => 'temporary',
		409 => 'permanent', // Resend: unclear, see mlab_smtp_engine_classify_http().
		413 => 'permanent',
		422 => 'permanent',
		425 => 'temporary',
		429 => 'temporary',
		501 => 'permanent',
	);
}

/**
 * Class of a failure: temporary (try again), permanent, unclear (the provider may have accepted the message) or partial (some recipients rejected).
 *
 * Input (all optional): http_status (0 = no HTTP answer), request_sent (bool, default true), message (text of a transport error),
 * provider, smtp_phase (connect|command|data|data_end), smtp_code, partial, local_failure (nothing was sent, will not get better by itself).
 *
 * @param array $info Facts about the failure.
 * @return string temporary, permanent, unclear or partial.
 */
function mlab_smtp_engine_classify( array $info ): string {
	$info = array_merge(
		array(
			'http_status'   => 0,
			'request_sent'  => true,
			'message'       => '',
			'provider'      => '',
			'smtp_phase'    => '',
			'smtp_code'     => 0,
			'partial'       => false,
			'local_failure' => false,
		),
		$info
	);
	if ( $info['partial'] ) {
		return 'partial';
	}
	if ( $info['local_failure'] ) {
		return 'permanent';
	}
	if ( '' !== $info['smtp_phase'] || (int) $info['smtp_code'] > 0 ) {
		return mlab_smtp_engine_classify_smtp( (string) $info['smtp_phase'], (int) $info['smtp_code'] );
	}
	return mlab_smtp_engine_classify_http( (int) $info['http_status'], (bool) $info['request_sent'], (string) $info['message'], (string) $info['provider'] );
}

/**
 * Class of an SMTP failure. A timeout after the end of the data is unclear, a reply decides everything else.
 *
 * @param string $phase connect, command, data or data_end.
 * @param int    $code  SMTP reply code, 0 when there was none.
 */
function mlab_smtp_engine_classify_smtp( string $phase, int $code ): string {
	if ( 'data_end' === $phase && 0 === $code ) {
		return 'unclear';
	}
	return $code >= 500 ? 'permanent' : 'temporary';
}

/**
 * Class of an HTTP failure.
 *
 * @param int    $status       HTTP status, 0 for a transport error.
 * @param bool   $request_sent True when the request had left.
 * @param string $message      Text of the transport error.
 * @param string $provider     Provider id.
 */
function mlab_smtp_engine_classify_http( int $status, bool $request_sent, string $message, string $provider ): string {
	if ( 0 === $status ) {
		$before_send = ! $request_sent || (bool) preg_match( '/could not resolve|couldn.t connect|failed to connect|connection refused|connection timed out|resolving timed out|network is unreachable|no route to host|ssl connect|certificate|curl error (6|7|35|51|58|60)\b/i', $message );
		return $before_send ? 'temporary' : 'unclear';
	}
	if ( 409 === $status && 'resend' === $provider ) {
		return 'unclear'; // Same idempotency key with other content: the first request may have been accepted.
	}
	$table = mlab_smtp_engine_http_table();
	if ( isset( $table[ $status ] ) ) {
		return $table[ $status ];
	}
	return $status >= 500 ? 'temporary' : 'permanent';
}

/**
 * Random number between 0 and 1. Tests inject their own source.
 *
 * @param callable|null $set Source returning a float from 0 to 1, null to only read.
 */
function mlab_smtp_engine_random( ?callable $set = null ): float {
	static $source = null;
	if ( null !== $set ) {
		$source = $set;
	}
	return null === $source ? wp_rand( 0, 1000000 ) / 1000000 : (float) $source();
}

/**
 * Wait before the next try: 60 s, doubled per try, at most 6 h, plus or minus 20 % jitter (never above the cap).
 *
 * @param int        $attempt Number of the try that just failed (1 = first).
 * @param float|null $random  Number from 0 to 1 (default: the injectable source).
 * @return int Seconds.
 */
function mlab_smtp_engine_backoff( int $attempt, ?float $random = null ): int {
	$random = null === $random ? mlab_smtp_engine_random() : $random;
	$base   = min( MLAB_SMTP_ENGINE_BACKOFF_CAP, MLAB_SMTP_ENGINE_BACKOFF_BASE * ( 2 ** max( 0, min( 20, $attempt - 1 ) ) ) );
	$delay  = (int) round( $base * ( 0.8 + 0.4 * max( 0.0, min( 1.0, $random ) ) ) );
	return min( MLAB_SMTP_ENGINE_BACKOFF_CAP, $delay );
}

/**
 * Seconds until the next try. Retry-After wins over the backoff (at most 24 h).
 *
 * @param int        $attempt     Number of the try that just failed.
 * @param int        $retry_after Retry-After seconds, 0 for none.
 * @param float|null $random      See mlab_smtp_engine_backoff().
 */
function mlab_smtp_engine_delay( int $attempt, int $retry_after, ?float $random = null ): int {
	return $retry_after > 0 ? min( MLAB_SMTP_ENGINE_RETRY_CAP, $retry_after ) : mlab_smtp_engine_backoff( $attempt, $random );
}

/**
 * Key of a pause: route and provider, so the two Resend accounts (main and fallback) pause independently.
 *
 * @param string $route    primary or fallback.
 * @param string $provider Provider id.
 */
function mlab_smtp_engine_pause_key( string $route, string $provider ): string {
	return $route . ':' . $provider;
}

/**
 * Pauses of routes after a Retry-After (key from mlab_smtp_engine_pause_key() => time until which nothing is sent).
 *
 * @param string $provider Key of the pause to set, empty to only read.
 * @param int    $until    Time the pause ends.
 * @return array<string,int> Active pauses.
 */
function mlab_smtp_engine_pauses( string $provider = '', int $until = 0 ): array {
	$now    = mlab_smtp_store_now();
	$pauses = array_filter( (array) get_option( 'mlab_smtp_engine_pauses', array() ), static fn( $t ): bool => (int) $t > $now );
	if ( '' !== $provider && $until > $now ) {
		$pauses[ $provider ] = max( (int) ( $pauses[ $provider ] ?? 0 ), $until );
		update_option( 'mlab_smtp_engine_pauses', $pauses, false );
	}
	return array_map( 'intval', $pauses );
}

/**
 * Providers whose requests carry a verifiable idempotency key (an unclear result may be repeated within 23 hours).
 *
 * @return string[]
 */
function mlab_smtp_engine_idempotent_providers(): array {
	return array( 'resend' );
}

/**
 * Idempotency key of a message and route. Stable for the life of the message (a manual resend bumps the counter).
 *
 * @param string $uuid   Message uuid.
 * @param string $route  primary or fallback.
 * @param int    $resend Counter of manual resends.
 */
function mlab_smtp_engine_idem_key( string $uuid, string $route, int $resend = 0 ): string {
	return 'mlab-' . substr( hash( 'sha256', $uuid . '|' . $route . ( $resend > 0 ? '|r' . $resend : '' ) ), 0, 48 );
}

/**
 * Gives the mail its uuid and a stable Message-ID (unless another plugin set its own). Runs in phpmailer_init.
 *
 * @param Mlab_Smtp_Mailer $mailer Mailer of wp_mail().
 */
function mlab_smtp_engine_identify( $mailer ): void {
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
	$uuid               = wp_generate_uuid4();
	$mailer->mlab_uuid  = $uuid;
	$mailer->mlab_msgid = '';
	// The mailer object is reused for every mail: only an ID that is not ours (set by another plugin) stays.
	if ( '' !== $mailer->MessageID && ! str_starts_with( (string) $mailer->MessageID, '<mlab-' ) ) {
		return;
	}
	$host = strtolower( (string) preg_replace( '/[^a-z0-9.-]/i', '', (string) substr( (string) strrchr( (string) $mailer->From, '@' ), 1 ) ) );
	if ( '' === $host ) {
		$host = (string) preg_replace( '/[^a-z0-9.-]/i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}
	$mailer->mlab_msgid = '<mlab-' . str_replace( '-', '', $uuid ) . '@' . ( '' !== $host ? $host : 'localhost' ) . '>';
	$mailer->MessageID  = $mailer->mlab_msgid;
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}

/**
 * Pairs [address, name] of a PHPMailer address list.
 *
 * @param array $addresses Result of getToAddresses() and friends.
 * @return array<int, array{0:string,1:string}>
 */
function mlab_smtp_engine_pairs( array $addresses ): array {
	return array_values(
		array_map(
			static fn( $pair ): array => array( (string) ( $pair[0] ?? '' ), (string) ( $pair[1] ?? '' ) ),
			$addresses
		)
	);
}

/**
 * Attachments of the mailer as Base64 items (path attachments are read now).
 *
 * @param Mlab_Smtp_Mailer $mailer Prepared mailer.
 * @return array|WP_Error
 */
function mlab_smtp_engine_snapshot_attachments( $mailer ) {
	$out = array();
	foreach ( $mailer->getAttachments() as $a ) {
		// Index: 0 path or content, 1 file name, 2 display name, 3 encoding, 4 type, 5 content given as string, 6 disposition, 7 content id.
		$content = $a[5] ? (string) $a[0] : file_get_contents( (string) $a[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local attachment file.
		if ( false === $content ) {
			/* translators: %s: file name */
			return new WP_Error( 'mlab_smtp_attachment', sprintf( __( 'Der Anhang «%s» konnte nicht gelesen werden.', 'mlab-smtp' ), (string) $a[1] ) );
		}
		$out[] = array(
			'name'        => '' !== (string) $a[2] ? (string) $a[2] : (string) $a[1],
			'type'        => (string) $a[4],
			'encoding'    => (string) $a[3],
			'disposition' => (string) $a[6],
			'cid'         => (string) $a[7],
			'content'     => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary content in a JSON snapshot.
		);
	}
	return $out;
}

/**
 * Rough size of the encoded snapshot (Base64 attachments and bodies), to refuse a message before building it.
 *
 * @param Mlab_Smtp_Mailer $mailer Prepared mailer.
 */
function mlab_smtp_engine_estimate_size( $mailer ): int {
	$size = strlen( (string) $mailer->Body ) + strlen( (string) $mailer->AltBody ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
	foreach ( $mailer->getAttachments() as $a ) {
		$size += (int) ceil( ( $a[5] ? strlen( (string) $a[0] ) : (int) @filesize( (string) $a[0] ) ) / 3 ) * 4; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing file is reported when it is read.
	}
	return (int) ceil( $size * 4 / 3 );
}

/**
 * Snapshot of the finished message (after preSend), for the queue.
 *
 * @param Mlab_Smtp_Mailer $mailer Prepared mailer.
 * @return array|WP_Error Snapshot, or an error when the message is too large or an attachment is unreadable.
 */
function mlab_smtp_engine_snapshot( $mailer ) {
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
	if ( mlab_smtp_engine_estimate_size( $mailer ) > mlab_smtp_store_max_bytes() ) {
		return new WP_Error( 'mlab_smtp_payload_size', __( 'Die Nachricht ist für die Warteschlange zu gross.', 'mlab-smtp' ) );
	}
	$attachments = mlab_smtp_engine_snapshot_attachments( $mailer );
	if ( is_wp_error( $attachments ) ) {
		return $attachments;
	}
	return array(
		'v'            => 1,
		'from'         => (string) $mailer->From,
		'from_name'    => (string) $mailer->FromName,
		'sender'       => (string) $mailer->Sender,
		'to'           => mlab_smtp_engine_pairs( $mailer->getToAddresses() ),
		'cc'           => mlab_smtp_engine_pairs( $mailer->getCcAddresses() ),
		'bcc'          => mlab_smtp_engine_pairs( $mailer->getBccAddresses() ),
		'reply_to'     => mlab_smtp_engine_pairs( array_values( $mailer->getReplyToAddresses() ) ),
		'subject'      => (string) $mailer->Subject,
		'body'         => (string) $mailer->Body,
		'alt_body'     => (string) $mailer->AltBody,
		'html'         => 'text/plain' !== $mailer->ContentType,
		'charset'      => (string) $mailer->CharSet,
		'encoding'     => (string) $mailer->Encoding,
		'priority'     => $mailer->Priority,
		'headers'      => array_values( $mailer->getCustomHeaders() ),
		'attachments'  => $attachments,
		'message_id'   => (string) $mailer->getLastMessageID(),
		'resend'       => 0,
		'content_type' => (string) $mailer->ContentType,
	);
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}

/**
 * Builds a fresh mailer from a snapshot. phpmailer_init does not run again (filter mlab_smtp_worker_run_phpmailer_init,
 * default false): settings another plugin only puts on the PHPMailer object do not apply to queued messages.
 *
 * @param array $s Snapshot.
 * @return Mlab_Smtp_Mailer
 * @throws \PHPMailer\PHPMailer\Exception When PHPMailer refuses an address.
 */
function mlab_smtp_engine_mailer_from_snapshot( array $s ) {
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
	require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
	require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
	require_once MLAB_SMTP_DIR . 'inc/class-mlab-smtp-mailer.php';
	$m              = new Mlab_Smtp_Mailer( true );
	$m->mlab_worker = true;
	$m->CharSet     = (string) ( $s['charset'] ?? 'UTF-8' );
	$m->Mailer      = 'sendmail'; // API-style header (keeps Bcc); the SMTP route switches before it builds again.
	$m->setFrom( (string) $s['from'], (string) ( $s['from_name'] ?? '' ), false );
	$m->Sender = (string) ( $s['sender'] ?? '' );
	foreach ( array(
		'addAddress' => 'to',
		'addCC'      => 'cc',
		'addBCC'     => 'bcc',
		'addReplyTo' => 'reply_to',
	) as $method => $key ) {
		foreach ( (array) ( $s[ $key ] ?? array() ) as $pair ) {
			$m->$method( (string) $pair[0], (string) $pair[1] );
		}
	}
	$m->Subject     = (string) $s['subject'];
	$m->Body        = (string) $s['body'];
	$m->AltBody     = (string) ( $s['alt_body'] ?? '' );
	$m->ContentType = ! empty( $s['html'] ) ? 'text/html' : 'text/plain';
	$m->Priority    = $s['priority'] ?? null;
	$m->Encoding    = (string) ( $s['encoding'] ?? '8bit' );
	foreach ( (array) ( $s['headers'] ?? array() ) as $header ) {
		$m->addCustomHeader( (string) $header[0], (string) ( $header[1] ?? '' ) );
	}
	mlab_smtp_engine_restore_attachments( $m, (array) ( $s['attachments'] ?? array() ) );
	$m->MessageID = (string) ( $s['message_id'] ?? '' );
	if ( apply_filters( 'mlab_smtp_worker_run_phpmailer_init', false, $m ) ) {
		do_action_ref_array( 'phpmailer_init', array( &$m ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook, so SMTP plugins and filters see the queued mail.
	}
	$m->preSend();
	return $m;
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}

/**
 * Adds the attachments of a snapshot to a mailer.
 *
 * @param Mlab_Smtp_Mailer $m           Mailer.
 * @param array            $attachments Attachments of the snapshot.
 */
function mlab_smtp_engine_restore_attachments( $m, array $attachments ): void {
	foreach ( $attachments as $a ) {
		$content     = base64_decode( (string) $a['content'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Own snapshot.
		$content     = false === $content ? '' : $content;
		$encoding    = '' !== (string) $a['encoding'] ? (string) $a['encoding'] : 'base64';
		$type        = (string) $a['type'];
		$disposition = '' !== (string) $a['disposition'] ? (string) $a['disposition'] : 'attachment';
		if ( 'inline' === $disposition && '' !== (string) $a['cid'] ) {
			$m->addStringEmbeddedImage( $content, (string) $a['cid'], (string) $a['name'], $encoding, $type, $disposition );
		} else {
			$m->addStringAttachment( $content, (string) $a['name'], $encoding, $type, $disposition );
		}
	}
}
