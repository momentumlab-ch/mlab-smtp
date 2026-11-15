<?php
// Engine, Worker: Übernahme, Wiederholung, Retry-After, unklare Ergebnisse, Richtlinie, manuelle Aktionen, Cron, Zusammenfassung.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/engine-helpers.php';
global $wpdb;

class Eng_Redirect extends RuntimeException {}
class Eng_Die extends RuntimeException {}
add_filter( 'wp_redirect', static function ( $l ) { throw new Eng_Redirect( (string) $l ); } );
add_filter( 'wp_die_handler', static fn() => static function ( $m, $t = '', $a = array() ) { throw new Eng_Die( (string) ( is_array( $a ) ? ( $a['response'] ?? 0 ) : 0 ) ); } );

function eng_due( int $id ): void {
	global $wpdb;
	$wpdb->update( mlab_smtp_table( 'messages' ), array( 'next_attempt_at' => time() - 1 ), array( 'id' => $id ) );
}
function eng_ok(): array { return array( 'code' => 200, 'body' => array( 'id' => 'em_w' ) ); }
function eng_fail( int $code = 503, array $headers = array() ): array { return array( 'code' => $code, 'body' => array( 'name' => 'application_error', 'message' => 'down' ), 'headers' => $headers ); }
function eng_reset_resend( callable $cb, array $settings = array() ): void {
	mlab_e_reset( $settings );
	mlab_e_reply( '#api\.resend\.com/emails#', $cb );
}

$events = array();
add_action( 'mlab_smtp_message_accepted', static function ( $e ) use ( &$events ) { $events[] = array( 'accepted', $e ); } );
add_action( 'mlab_smtp_message_failed', static function ( $e ) use ( &$events ) { $events[] = array( 'failed', $e ); } );
$core = array();
add_action( 'wp_mail_succeeded', static function ( $d ) use ( &$core ) { $core[] = $d; } );

// 1. Der Worker sendet eine eingereihte Nachricht mit Anhang und Bcc; Nutzlast wird nach der Annahme gelöscht.
eng_reset_resend( 'eng_ok', array( 'delivery_mode' => 'queue' ) );
$file = wp_tempnam( 'engine' );
file_put_contents( $file, 'ANHANG' );
wp_mail( 'user@example.test', 'Betreff', 'Text', array( 'Bcc: bcc@example.test' ), array( $file ) );
$row = mlab_e_last_row();
assert_same( 0, count( mlab_http_requests() ), 'worker: nothing sent before the worker runs' );
$core = array();
$done = mlab_smtp_process_queue();
assert_same( array( 'accepted' => 1 ), $done, 'worker: one message accepted' );
$row  = mlab_smtp_store_get( (int) $row['id'] );
$body = json_decode( mlab_e_resend_requests()[0]['args']['body'], true );
assert_same( 'accepted', $row['status'], 'worker: row accepted' );
assert_same( 0, (int) $row['has_payload'], 'worker: payload deleted right after the acceptance' );
assert_same( 'em_w', $row['provider_msg_id'], 'worker: provider message id stored' );
assert_same( array( 'bcc@example.test' ), $body['bcc'], 'worker: Bcc reaches the provider' );
assert_same( base64_encode( 'ANHANG' ), $body['attachments'][0]['content'], 'worker: attachment reaches the provider' );
assert_same( 1, count( $core ), 'worker: wp_mail_succeeded fires once, in the worker' );
assert_same( 'worker', $core[0]['mlab_context'], 'worker: the core event carries the worker context' );
assert_true( mlab_smtp_state_get()['last_success'] > 0, 'worker: delivery counted once by the plugin' );
assert_same( 1, count( mlab_smtp_log_get() ), 'worker: still one log entry (our hooks ignore the worker event)' );
assert_same( 'accepted', end( $events )[0], 'worker: mlab_smtp_message_accepted fired' );
assert_same( array(), mlab_smtp_process_queue(), 'worker: nothing left to do' );
unlink( $file );

// 2. Gleicher Schlüssel und gleiche Message-ID bei jedem Versuch, identischer Inhalt.
eng_reset_resend(
	static fn( $u, $a, $n ) => $n < 3 ? eng_fail() : eng_ok(),
	array( 'max_attempts' => 6 )
);
$id = mlab_e_queue_message();
mlab_smtp_process_queue();
assert_same( 'temp_failed', mlab_smtp_store_get( $id )['status'], 'retry: first failure waits for a retry' );
assert_same( array(), mlab_smtp_process_queue(), 'retry: not due yet, nothing runs' );
eng_due( $id );
mlab_smtp_process_queue();
eng_due( $id );
mlab_smtp_process_queue();
$reqs = mlab_e_resend_requests();
assert_same( 3, count( $reqs ), 'retry: three requests' );
assert_same( 'accepted', mlab_smtp_store_get( $id )['status'], 'retry: accepted on the third try' );
assert_same( 1, count( array_unique( array_map( static fn( $r ) => mlab_e_header( $r, 'Idempotency-Key' ), $reqs ) ) ), 'retry: all requests carry the identical idempotency key' );
assert_same( 1, count( array_unique( array_map( static fn( $r ) => $r['args']['body'], $reqs ) ) ), 'retry: the payload is identical between retries' );
$atts = mlab_smtp_store_attempts( $id );
assert_same( 1, count( array_unique( array_column( $atts, 'mail_message_id' ) ) ), 'retry: the Message-ID is the same on every attempt' );
assert_same( 3, mlab_smtp_engine_rounds( $atts ), 'retry: three rounds counted' );

// 2b. Message-ID im MIME-Text (M365) bleibt gleich.
mlab_e_reset( array( 'provider' => 'm365', 'm365_tenant' => 't', 'm365_client_id' => 'c', 'm365_client_secret' => 'secret-1234567890' ) );
mlab_e_reply( '#login\.microsoftonline#', static fn() => array( 'code' => 200, 'body' => array( 'access_token' => 'T', 'expires_in' => 3600 ) ) );
mlab_e_reply( '#graph\.microsoft\.com#', static fn( $u, $a, $n ) => $n < 2 ? array( 'code' => 503, 'body' => array() ) : array( 'code' => 202, 'body' => '' ) );
$id   = mlab_e_queue_message( array( 'provider' => 'm365' ) );
$want = mlab_smtp_store_get_payload( $id )['message_id'];
mlab_smtp_process_queue();
eng_due( $id );
mlab_smtp_process_queue();
$ids = array();
foreach ( mlab_http_requests() as $r ) {
	if ( str_contains( $r['url'], 'graph.microsoft.com' ) ) {
		preg_match( '/^Message-ID: (\S+)/mi', (string) base64_decode( $r['args']['body'] ), $m );
		$ids[] = $m[1] ?? '';
	}
}
assert_same( array( $want, $want ), $ids, 'retry: the same Message-ID header goes out on every try (M365)' );
assert_same( 'accepted', mlab_smtp_store_get( $id )['status'], 'M365 worker: accepted' );

// 3. Zwei Worker nacheinander: nur einer verarbeitet die Zeile.
eng_reset_resend( 'eng_ok' );
$id = mlab_e_queue_message();
$a  = mlab_smtp_store_claim_batch( 5, 300 );
assert_same( 1, count( $a ), 'claim: worker A takes the row' );
assert_same( array(), mlab_smtp_process_queue(), 'claim: worker B finds nothing to take' );
assert_same( 0, count( mlab_http_requests() ), 'claim: only one worker sends' );
assert_same( 'processing', mlab_smtp_store_get( $id )['status'], 'claim: the row stays with worker A' );
assert_true( ! mlab_smtp_store_complete( $id, 'wrong-token', 'accepted' ), 'claim: only the lock token completes' );

// 4. Tod des Workers vor und nach request_sent_at.
eng_reset_resend( static function () { throw new RuntimeException( 'worker died' ); } );
$after = mlab_e_queue_message();
mlab_smtp_process_queue();
assert_same( 'processing', mlab_smtp_store_get( $after )['status'], 'death after the request left: the row is still processing' );
mlab_smtp_store_sweep( time() + 400 );
assert_same( 'unclear', mlab_smtp_store_get( $after )['status'], 'death after request_sent_at: unclear' );
mlab_e_reset();
add_filter( 'mlab_smtp_queue_before_send', static function () { throw new RuntimeException( 'died early' ); } );
$before = mlab_e_queue_message();
mlab_smtp_process_queue();
remove_all_filters( 'mlab_smtp_queue_before_send' );
mlab_smtp_store_sweep( time() + 400 );
assert_same( 'queued', mlab_smtp_store_get( $before )['status'], 'death before any request: back in the queue' );

// 5. Unklar wird nicht automatisch wiederholt (M365, Google); Resend nur im 23-h-Fenster.
mlab_e_reset( array( 'provider' => 'm365', 'm365_tenant' => 't', 'm365_client_id' => 'c', 'm365_client_secret' => 'secret-1234567890' ) );
mlab_e_reply( '#login\.microsoftonline#', static fn() => array( 'code' => 200, 'body' => array( 'access_token' => 'T', 'expires_in' => 3600 ) ) );
mlab_e_reply( '#graph\.microsoft\.com#', static fn() => mlab_e_timeout() );
$id = mlab_e_queue_message( array( 'provider' => 'm365' ) );
mlab_smtp_process_queue();
assert_same( 'unclear', mlab_smtp_store_get( $id )['status'], 'M365: a timeout after sending is unclear' );
assert_same( 1, (int) mlab_smtp_store_get( $id )['has_payload'], 'M365: the payload stays for a manual decision' );
assert_true( (int) $wpdb->get_var( 'SELECT payload_expires_at FROM ' . mlab_smtp_table( 'messages' ) . ' WHERE id = ' . $id ) > 0, 'M365: unclear payload has an expiry' );
eng_due( $id );
mlab_smtp_process_queue();
assert_same( 1, count( array_filter( mlab_http_requests(), static fn( $r ) => str_contains( $r['url'], 'graph.microsoft' ) ) ), 'M365: no automatic second send' );
mlab_e_reset( array( 'provider' => 'google', 'google_client_id' => 'c', 'google_client_secret' => 'secret-1234567890', 'google_refresh_token' => 'tok-1234567890', 'google_account' => 'a@example.test' ) );
mlab_e_reply( '#oauth2\.googleapis\.com#', static fn() => array( 'code' => 200, 'body' => array( 'access_token' => 'T', 'expires_in' => 3600 ) ) );
mlab_e_reply( '#gmail\.googleapis\.com#', static fn() => mlab_e_timeout() );
$id = mlab_e_queue_message( array( 'provider' => 'google' ) );
mlab_smtp_process_queue();
assert_same( 'unclear', mlab_smtp_store_get( $id )['status'], 'Google: a timeout after sending is unclear' );
$unclear = array( array( 'class' => 'unclear', 'provider' => 'smtp', 'route' => 'primary', 'error' => 'x', 'retry_after' => 0, 'info' => array() ) );
assert_same( false, mlab_smtp_engine_outcome( $unclear )['retryable'], 'SMTP: unclear is never retried automatically' );
$unclear[0]['provider'] = 'resend';
assert_same( true, mlab_smtp_engine_outcome( $unclear )['retryable'], 'Resend: unclear may be retried (idempotency key)' );

eng_reset_resend( static fn( $u, $a, $n ) => 1 === $n ? mlab_e_timeout() : eng_ok() );
$id = mlab_e_queue_message();
mlab_smtp_process_queue();
assert_same( 'temp_failed', mlab_smtp_store_get( $id )['status'], 'Resend: unclear within 23 h is retried' );
eng_due( $id );
mlab_smtp_process_queue();
$reqs = mlab_e_resend_requests();
assert_same( 'accepted', mlab_smtp_store_get( $id )['status'], 'Resend: the retry is accepted' );
assert_same( mlab_e_header( $reqs[0], 'Idempotency-Key' ), mlab_e_header( $reqs[1], 'Idempotency-Key' ), 'Resend: the retry of an unclear message reuses the key' );
// Nach 23 Stunden nicht mehr automatisch.
eng_reset_resend( static fn() => mlab_e_timeout() );
$id = mlab_e_queue_message();
mlab_smtp_process_queue();
$wpdb->update( mlab_smtp_table( 'attempts' ), array( 'started_at' => time() - 24 * HOUR_IN_SECONDS ), array( 'message_id' => $id ) );
eng_due( $id );
mlab_smtp_process_queue();
assert_same( 'unclear', mlab_smtp_store_get( $id )['status'], 'Resend: unclear later than 23 h after the first attempt is not retried' );
// Grosses Retry-After zählt nicht gegen das Fenster.
eng_reset_resend( static fn( $u, $a, $n ) => 1 === $n ? mlab_e_timeout() : eng_fail( 429, array( 'retry-after' => '999999' ) ) );
$id = mlab_e_queue_message();
mlab_smtp_process_queue();
$wpdb->update( mlab_smtp_table( 'attempts' ), array( 'started_at' => time() - 2 * HOUR_IN_SECONDS ), array( 'message_id' => $id ) );
eng_due( $id );
mlab_smtp_process_queue();
assert_same( 'unclear', mlab_smtp_store_get( $id )['status'], 'a large Retry-After does not push an unclear message beyond 23 h' );

// 6. Retry-After gewinnt, pausiert den Anbieter.
eng_reset_resend( static fn() => eng_fail( 429, array( 'Retry-After' => '120' ) ) );
$one = mlab_e_queue_message();
$two = mlab_e_queue_message();
$res = mlab_smtp_process_queue();
assert_same( 1, count( mlab_e_resend_requests() ), 'Retry-After: the second message is not sent while the provider is paused' );
$r1 = mlab_smtp_store_get( $one );
assert_true( abs( (int) $r1['next_attempt_at'] - ( time() + 120 ) ) <= 3, 'Retry-After wins over the backoff' );
assert_true( isset( mlab_smtp_engine_pauses()['primary:resend'] ), 'Retry-After: the route is paused (key route:provider)' );
assert_same( 'queued', mlab_smtp_store_get( $two )['status'], 'Retry-After: the paused message goes back untouched' );
assert_same( 0, (int) mlab_smtp_store_get( $two )['attempts'], 'Retry-After: no attempt was made for it' );
assert_same( array(), mlab_smtp_process_queue(), 'Retry-After: nothing runs during the pause' );
$id = mlab_e_queue_message();
eng_reset_resend( static fn() => eng_fail( 503, array( 'Retry-After' => '99999999' ) ) );
$id = mlab_e_queue_message();
mlab_smtp_process_queue();
assert_true( (int) mlab_smtp_store_get( $id )['next_attempt_at'] <= time() + DAY_IN_SECONDS + 3, 'Retry-After is capped at 24 h' );

// 7. Richtlinie nach unklar: Queue-Standard kein Wechsel, Einstellung erlaubt ihn.
eng_reset_resend( static fn( $u, $a, $n ) => str_contains( $a['headers']['Authorization'], 'main' ) ? mlab_e_timeout() : eng_ok(), array() );
mlab_e_with_fallback();
$id = mlab_e_queue_message( array( 'provider' => 'resend' ) );
mlab_smtp_process_queue();
assert_same( 1, count( mlab_e_resend_requests() ), 'queue policy: no fallback after unclear by default' );
assert_same( 'temp_failed', mlab_smtp_store_get( $id )['status'], 'queue policy: Resend unclear stays retryable with the same key' );
eng_reset_resend( static fn( $u, $a, $n ) => str_contains( $a['headers']['Authorization'], 'main' ) ? mlab_e_timeout() : eng_ok(), array( 'queue_unclear_failover' => 1 ) );
mlab_e_with_fallback();
$id = mlab_e_queue_message();
mlab_smtp_process_queue();
$row = mlab_smtp_store_get( $id );
assert_same( 2, count( mlab_e_resend_requests() ), 'queue policy: with the setting the fallback is tried' );
assert_same( 'accepted', $row['status'], 'queue policy: accepted through the fallback' );
assert_contains( 'fallback_after_unclear', $row['last_error'], 'queue policy: noted on the row' );

// 8. Höchstzahl der Versuche: endgültig, Inhalt bleibt bis zur Frist.
eng_reset_resend( static fn() => eng_fail( 503 ) );
$id = mlab_e_queue_message( array( 'max_attempts' => 2 ) );
mlab_smtp_process_queue();
eng_due( $id );
mlab_smtp_process_queue();
$row = mlab_smtp_store_get( $id );
assert_same( 'failed', $row['status'], 'max attempts: the message fails for good' );
assert_same( 1, (int) $row['has_payload'], 'max attempts: the payload stays for a manual retry' );
$exp = (int) $wpdb->get_var( 'SELECT payload_expires_at FROM ' . mlab_smtp_table( 'messages' ) . ' WHERE id = ' . $id );
assert_true( abs( $exp - ( time() + 7 * DAY_IN_SECONDS ) ) <= 5, 'payload retention: 7 days by default' );
mlab_smtp_store( array( 'payload_days' => 14 ) );
assert_same( 14, mlab_smtp_engine_payload_days( 7 ), 'payload retention: the setting is used (14 days)' );
mlab_smtp_store( array( 'payload_days' => 7 ) );
assert_same( 'permanent', mlab_smtp_engine_overall_class( array( array( 'class' => 'permanent' ) ) ), 'permanent class' );

// 9. Manuelle Aktionen.
$retry = $id;
mlab_e_reset();
mlab_e_reply( '#api\.resend\.com/emails#', 'eng_ok' );
assert_true( is_wp_error( mlab_smtp_engine_manual_retry( $retry ) ), 'manual retry: unknown message is refused' );
$failed = mlab_e_queue_message( array( 'status' => 'failed', 'completed_at' => time() ) );
assert_true( true === mlab_smtp_engine_manual_retry( $failed ), 'manual retry: a failed message with payload is queued again' );
assert_same( 'queued', mlab_smtp_store_get( $failed )['status'], 'manual retry: status queued' );
mlab_smtp_process_queue();
assert_same( 'accepted', mlab_smtp_store_get( $failed )['status'], 'manual retry: the worker delivers it' );
assert_true( is_wp_error( mlab_smtp_engine_manual_retry( $failed ) ), 'manual retry: not possible once accepted' );
$nopay = mlab_smtp_store_create( array( 'mode' => 'queued', 'status' => 'failed', 'recipients' => 'user@example.test', 'subject' => 's' ) );
assert_true( is_wp_error( mlab_smtp_engine_manual_retry( $nopay ) ), 'manual retry: refused without payload' );
$unclear = mlab_e_queue_message( array( 'status' => 'unclear', 'completed_at' => time() ) );
assert_true( true === mlab_smtp_engine_mark_delivered( $unclear ), 'mark delivered: unclear message' );
assert_same( 'accepted', mlab_smtp_store_get( $unclear )['status'], 'mark delivered: status accepted' );
assert_same( 0, (int) mlab_smtp_store_get( $unclear )['has_payload'], 'mark delivered: payload deleted' );
assert_true( is_wp_error( mlab_smtp_engine_mark_delivered( $failed ) ), 'mark delivered: only for unclear messages' );
mlab_http_reset();
$unclear = mlab_e_queue_message( array( 'status' => 'unclear', 'completed_at' => time() ) );
mlab_smtp_process_queue();
assert_true( true === mlab_smtp_engine_manual_resend( $unclear ), 'resend: unclear message is queued again' );
mlab_smtp_process_queue();
$old = mlab_smtp_engine_idem_key( mlab_smtp_store_get( $unclear )['uuid'], 'primary' );
assert_true( mlab_e_header( mlab_e_resend_requests()[0], 'Idempotency-Key' ) !== $old, 'resend (duplicate possible): a new idempotency key is used' );
assert_same( 'accepted', mlab_smtp_store_get( $unclear )['status'], 'resend: delivered' );

// 9b. Handler: Berechtigung und Nonce.
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$act    = static function ( string $do, int $id, ?string $nonce_action = 'bound' ): string {
	$_REQUEST = array( 'do' => $do, 'id' => (string) $id );
	if ( $nonce_action ) {
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'bound' === $nonce_action ? mlab_smtp_message_nonce_action( $id, $do ) : $nonce_action );
	}
	try {
		mlab_smtp_handle_message_action();
	} catch ( Eng_Redirect $e ) {
		return 'redirect';
	} catch ( Eng_Die $e ) {
		return 'die:' . $e->getMessage();
	}
	return 'none';
};
$target = mlab_e_queue_message( array( 'status' => 'unclear', 'completed_at' => time() ) );
wp_set_current_user( $admins[0]->ID );
assert_same( 'die:403', $act( 'delivered', $target, null ), 'handler: no nonce is refused' );
assert_same( 'unclear', mlab_smtp_store_get( $target )['status'], 'handler: nothing changed without a nonce' );
$sub = wp_insert_user( array( 'user_login' => 'eng_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $sub );
assert_same( 'die:403', $act( 'delivered', $target ), 'handler: a user without capability is refused' );
wp_set_current_user( $admins[0]->ID );
assert_same( 'redirect', $act( 'delivered', $target ), 'handler: an administrator with a nonce succeeds' );
assert_same( 'accepted', mlab_smtp_store_get( $target )['status'], 'handler: the action ran' );
assert_contains( 'mlab_smtp_message_action', mlab_smtp_engine_action_url( 5, 'retry' ), 'action url points to the handler' );

// 10. Cron.
mlab_smtp_engine_deactivate();
assert_same( false, wp_next_scheduled( MLAB_SMTP_QUEUE_HOOK ), 'cron: removed on deactivation' );
mlab_smtp_engine_schedule();
assert_true( false !== wp_next_scheduled( MLAB_SMTP_QUEUE_HOOK ), 'cron: registered on activation' );
assert_same( 'mlab_smtp_minute', wp_get_schedule( MLAB_SMTP_QUEUE_HOOK ), 'cron: custom one-minute schedule' );
assert_same( 60, wp_get_schedules()['mlab_smtp_minute']['interval'], 'cron: interval 60 s' );
wp_clear_scheduled_hook( MLAB_SMTP_QUEUE_HOOK );
assert_true( false !== has_action( 'init', 'mlab_smtp_engine_schedule' ), 'cron: the self-healing check runs on init' );
mlab_smtp_engine_schedule();
assert_true( false !== wp_next_scheduled( MLAB_SMTP_QUEUE_HOOK ), 'cron: self-heals when the event is missing' );
remove_all_filters( 'mlab_smtp_engine_spawn' );
mlab_smtp_engine_spawn();
assert_true( false !== wp_next_scheduled( MLAB_SMTP_QUEUE_HOOK, array( 'now' ) ), 'spawn: a one-off event is planned right after the enqueue' );
wp_clear_scheduled_hook( MLAB_SMTP_QUEUE_HOOK, array( 'now' ) );
add_filter( 'mlab_smtp_engine_spawn', '__return_false' );

// 11. Fallback-Test-Flag: gilt nur für diese Anfrage.
mlab_e_reset();
assert_same( false, mlab_smtp_fallback_ready(), 'flag: fallback is off' );
mlab_smtp_flag( 'fallback_test', true );
assert_same( true, mlab_smtp_fallback_ready(), 'flag: fallback_test makes the fallback ready' );
mlab_smtp_store( array( 'fb_resend_api_key' => '' ) );
assert_same( false, mlab_smtp_fallback_ready(), 'flag: an incomplete fallback is still not ready' );
mlab_smtp_flag( 'fallback_test', false );
assert_same( false, mlab_smtp_fallback_ready(), 'flag: off again' );
assert_same( false, has_filter( 'option_' . MLAB_SMTP_OPTION ), 'the option filter hack is gone' );

// 12. Verwaltung, Zusammenfassung, Gesundheit.
eng_reset_resend( 'eng_ok' );
$stale = mlab_e_queue_message( array( 'next_attempt_at' => time() - 20 * MINUTE_IN_SECONDS ) );
$sum   = mlab_smtp_engine_summary();
assert_same( 1, $sum['waiting'], 'summary: one waiting message' );
assert_true( $sum['oldest_wait'] >= 20 * MINUTE_IN_SECONDS - 2, 'summary: oldest wait' );
assert_true( $sum['stale'], 'summary: stale after 10 minutes' );
$rows = array_column( mlab_smtp_health_checks(), null, 'title' );
assert_same( 'warn', $rows['Warteschlange staut sich']['level'] ?? '', 'health: a warning for a stuck queue' );
assert_contains( 'System-Cron', $rows['Warteschlange staut sich']['text'] ?? '', 'health: recommends a system cron' );
assert_contains( 'DISABLE_WP_CRON', mlab_smtp_engine_cron_text( array( 'disabled' => true, 'scheduled' => false, 'next' => 0, 'last_run' => 0 ) ), 'health: names DISABLE_WP_CRON' );
mlab_smtp_process_queue();
assert_same( false, isset( array_column( mlab_smtp_health_checks(), null, 'title' )['Warteschlange staut sich'] ), 'health: no queue row once the queue is empty' );
mlab_e_queue_message( array( 'status' => 'unclear' ) );
mlab_e_queue_message( array( 'status' => 'failed' ) );
$health = array_column( mlab_smtp_health_checks(), null, 'title' );
assert_same( 'warn', $health['Versandstatus unklar']['level'] ?? '', 'health: unclear count row' );
assert_same( 'warn', $health['Endgültige Fehler']['level'] ?? '', 'health: final failures row' );
$sum = mlab_smtp_engine_summary();
assert_same( 1, $sum['unclear'], 'summary: unclear count' );
assert_same( 1, $sum['failed'], 'summary: failed count' );

// 13. Unlesbare Nutzlast (Schlüssel geändert) ist ein endgültiger Fehler mit genannter Ursache, nicht unklar.
eng_reset_resend( 'eng_ok' );
$id = mlab_e_queue_message();
$wpdb->update( mlab_smtp_table( 'messages' ), array( 'payload' => 'enc2:kaputt' ), array( 'id' => $id ) );
mlab_smtp_process_queue();
$row = mlab_smtp_store_get( $id );
assert_same( 'failed', $row['status'], 'unreadable payload: failed, not unclear' );
assert_contains( 'nicht mehr lesbar', $row['last_error'], 'unreadable payload: the cause is named' );

// 14. Erweiterungspunkt: Nachricht zurückstellen.
eng_reset_resend( 'eng_ok' );
add_filter( 'mlab_smtp_queue_before_send', '__return_false' );
$id = mlab_e_queue_message();
mlab_smtp_process_queue();
remove_all_filters( 'mlab_smtp_queue_before_send' );
assert_same( 0, count( mlab_http_requests() ), 'queue_before_send: a veto keeps the message back' );
assert_true( (int) mlab_smtp_store_get( $id )['next_attempt_at'] > time() + 200, 'queue_before_send: postponed' );

t_finish();
