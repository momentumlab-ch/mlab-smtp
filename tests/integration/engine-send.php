<?php
// Engine, Versand: Zeile vor dem Senden, Rückgabewerte je Modus, Ersatzweg-Regeln, unklare Ergebnisse, Hintergrund-Wiederholung.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/engine-helpers.php';

$ok_reply = static fn(): array => array( 'code' => 200, 'body' => array( 'id' => 'em_ok' ) );
$by_key   = static function ( array $map ) {
	return static function ( $url, $args, $n ) use ( $map ) {
		$auth = $args['headers']['Authorization'] ?? '';
		$r    = $map[ $auth ] ?? $map['*'] ?? array( 'code' => 200, 'body' => array( 'id' => 'em_ok' ) );
		return is_callable( $r ) ? $r( $n ) : $r;
	};
};
$events = array();
add_action( 'mlab_smtp_message_accepted', static function ( $e ) use ( &$events ) { $events[] = array( 'accepted', $e ); } );
add_action( 'mlab_smtp_message_failed', static function ( $e ) use ( &$events ) { $events[] = array( 'failed', $e ); } );
$succeeded = 0;
add_action( 'wp_mail_succeeded', static function () use ( &$succeeded ) { ++$succeeded; } );

// 1. Sync, Erfolg: Zeile, Versuch, Schlüssel, Tag, Anbieter-ID, keine Nutzlast.
mlab_e_reset();
mlab_e_reply( '#api\.resend\.com/emails#', $ok_reply );
assert_same( true, wp_mail( 'user@example.test', 'Betreff', 'Text' ), 'sync: wp_mail is true when the provider accepted' );
$row = mlab_e_last_row();
assert_same( 'accepted', $row['status'], 'sync: row is accepted' );
assert_same( 'sync', $row['mode'], 'sync: mode sync' );
assert_same( 'em_ok', $row['provider_msg_id'], 'sync: provider message id stored' );
assert_same( 0, (int) $row['has_payload'], 'sync: no payload stored' );
$att = mlab_smtp_store_attempts( (int) $row['id'] );
assert_same( 1, count( $att ), 'sync: one attempt for the primary route' );
assert_same( 'accepted', $att[0]['outcome'], 'sync: attempt accepted' );
assert_true( (int) $att[0]['request_sent_at'] > 0, 'sync: request_sent_at is written' );
$req  = mlab_e_resend_requests()[0];
$body = json_decode( $req['args']['body'], true );
assert_same( mlab_smtp_engine_idem_key( $row['uuid'], 'primary' ), mlab_e_header( $req, 'Idempotency-Key' ), 'sync: request carries the stored idempotency key' );
assert_same( $att[0]['idem_key'], mlab_e_header( $req, 'Idempotency-Key' ), 'sync: attempt stores the same key' );
assert_same( str_replace( '-', '', $row['uuid'] ), $body['tags'][0]['value'], 'sync: Resend tag mlab_id is the uuid without dashes' );
assert_same( 'mlab_id', $body['tags'][0]['name'], 'sync: tag name' );
assert_true( (bool) preg_match( '/^[A-Za-z0-9_-]+$/', $body['tags'][0]['value'] ), 'sync: tag value has only allowed characters' );
assert_same( 1, count( $events ), 'sync: one engine event' );
assert_same( 'accepted', $events[0][0], 'sync: mlab_smtp_message_accepted fired' );
assert_same( $row['uuid'], $events[0][1]['uuid'], 'sync: event carries the uuid' );
assert_same( 1, count( mlab_smtp_log_get() ), 'sync: exactly one log entry (the row of the engine)' );
assert_true( mlab_smtp_state_get()['last_success'] > 0, 'sync: the counters saw the delivery' );
assert_true( str_starts_with( $att[0]['mail_message_id'], '<mlab-' ), 'sync: stable Message-ID from the uuid' );

// 2. Die Zeile steht vor dem Senden (Fatal Error mitten im Versand hinterlässt eine Spur).
mlab_e_reset();
$seen = null;
mlab_e_reply(
	'#api\.resend\.com/emails#',
	static function () use ( &$seen ) {
		$row  = mlab_e_last_row();
		$seen = array( 'row' => $row, 'attempts' => $row ? mlab_smtp_store_attempts( (int) $row['id'] ) : array() );
		return array( 'code' => 200, 'body' => array( 'id' => 'em_x' ) );
	}
);
wp_mail( 'user@example.test', 'Betreff', 'Text' );
assert_same( 'processing', $seen['row']['status'] ?? '', 'before send: the row exists with status processing' );
assert_true( (int) ( $seen['row']['locked_until'] ?? 0 ) >= time() + 290, 'before send: lease of about 300 s' );
assert_same( 'sync', $seen['row']['mode'] ?? '', 'before send: mode sync' );
assert_same( 1, count( $seen['attempts'] ), 'before send: the attempt row exists' );
assert_true( (int) ( $seen['attempts'][0]['request_sent_at'] ?? 0 ) > 0, 'before send: request_sent_at is already written' );
// Ein verwaister processing-Satz wird nach Ablauf der Lease unklar.
$orphan = mlab_smtp_store_create( array( 'mode' => 'sync', 'lease' => 300, 'recipients' => 'user@example.test', 'subject' => 's' ) );
mlab_smtp_store_sweep( time() + 400 );
assert_same( 'unclear', mlab_smtp_store_get( $orphan )['status'], 'a fatal error in the middle leaves an unclear row after the lease' );

// 3. Sync, Fehler ohne Ersatzweg.
mlab_e_reset();
mlab_e_reply( '#api\.resend\.com/emails#', static fn() => array( 'code' => 500, 'body' => array( 'name' => 'application_error', 'message' => 'boom' ) ) );
assert_same( false, wp_mail( 'user@example.test', 'Betreff', 'Text' ), 'sync: wp_mail is false when the provider failed' );
$row = mlab_e_last_row();
assert_same( 'failed', $row['status'], 'sync failure: row failed' );
assert_same( 'temporary', $row['error_class'], 'sync failure: class temporary' );
assert_contains( 'application_error', $row['last_error'], 'sync failure: error stored' );
assert_same( 0, (int) $row['has_payload'], 'sync failure: no payload without background retry' );
assert_same( 'failed', end( $events )[0], 'sync failure: mlab_smtp_message_failed fired' );
assert_same( 1, count( mlab_smtp_log_get() ), 'sync failure: one log entry' );

// 4. Ersatzweg nach einem Fehler.
mlab_e_reset();
mlab_e_with_fallback();
mlab_e_reply( '#api\.resend\.com/emails#', $by_key( array( 'Bearer ' . ENG_KEY => array( 'code' => 503, 'body' => array( 'name' => 'x', 'message' => 'down' ) ) ) ) );
assert_same( true, wp_mail( 'user@example.test', 'Betreff', 'Text' ), 'fallback delivers after a temporary primary failure' );
$row = mlab_e_last_row();
assert_same( 'accepted', $row['status'], 'fallback: row accepted' );
assert_same( 1, (int) $row['fallback'], 'fallback: flagged' );
assert_same( 2, count( mlab_smtp_store_attempts( (int) $row['id'] ) ), 'fallback: one attempt per route' );
$reqs = mlab_e_resend_requests();
assert_true( mlab_e_header( $reqs[0], 'Idempotency-Key' ) !== mlab_e_header( $reqs[1], 'Idempotency-Key' ), 'fallback: each route has its own idempotency key' );
assert_same( mlab_smtp_engine_idem_key( $row['uuid'], 'fallback' ), mlab_e_header( $reqs[1], 'Idempotency-Key' ), 'fallback: key derived from uuid and route' );

// 5. Unklar im Modus sync: Wechsel auf den Ersatzweg bleibt, als solcher vermerkt.
mlab_e_reset();
mlab_e_with_fallback();
mlab_e_reply( '#api\.resend\.com/emails#', $by_key( array( 'Bearer ' . ENG_KEY => static fn() => mlab_e_timeout() ) ) );
assert_same( true, wp_mail( 'user@example.test', 'Betreff', 'Text' ), 'sync: fallback after unclear stays active (default)' );
$row = mlab_e_last_row();
$att = mlab_smtp_store_attempts( (int) $row['id'] );
assert_same( 'unknown', $att[0]['outcome'], 'sync: the timeout is an unclear attempt' );
assert_same( 'fallback_after_unclear', $att[1]['error_code'], 'sync: the fallback attempt carries the note' );
assert_contains( 'fallback_after_unclear', $row['last_error'], 'sync: the message row names the fallback after unclear' );
mlab_e_reset( array( 'unclear_failover' => 0 ) );
mlab_e_with_fallback();
mlab_e_reply( '#api\.resend\.com/emails#', static fn() => mlab_e_timeout() );
assert_same( false, wp_mail( 'user@example.test', 'Betreff', 'Text' ), 'sync: with the setting off there is no fallback after unclear' );
assert_same( 1, count( mlab_e_resend_requests() ), 'sync: only one request without fallback after unclear' );
assert_same( 'unclear', mlab_e_last_row()['status'], 'sync: the row is unclear, not failed' );
assert_same( 'unclear', end( $events )[1]['status'], 'sync: event reports unclear' );

// 6. 409 ist unklar.
mlab_e_reset( array( 'unclear_failover' => 0 ) );
mlab_e_reply( '#api\.resend\.com/emails#', static fn() => array( 'code' => 409, 'body' => array( 'name' => 'invalid_idempotent_request', 'message' => 'Same key, different payload' ) ) );
assert_same( false, wp_mail( 'user@example.test', 'Betreff', 'Text' ), '409: wp_mail is false' );
assert_same( 'unclear', mlab_e_last_row()['status'], '409: the message is unclear, not a permanent failure' );

// 7. Hintergrund-Wiederholung nur bei vorübergehendem Fehler.
mlab_e_reset( array( 'retry_in_background' => 1 ) );
$succeeded = 0;
mlab_e_reply( '#api\.resend\.com/emails#', static fn() => array( 'code' => 503, 'body' => array( 'name' => 'x', 'message' => 'down' ) ) );
assert_same( true, wp_mail( 'user@example.test', 'Betreff', 'Text' ), 'retry_in_background: wp_mail is true after a temporary failure' );
$row = mlab_e_last_row();
assert_same( 'temp_failed', $row['status'], 'retry_in_background: row waits for a retry' );
assert_same( 'queued', $row['mode'], 'retry_in_background: row is now a queue message' );
assert_same( 1, (int) $row['has_payload'], 'retry_in_background: encrypted payload stored' );
assert_true( abs( (int) $row['next_attempt_at'] - ( time() + 60 ) ) <= 15, 'retry_in_background: first retry after about 60 s' );
assert_same( 0, mlab_smtp_state_get()['failures_since'], 'retry_in_background: the enqueue is not counted as a failure' );
assert_same( 0, mlab_smtp_state_get()['last_success'], 'retry_in_background: the enqueue is not counted as a delivery' );
assert_same( 1, count( mlab_smtp_log_get() ), 'retry_in_background: one log entry, status unchanged by the core hook' );
assert_same( 'temp_failed', mlab_smtp_log_get()[0]['status'], 'retry_in_background: log shows temp_failed' );
assert_same( 1, $succeeded, 'core still fires wp_mail_succeeded for the enqueue (documented deviation)' );
mlab_e_reset( array( 'retry_in_background' => 1 ) );
mlab_e_reply( '#api\.resend\.com/emails#', static fn() => array( 'code' => 422, 'body' => array( 'name' => 'validation_error', 'message' => 'bad' ) ) );
assert_same( false, wp_mail( 'user@example.test', 'Betreff', 'Text' ), 'retry_in_background: a permanent failure is reported at once' );
assert_same( 'failed', mlab_e_last_row()['status'], 'retry_in_background: permanent failure is final' );
mlab_e_reset( array( 'retry_in_background' => 1 ) );
mlab_e_reply( '#api\.resend\.com/emails#', static fn() => mlab_e_timeout() );
assert_same( true, wp_mail( 'user@example.test', 'Betreff', 'Text' ), 'retry_in_background: unclear on Resend may be retried with the same key' );
mlab_e_reset( array( 'retry_in_background' => 1, 'provider' => 'm365', 'm365_tenant' => 't', 'm365_client_id' => 'c', 'm365_client_secret' => 'secret-1234567890' ) );
mlab_e_reply( '#login\.microsoftonline#', static fn() => array( 'code' => 200, 'body' => array( 'access_token' => 'T', 'expires_in' => 3600 ) ) );
mlab_e_reply( '#graph\.microsoft\.com#', static fn() => mlab_e_timeout() );
assert_same( false, wp_mail( 'user@example.test', 'Betreff', 'Text' ), 'retry_in_background: unclear on M365 is never retried automatically' );
assert_same( 'unclear', mlab_e_last_row()['status'], 'retry_in_background: M365 timeout is unclear' );

// 8. Modus queue: true nach dem Speichern, nichts gesendet, Statistik unberührt.
mlab_e_reset( array( 'delivery_mode' => 'queue' ) );
$succeeded = 0;
mlab_e_reply( '#api\.resend\.com/emails#', $ok_reply );
$file = wp_tempnam( 'engine' );
file_put_contents( $file, "PDF\0BIN" );
assert_same( true, wp_mail( array( 'user@example.test' ), 'Betreff', '<p>Hallo</p>', array( 'Content-Type: text/html; charset=UTF-8', 'Bcc: bcc@example.test', 'Cc: cc@example.test', 'X-Eins: 1' ), array( $file ) ), 'queue: wp_mail is true (accepted for sending)' );
assert_same( 0, count( mlab_http_requests() ), 'queue: nothing is sent while queueing' );
$row = mlab_e_last_row();
assert_same( 'queued', $row['status'], 'queue: row is queued' );
assert_same( 'queued', $row['mode'], 'queue: mode queued' );
$snap = mlab_smtp_store_get_payload( (int) $row['id'] );
assert_true( is_array( $snap ), 'queue: payload is stored and readable' );
assert_same( array( array( 'bcc@example.test', '' ) ), $snap['bcc'], 'queue: Bcc in the snapshot' );
assert_same( base64_encode( "PDF\0BIN" ), $snap['attachments'][0]['content'], 'queue: attachment read at enqueue time' );
assert_same( '<p>Hallo</p>', $snap['body'], 'queue: body in the snapshot' );
assert_true( str_starts_with( $snap['message_id'], '<mlab-' ), 'queue: stable Message-ID in the snapshot' );
assert_same( 0, mlab_smtp_state_get()['last_success'], 'queue: the enqueue is not counted as a delivery' );
assert_same( 1, count( mlab_smtp_log_get() ), 'queue: one log entry' );
assert_same( 'queued', mlab_smtp_log_get()[0]['status'], 'queue: the log shows queued, not delivered' );
assert_same( 1, $succeeded, 'queue: core fires wp_mail_succeeded at enqueue time' );
$raw = (string) $GLOBALS['wpdb']->get_var( 'SELECT payload FROM ' . mlab_smtp_table( 'messages' ) . ' WHERE id = ' . (int) $row['id'] );
assert_true( ! str_contains( $raw, 'Hallo' ) && ! str_contains( $raw, 'user@example.test' ), 'queue: the payload is encrypted' );
unlink( $file );

// 9. Zu grosse Nachricht wird nie eingereiht, sondern synchron gesendet.
mlab_e_reset( array( 'delivery_mode' => 'queue' ) );
mlab_e_reply( '#api\.resend\.com/emails#', $ok_reply );
add_filter( 'mlab_smtp_queue_max_bytes', static fn(): int => 3000 );
$file = wp_tempnam( 'engine' );
file_put_contents( $file, str_repeat( 'x', 9000 ) );
assert_same( true, wp_mail( 'user@example.test', 'Betreff', 'Text', array(), array( $file ) ), 'size cap: wp_mail is true' );
assert_same( 1, count( mlab_e_resend_requests() ), 'size cap: the message was sent synchronously' );
$row = mlab_e_last_row();
assert_same( 'sync', $row['mode'], 'size cap: the row is a sync row' );
assert_same( 'accepted', $row['status'], 'size cap: accepted' );
remove_all_filters( 'mlab_smtp_queue_max_bytes' );
unlink( $file );

// 10. Ohne Tabellen: wp_mail() sendet trotzdem (synchron, ohne Protokoll).
mlab_e_reset();
mlab_e_reply( '#api\.resend\.com/emails#', $ok_reply );
update_option( 'mlab_smtp_db_version', '0' );
$before = count( mlab_e_resend_requests() );
// db_ready() prüft nur die Option: Zeilen werden dann nicht geschrieben.
assert_same( true, wp_mail( 'user@example.test', 'Betreff', 'Text' ), 'no tables: wp_mail still sends' );
assert_same( $before + 1, count( mlab_e_resend_requests() ), 'no tables: the request was made' );
mlab_smtp_db_install();

// 11. Route-Kandidaten-Filter und Erweiterungspunkt.
mlab_e_reset();
mlab_e_with_fallback();
mlab_e_reply( '#api\.resend\.com/emails#', $ok_reply );
add_filter( 'mlab_smtp_route_candidates', static fn( $routes ) => array_reverse( $routes ) );
wp_mail( 'user@example.test', 'Betreff', 'Text' );
assert_same( 'Bearer ' . ENG_FB_KEY, mlab_e_header( mlab_e_resend_requests()[0], 'Authorization' ), 'route_candidates filter can put the fallback first' );
remove_all_filters( 'mlab_smtp_route_candidates' );

t_finish();
