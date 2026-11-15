<?php
// Engine: Fehlerklassen (Tabelle), Wiederholungsabstände, Retry-After, Identität, Schnappschuss.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/engine-helpers.php';
mlab_e_reset();

// Klassifizierung: Tabelle.
$table = array(
	array( array( 'http_status' => 408 ), 'temporary', 'HTTP 408' ),
	array( array( 'http_status' => 425 ), 'temporary', 'HTTP 425' ),
	array( array( 'http_status' => 429 ), 'temporary', 'HTTP 429' ),
	array( array( 'http_status' => 500 ), 'temporary', 'HTTP 500' ),
	array( array( 'http_status' => 502 ), 'temporary', 'HTTP 502' ),
	array( array( 'http_status' => 503 ), 'temporary', 'HTTP 503' ),
	array( array( 'http_status' => 504 ), 'temporary', 'HTTP 504' ),
	array( array( 'http_status' => 501 ), 'permanent', 'HTTP 501 is not temporary' ),
	array( array( 'http_status' => 400 ), 'permanent', 'HTTP 400' ),
	array( array( 'http_status' => 401 ), 'permanent', 'HTTP 401' ),
	array( array( 'http_status' => 403 ), 'permanent', 'HTTP 403' ),
	array( array( 'http_status' => 404 ), 'permanent', 'HTTP 404' ),
	array( array( 'http_status' => 413 ), 'permanent', 'HTTP 413' ),
	array( array( 'http_status' => 422 ), 'permanent', 'HTTP 422' ),
	array( array( 'http_status' => 409, 'provider' => 'resend' ), 'unclear', 'Resend 409 (idempotency conflict)' ),
	array( array( 'http_status' => 409, 'provider' => 'm365' ), 'permanent', '409 of another provider' ),
	array( array( 'http_status' => 0, 'request_sent' => false, 'message' => 'cURL error 28: Operation timed out' ), 'temporary', 'nothing sent yet' ),
	array( array( 'http_status' => 0, 'message' => 'cURL error 7: Failed to connect to api.resend.com port 443' ), 'temporary', 'connect failure' ),
	array( array( 'http_status' => 0, 'message' => 'cURL error 28: Connection timed out after 10001 milliseconds' ), 'temporary', 'connect timeout' ),
	array( array( 'http_status' => 0, 'message' => 'cURL error 6: Could not resolve host' ), 'temporary', 'DNS failure' ),
	array( array( 'http_status' => 0, 'message' => 'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received' ), 'unclear', 'timeout after the request was sent' ),
	array( array( 'http_status' => 0, 'message' => 'cURL error 52: Empty reply from server' ), 'unclear', 'abort after sending' ),
	array( array( 'smtp_phase' => 'command', 'smtp_code' => 451 ), 'temporary', 'SMTP 4xx' ),
	array( array( 'smtp_phase' => 'command', 'smtp_code' => 550 ), 'permanent', 'SMTP 5xx' ),
	array( array( 'smtp_phase' => 'connect', 'smtp_code' => 0 ), 'temporary', 'SMTP connect failure' ),
	array( array( 'smtp_phase' => 'data_end', 'smtp_code' => 0 ), 'unclear', 'SMTP timeout after the end of DATA' ),
	array( array( 'smtp_phase' => 'data_end', 'smtp_code' => 554 ), 'permanent', 'SMTP 5xx answer to the end of DATA' ),
	array( array( 'smtp_phase' => 'data_end', 'smtp_code' => 451 ), 'temporary', 'SMTP 4xx answer to the end of DATA' ),
	array( array( 'smtp_phase' => 'command', 'smtp_code' => 550, 'partial' => true ), 'partial', 'rejected recipients' ),
	array( array( 'local_failure' => true ), 'permanent', 'local failure (no credentials)' ),
);
foreach ( $table as $row ) {
	assert_same( $row[1], mlab_smtp_engine_classify( $row[0] ), 'classify: ' . $row[2] );
}

// Backoff mit eingespeister Zufallsquelle.
assert_same( 48, mlab_smtp_engine_backoff( 1, 0.0 ), 'backoff 1: lower bound -20 %' );
assert_same( 60, mlab_smtp_engine_backoff( 1, 0.5 ), 'backoff 1: 60 s at the middle' );
assert_same( 72, mlab_smtp_engine_backoff( 1, 1.0 ), 'backoff 1: upper bound +20 %' );
assert_same( 120, mlab_smtp_engine_backoff( 2, 0.5 ), 'backoff doubles' );
assert_same( 240, mlab_smtp_engine_backoff( 3, 0.5 ), 'backoff doubles again' );
assert_same( 6 * HOUR_IN_SECONDS, mlab_smtp_engine_backoff( 12, 0.5 ), 'backoff is capped at 6 h' );
assert_same( 6 * HOUR_IN_SECONDS, mlab_smtp_engine_backoff( 30, 1.0 ), 'jitter never lifts the delay above the cap' );
assert_same( (int) round( 0.8 * 6 * HOUR_IN_SECONDS ), mlab_smtp_engine_backoff( 12, 0.0 ), 'jitter lowers the capped delay by 20 %' );
mlab_smtp_engine_random( static fn(): float => 1.0 );
assert_same( 72, mlab_smtp_engine_backoff( 1 ), 'the injected random source is used' );
mlab_smtp_engine_random( static fn(): float => 0.5 );
$ok = true;
for ( $i = 1; $i <= 10; $i++ ) {
	$base = min( 6 * HOUR_IN_SECONDS, 60 * ( 2 ** ( $i - 1 ) ) );
	$ok   = $ok && mlab_smtp_engine_backoff( $i, 0.0 ) >= (int) round( 0.8 * $base ) && mlab_smtp_engine_backoff( $i, 1.0 ) <= min( 6 * HOUR_IN_SECONDS, (int) round( 1.2 * $base ) );
}
assert_true( $ok, 'backoff stays within +-20 % of the base for 10 tries' );

// Retry-After.
assert_same( 120, mlab_smtp_parse_retry_after( '120' ), 'Retry-After in seconds' );
assert_same( 90, mlab_smtp_parse_retry_after( gmdate( 'D, d M Y H:i:s', 1800000090 ) . ' GMT', 1800000000 ), 'Retry-After as an HTTP date' );
assert_same( 0, mlab_smtp_parse_retry_after( 'soon' ), 'invalid Retry-After' );
assert_same( 0, mlab_smtp_parse_retry_after( '' ), 'missing Retry-After' );
assert_same( 7200, mlab_smtp_engine_delay( 1, 7200, 0.5 ), 'Retry-After wins over the backoff' );
assert_same( DAY_IN_SECONDS, mlab_smtp_engine_delay( 1, 10 * DAY_IN_SECONDS, 0.5 ), 'Retry-After is capped at 24 h' );
assert_same( 60, mlab_smtp_engine_delay( 1, 0, 0.5 ), 'no Retry-After: backoff' );
$err = mlab_smtp_decode_response( array( 'headers' => array( 'Retry-After' => '30' ), 'body' => '{"name":"rate_limit_exceeded","message":"slow"}', 'response' => array( 'code' => 429 ) ) );
assert_same( 30, $err->get_error_data()['retry_after'], 'decode_response captures Retry-After into the error data' );
assert_same( 429, $err->get_error_data()['status'], 'decode_response keeps the status' );
$tr = mlab_smtp_decode_response( new WP_Error( 'http_request_failed', 'x' ) );
assert_true( ! empty( $tr->get_error_data()['transport'] ), 'a transport error is marked as such' );

// Identität.
assert_same( mlab_smtp_engine_idem_key( 'u1', 'primary' ), mlab_smtp_engine_idem_key( 'u1', 'primary' ), 'idempotency key is stable' );
assert_true( mlab_smtp_engine_idem_key( 'u1', 'primary' ) !== mlab_smtp_engine_idem_key( 'u1', 'fallback' ), 'idempotency key differs per route' );
assert_true( mlab_smtp_engine_idem_key( 'u1', 'primary' ) !== mlab_smtp_engine_idem_key( 'u2', 'primary' ), 'idempotency key differs per message' );
assert_true( mlab_smtp_engine_idem_key( 'u1', 'primary' ) !== mlab_smtp_engine_idem_key( 'u1', 'primary', 1 ), 'a manual resend gets a new key' );
assert_true( strlen( mlab_smtp_engine_idem_key( 'u1', 'primary' ) ) <= 64, 'key fits the column' );

// Schnappschuss mit Anhang und Bcc: Rundlauf.
require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
require_once MLAB_SMTP_DIR . 'inc/class-mlab-smtp-mailer.php';
$m = new Mlab_Smtp_Mailer( true );
$m->setFrom( 'info@example.test', 'Firma' );
$m->addAddress( 'user@example.test', 'Anna' );
$m->addCC( 'cc@example.test' );
$m->addBCC( 'bcc@example.test' );
$m->addReplyTo( 'reply@example.test', 'Antwort' );
$m->Subject = 'Grüsse';
$m->isHTML( true );
$m->Body    = '<p>Hallo</p>';
$m->AltBody = 'Hallo';
$m->MessageID = '<mlab-abc@example.test>';
$m->addCustomHeader( 'X-Test', 'eins' );
$m->addStringAttachment( "PDF\0DATA", 'rechnung.pdf', 'base64', 'application/pdf' );
$m->Mailer = 'sendmail';
$m->preSend();
$snap = mlab_smtp_engine_snapshot( $m );
assert_true( is_array( $snap ), 'snapshot is built' );
assert_same( array( array( 'bcc@example.test', '' ) ), $snap['bcc'], 'snapshot keeps Bcc' );
assert_same( 'rechnung.pdf', $snap['attachments'][0]['name'], 'snapshot keeps the attachment name' );
assert_same( base64_encode( "PDF\0DATA" ), $snap['attachments'][0]['content'], 'attachment content is Base64' );
assert_same( '<mlab-abc@example.test>', $snap['message_id'], 'snapshot stores the Message-ID' );
$json = json_decode( wp_json_encode( $snap ), true );
$b    = mlab_smtp_engine_mailer_from_snapshot( $json );
assert_same( '<mlab-abc@example.test>', $b->getLastMessageID(), 'rebuilt mailer uses the stored Message-ID' );
assert_same( array( 'bcc@example.test' ), array_column( $b->getBccAddresses(), 0 ), 'rebuilt mailer has the Bcc recipient' );
assert_same( array( 'cc@example.test' ), array_column( $b->getCcAddresses(), 0 ), 'rebuilt mailer has the Cc recipient' );
assert_same( 'Grüsse', $b->Subject, 'subject survives' );
assert_same( '<p>Hallo</p>', $b->Body, 'HTML body survives' );
assert_same( 'Hallo', $b->AltBody, 'text body survives' );
$att = $b->getAttachments();
assert_same( "PDF\0DATA", $att[0][0], 'attachment content survives the roundtrip' );
assert_same( 'application/pdf', $att[0][4], 'attachment type survives' );
assert_contains( 'X-Test: eins', $b->getSentMIMEMessage(), 'custom header survives' );
assert_contains( 'Bcc: bcc@example.test', $b->getSentMIMEMessage(), 'API style header keeps Bcc' );
assert_contains( 'Reply-To: Antwort <reply@example.test>', $b->getSentMIMEMessage(), 'reply-to survives' );

// Obergrenze (Grösse der codierten Nachricht).
add_filter( 'mlab_smtp_queue_max_bytes', static fn(): int => 2000 );
$big = clone $m;
$big->clearAttachments();
$big->addStringAttachment( str_repeat( 'x', 5000 ), 'gross.bin' );
assert_true( is_wp_error( mlab_smtp_engine_snapshot( $big ) ), 'a snapshot above the cap is refused' );
assert_true( is_wp_error( mlab_smtp_store_payload_encode( array( 'x' => str_repeat( 'y', 5000 ) ) ) ), 'the store refuses an encoded payload above the cap' );
remove_all_filters( 'mlab_smtp_queue_max_bytes' );

t_finish();
