<?php
// Resend-Webhooks gegen echtes WordPress: Signatur, Idempotenz, Zuordnung, Rang, getrennte Secrets.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/store-helpers.php';

mlab_t_reset_store();
delete_option( MLAB_SMTP_OPTION );
rest_get_server();
do_action( 'rest_api_init' );

$main_secret = 'whsec_' . base64_encode( str_repeat( 'a', 24 ) );
$fb_secret   = 'whsec_' . base64_encode( str_repeat( 'b', 24 ) );
assert_true( mlab_smtp_store( array( 'provider' => 'resend', 'resend_webhook_secret' => $main_secret, 'fb_resend_webhook_secret' => $fb_secret ) ), 'secrets stored' );

function wh_sig( string $secret, string $id, int $ts, string $body ): string {
	$key = base64_decode( substr( $secret, 6 ), true );
	return 'v1,' . base64_encode( hash_hmac( 'sha256', "{$id}.{$ts}.{$body}", $key, true ) );
}
function wh_post( string $route, string $body, array $headers = array(), string $method = 'POST' ): WP_REST_Response {
	$req = new WP_REST_Request( $method, '/mlab-smtp/v1/webhooks/' . $route );
	$req->set_body( $body );
	foreach ( $headers as $k => $v ) {
		$req->set_header( $k, $v );
	}
	return rest_do_request( $req );
}
function wh_signed( string $route, string $secret, string $id, array $payload, ?int $ts = null ): WP_REST_Response {
	$body = wp_json_encode( $payload );
	$ts   = $ts ?? time();
	return wh_post( $route, $body, array( 'svix-id' => $id, 'svix-timestamp' => (string) $ts, 'svix-signature' => wh_sig( $secret, $id, $ts, $body ) ) );
}
function wh_event( string $type, string $email_id, string $uuid = '', array $extra = array() ): array {
	$data = array( 'email_id' => $email_id, 'tags' => '' === $uuid ? new stdClass() : array( 'mlab_id' => str_replace( '-', '', $uuid ) ) );
	return array( 'type' => $type, 'created_at' => gmdate( 'c' ), 'data' => array_merge( $data, $extra ) );
}
function wh_status( int $id ): string {
	return (string) mlab_smtp_store_get( $id )['status'];
}
function wh_events( string $provider = 'resend' ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'events' ) . ' WHERE provider = %s', $provider ) );
}

// ---- Known-answer vector from the Svix/Resend documentation.
$kat_secret = base64_decode( 'MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', true );
assert_true( mlab_smtp_webhook_verify( $kat_secret, 'msg_p5jXN8AQM9LWM0D4loKWxJek', '1614265330', 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=', '{"test": 2432232314}', 1614265330 ), 'documented vector verifies' );
assert_true( ! mlab_smtp_webhook_verify( $kat_secret, 'msg_p5jXN8AQM9LWM0D4loKWxJek', '1614265330', 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=', '{"test": 2432232315}', 1614265330 ), 'documented vector with another body fails' );

// ---- Routes and secret storage.
$routes = rest_get_server()->get_routes();
assert_true( isset( $routes['/mlab-smtp/v1/webhooks/resend'], $routes['/mlab-smtp/v1/webhooks/resend-fallback'] ), 'both routes registered' );
assert_true( str_contains( mlab_smtp_webhook_url( 'resend' ), 'mlab-smtp/v1/webhooks/resend' ), 'url built from rest_url' );
$stored = (string) get_option( MLAB_SMTP_OPTION )['resend_webhook_secret'];
assert_true( '' !== $stored && ! str_contains( $stored, 'whsec_' ) && str_starts_with( $stored, 'enc' ), 'secret is stored encrypted' );
assert_same( $main_secret, mlab_smtp_get( 'resend_webhook_secret' ), 'secret decrypts' );
assert_true( is_wp_error( mlab_smtp_sanitize_webhook_secret( 'kein secret' ) ), 'wrong secret format rejected' );

$uuid = wp_generate_uuid4();
$id   = mlab_t_msg( array( 'uuid' => $uuid, 'status' => 'accepted' ) );
mlab_smtp_store_set_status( $id, 'accepted', array( 'provider_msg_id' => 'em_1' ) );

// ---- Valid, then replay.
$r = wh_signed( 'resend', $main_secret, 'msg_1', wh_event( 'email.delivered', 'em_1', $uuid ) );
assert_same( 200, $r->get_status(), 'valid signature accepted' );
assert_same( 'delivered', wh_status( $id ), 'delivered sets delivered' );
$r = wh_signed( 'resend', $main_secret, 'msg_1', wh_event( 'email.delivered', 'em_1', $uuid ) );
assert_same( 200, $r->get_status(), 'replay answered with 200' );
assert_same( 1, wh_events(), 'replay stored once' );

// ---- Out of order: late sent does not downgrade; delayed only recorded.
$r = wh_signed( 'resend', $main_secret, 'msg_2', wh_event( 'email.sent', 'em_1', $uuid ) );
assert_same( 200, $r->get_status(), 'late sent accepted' );
assert_same( 'delivered', wh_status( $id ), 'late sent does not downgrade' );
wh_signed( 'resend', $main_secret, 'msg_3', wh_event( 'email.delivery_delayed', 'em_1', $uuid ) );
assert_same( 'delivered', wh_status( $id ), 'delayed does not downgrade' );
$id2 = mlab_t_msg( array( 'status' => 'accepted' ) );
mlab_smtp_store_set_status( $id2, 'accepted', array( 'provider_msg_id' => 'em_2' ) );
wh_signed( 'resend', $main_secret, 'msg_4', wh_event( 'email.delivery_delayed', 'em_2' ) );
assert_same( 'accepted', wh_status( $id2 ), 'delayed keeps accepted' );
assert_same( 1, wh_events() - 3, 'delay is recorded as event' );

// ---- Mapping by email_id when no tag; bounce is terminal and carries redacted detail.
wh_signed( 'resend', $main_secret, 'msg_5', wh_event( 'email.bounced', 'em_2', '', array( 'bounce' => array( 'type' => 'Permanent', 'subType' => 'General', 'message' => 'mailbox anna@example.ch unavailable' ) ) ) );
assert_same( 'bounced', wh_status( $id2 ), 'bounce matched by email_id' );
global $wpdb;
$detail = (string) $wpdb->get_var( 'SELECT detail FROM ' . mlab_smtp_table( 'events' ) . " WHERE event_id = 'msg_5'" );
assert_true( ! str_contains( $detail, 'anna@example.ch' ) && str_contains( $detail, 'Permanent' ), 'bounce detail short and masked' );

// ---- Unclear is resolved.
foreach ( array( array( 'email.delivered', 'delivered' ), array( 'email.sent', 'accepted' ), array( 'email.complained', 'complained' ), array( 'email.failed', 'failed' ) ) as $i => $case ) {
	$m = mlab_t_msg( array( 'status' => 'unclear' ) );
	mlab_smtp_store_set_status( $m, 'unclear', array( 'provider_msg_id' => 'em_u' . $i ) );
	wh_signed( 'resend', $main_secret, 'msg_u' . $i, wh_event( $case[0], 'em_u' . $i ) );
	assert_same( $case[1], wh_status( $m ), "unclear resolved by {$case[0]}" );
}

// ---- Rejections.
$body = wp_json_encode( wh_event( 'email.delivered', 'em_1', $uuid ) );
$ts   = time();
$good = array( 'svix-id' => 'msg_x', 'svix-timestamp' => (string) $ts, 'svix-signature' => wh_sig( $main_secret, 'msg_x', $ts, $body ) );
$cases = array(
	'tampered body'     => wh_post( 'resend', $body . ' ', $good ),
	'wrong secret'      => wh_post( 'resend', $body, array_merge( $good, array( 'svix-signature' => wh_sig( $fb_secret, 'msg_x', $ts, $body ) ) ) ),
	'timestamp too old' => wh_post( 'resend', $body, array_merge( $good, array( 'svix-timestamp' => (string) ( $ts - 400 ), 'svix-signature' => wh_sig( $main_secret, 'msg_x', $ts - 400, $body ) ) ) ),
	'timestamp too new' => wh_post( 'resend', $body, array_merge( $good, array( 'svix-timestamp' => (string) ( $ts + 400 ), 'svix-signature' => wh_sig( $main_secret, 'msg_x', $ts + 400, $body ) ) ) ),
	'no headers'        => wh_post( 'resend', $body ),
	'no signature'      => wh_post( 'resend', $body, array( 'svix-id' => 'msg_x', 'svix-timestamp' => (string) $ts ) ),
	'no id'             => wh_post( 'resend', $body, array( 'svix-timestamp' => (string) $ts, 'svix-signature' => $good['svix-signature'] ) ),
);
$before = wh_events();
foreach ( $cases as $label => $resp ) {
	assert_same( 401, $resp->get_status(), "rejected: {$label}" );
	assert_true( ! str_contains( wp_json_encode( $resp->get_data() ), 'whsec' ), "no secret in response: {$label}" );
}
assert_same( $before, wh_events(), 'rejected requests store nothing' );
assert_same( 200, wh_post( 'resend', $body, array_merge( $good, array( 'svix-signature' => 'v1,AAAA= ' . $good['svix-signature'] . ' v2,zzzz' ) ) )->get_status(), 'one valid of several signatures is enough' );

// ---- Primary and backup secrets are not interchangeable.
assert_same( 401, wh_signed( 'resend-fallback', $main_secret, 'msg_f1', wh_event( 'email.delivered', 'em_1' ) )->get_status(), 'main secret not valid on the backup route' );
assert_same( 200, wh_signed( 'resend-fallback', $fb_secret, 'msg_f2', wh_event( 'email.sent', 'em_zz' ) )->get_status(), 'backup secret valid on the backup route' );
assert_same( 1, wh_events( 'resend-fallback' ), 'backup events are kept apart' );
assert_same( 200, wh_signed( 'resend-fallback', $fb_secret, 'msg_1', wh_event( 'email.sent', 'em_zz' ) )->get_status(), 'same svix-id on the other route is separate' );

// ---- Method, size, unknown type, not configured.
assert_true( 200 !== wh_post( 'resend', $body, $good, 'GET' )->get_status(), 'GET rejected' );
assert_same( 413, wh_post( 'resend', str_repeat( 'x', MLAB_SMTP_WEBHOOK_MAX_BYTES + 1 ), $good )->get_status(), 'oversize body 413' );
$before = wh_events();
assert_same( 200, wh_signed( 'resend', $main_secret, 'msg_open', wh_event( 'email.opened', 'em_1', $uuid ) )->get_status(), 'unknown type answered with 200' );
assert_same( $before, wh_events(), 'unknown type not stored' );
mlab_smtp_store( array( 'fb_resend_webhook_secret' => '' ) );
$nc = wh_signed( 'resend-fallback', $fb_secret, 'msg_nc', wh_event( 'email.sent', 'em_1' ) );
assert_same( 404, $nc->get_status(), 'no secret configured gives 404' );
assert_true( ! str_contains( wp_json_encode( $nc->get_data() ), 'whsec' ), 'not configured leaks nothing' );

// ---- Failed processing keeps the event stored but unprocessed; the redelivery is processed, not swallowed.
$boom = static function () {
	throw new RuntimeException( 'boom' );
};
add_action( 'mlab_smtp_webhook_event', $boom );
$m = mlab_t_msg( array( 'status' => 'accepted' ) );
mlab_smtp_store_set_status( $m, 'accepted', array( 'provider_msg_id' => 'em_r' ) );
assert_same( 500, wh_signed( 'resend', $main_secret, 'msg_r', wh_event( 'email.delivered', 'em_r' ) )->get_status(), 'failing processing answers 500' );
assert_same( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'events' ) . " WHERE event_id = 'msg_r' AND processed_at IS NULL" ), 'event stored but not processed' );
remove_action( 'mlab_smtp_webhook_event', $boom );
assert_same( 200, wh_signed( 'resend', $main_secret, 'msg_r', wh_event( 'email.delivered', 'em_r' ) )->get_status(), 'redelivery succeeds' );
assert_same( 'delivered', wh_status( $m ), 'redelivery applied' );
assert_same( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'events' ) . " WHERE event_id = 'msg_r' AND processed_at IS NOT NULL" ), 'event marked processed after the redelivery' );
assert_same( 'duplicate', wh_signed( 'resend', $main_secret, 'msg_r', wh_event( 'email.delivered', 'em_r' ) )->get_data()['status'], 'a processed event is a real duplicate' );

// ---- The status update fails (database error): stored, but 500 and not marked processed; the redelivery repairs it.
$m2 = mlab_t_msg( array( 'status' => 'accepted' ) );
mlab_smtp_store_set_status( $m2, 'accepted', array( 'provider_msg_id' => 'em_db' ) );
$break = static fn( $sql ) => str_starts_with( $sql, 'UPDATE `' . mlab_smtp_table( 'messages' ) . '`' ) ? 'UPDATE mlab_smtp_missing_table SET x = 1' : $sql;
add_filter( 'query', $break );
$r = wh_signed( 'resend', $main_secret, 'msg_db', wh_event( 'email.delivered', 'em_db' ) );
remove_filter( 'query', $break );
assert_same( 500, $r->get_status(), 'database error while applying the status answers 500' );
assert_same( 'accepted', wh_status( $m2 ), 'status unchanged after the failure' );
assert_same( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'events' ) . " WHERE event_id = 'msg_db' AND processed_at IS NULL" ), 'event kept as not processed' );
$r = wh_signed( 'resend', $main_secret, 'msg_db', wh_event( 'email.delivered', 'em_db' ) );
assert_same( 200, $r->get_status(), 'redelivery of the same event id is accepted' );
assert_true( 'duplicate' !== ( $r->get_data()['status'] ?? '' ), 'redelivery is not discarded as duplicate' );
assert_same( 'delivered', wh_status( $m2 ), 'redelivery applies the status' );
assert_same( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'events' ) . " WHERE event_id = 'msg_db'" ), 'still a single row (dedup intact)' );
assert_same( 'duplicate', wh_signed( 'resend', $main_secret, 'msg_db', wh_event( 'email.delivered', 'em_db' ) )->get_data()['status'], 'now it is a duplicate' );

// ---- Already higher status: expected «unchanged» is no error and counts as processed.
$m3 = mlab_t_msg( array( 'status' => 'delivered' ) );
mlab_smtp_store_set_status( $m3, 'delivered', array( 'provider_msg_id' => 'em_hi' ) );
assert_same( 200, wh_signed( 'resend', $main_secret, 'msg_hi', wh_event( 'email.sent', 'em_hi' ) )->get_status(), 'unchanged status is answered with 200' );
assert_same( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'events' ) . " WHERE event_id = 'msg_hi' AND processed_at IS NOT NULL" ), 'unchanged counts as processed' );

// ---- Event during a running send: stored, applied after the completion, then processed.
$m4 = mlab_t_msg( array( 'status' => 'processing' ) );
$wpdb->update( mlab_smtp_table( 'messages' ), array( 'provider_msg_id' => 'em_run' ), array( 'id' => $m4 ) );
assert_same( 200, wh_signed( 'resend', $main_secret, 'msg_run', wh_event( 'email.delivered', 'em_run' ) )->get_status(), 'event during processing answers 200' );
assert_same( 'processing', wh_status( $m4 ), 'event does not change a processing row' );
assert_same( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'events' ) . " WHERE event_id = 'msg_run' AND processed_at IS NULL" ), 'event waits unprocessed' );
mlab_smtp_store_apply_pending_events( $m4 );
assert_same( 'processing', wh_status( $m4 ), 'still processing: nothing applied, not marked' );
$wpdb->update( mlab_smtp_table( 'messages' ), array( 'status' => 'accepted' ), array( 'id' => $m4 ) );
mlab_smtp_store_apply_pending_events( $m4 );
assert_same( 'delivered', wh_status( $m4 ), 'applied after the completion' );
assert_same( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'events' ) . " WHERE event_id = 'msg_run' AND processed_at IS NOT NULL" ), 'processed after the completion' );

// ---- Event before the provider id, rematch, orphan purge.
$late = mlab_t_msg( array( 'status' => 'accepted', 'uuid' => $u2 = wp_generate_uuid4() ) );
wh_signed( 'resend', $main_secret, 'msg_o1', wh_event( 'email.delivered', 'em_late' ) );
assert_same( 'accepted', wh_status( $late ), 'event before the provider id changes nothing yet' );
assert_same( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'events' ) . " WHERE event_id = 'msg_o1' AND message_id IS NULL" ), 'orphan kept with NULL message' );
mlab_smtp_store_set_status( $late, 'accepted', array( 'provider_msg_id' => 'em_late' ) );
assert_same( 0, mlab_smtp_webhook_rematch(), 'the background run leaves an event alone that is not due yet' );
mlab_smtp_webhook_rematch_message( $late );
assert_same( 'delivered', wh_status( $late ), 'the targeted rematch applies the status at once' );
assert_same( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'events' ) . " WHERE event_id = 'msg_o1' AND message_id IS NOT NULL AND processed_at IS NOT NULL" ), 'the event is matched and processed' );
$wpdb->insert( mlab_smtp_table( 'events' ), array( 'provider' => 'resend', 'event_id' => 'old', 'type' => 'email.sent', 'occurred_at' => 1, 'received_at' => time() - 8 * DAY_IN_SECONDS, 'detail' => '{}' ) );
mlab_smtp_webhook_rematch();
assert_same( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'events' ) . " WHERE event_id = 'old'" ), 'orphans older than 7 days deleted' );
assert_true( has_action( 'mlab_smtp_daily', 'mlab_smtp_webhook_rematch' ) !== false, 'daily job rematches' );

// ---- Settings view never prints stored secrets; state line.
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
mlab_smtp_store( array( 'fb_resend_webhook_secret' => $fb_secret ) );
ob_start();
mlab_smtp_render_set_webhooks();
$html = (string) ob_get_clean();
assert_true( ! str_contains( $html, 'whsec_' . base64_encode( str_repeat( 'a', 24 ) ) ) && ! str_contains( $html, base64_encode( str_repeat( 'b', 24 ) ) ), 'settings never print stored secrets' );
assert_contains( 'webhooks/resend-fallback', $html, 'settings show the backup URL' );
assert_contains( 'Letztes Ereignis vor', $html, 'state line shows last event' );

t_finish();
