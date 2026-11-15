<?php
// Fix 2: recipients_failed ist nur «partial», wenn weniger Empfänger abgelehnt wurden als es gab.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/engine-helpers.php';
global $wpdb;

/** Route-Info, wie sie die Mailer-Klasse aus einer PHPMailer-Ausnahme baut (SMTP-Antwort synthetisch). */
function eng_smtp_info( array $rcpts, array $refused, int $code ): array {
	$snap = array( 'v' => 1, 'from' => 'info@example.test', 'from_name' => '', 'sender' => '', 'to' => array_map( static fn( $a ) => array( $a, '' ), $rcpts ), 'subject' => 'S', 'body' => 'B', 'html' => false, 'message_id' => '<m@example.test>' );
	$m    = mlab_smtp_engine_mailer_from_snapshot( $snap );
	$prop = new ReflectionProperty( \PHPMailer\PHPMailer\SMTP::class, 'error' );
	$prop->setAccessible( true );
	$prop->setValue( $m->getSMTPInstance(), array( 'error' => 'RCPT TO command failed', 'detail' => 'x', 'smtp_code' => (string) $code, 'smtp_code_ex' => '' ) );
	$text = '';
	foreach ( $refused as $r ) {
		$text .= $r . ': ' . $code . ' refused';
	}
	$method = new ReflectionMethod( $m, 'smtp_info' );
	$method->setAccessible( true );
	return $method->invoke( $m, new \PHPMailer\PHPMailer\Exception( 'SMTP Error: The following recipients failed: ' . $text ) );
}
function eng_classify( array $info ): string {
	return mlab_smtp_engine_classify( array_merge( $info, array( 'request_sent' => true, 'provider' => 'smtp', 'message' => 'x' ) ) );
}

mlab_e_reset();
$two = array( 'a@example.test', 'b@example.test' );

$all450 = eng_smtp_info( $two, $two, 450 );
assert_same( false, $all450['partial'], 'all refused 450: not partial' );
assert_same( false, $all450['no_fallback'], 'all refused: DATA was skipped, fallback allowed' );
assert_same( 'temporary', eng_classify( $all450 ), 'all refused 450: temporary' );
$all550 = eng_smtp_info( $two, $two, 550 );
assert_same( 'permanent', eng_classify( $all550 ), 'all refused 550: permanent' );
$one = eng_smtp_info( $two, array( 'b@example.test' ), 550 );
assert_same( true, $one['partial'], 'one of two refused: partial' );
assert_same( true, $one['no_fallback'], 'partial: no fallback' );
assert_same( 'partial', eng_classify( $one ), 'one of two refused: partial class' );
$single = eng_smtp_info( array( 'a@example.test' ), array( 'a@example.test' ), 450 );
assert_same( 'temporary', eng_classify( $single ), 'single recipient refused: temporary, not partial' );

// Worker: temporäre Ablehnung aller Empfänger wird wiederholt, die Nutzlast bleibt.
$settle = static function ( array $info ): array {
	$id   = mlab_e_queue_message();
	$row  = mlab_smtp_store_claim_batch( 1 )[0];
	$cls  = eng_classify( $info );
	$res  = array( array( 'route' => 'primary', 'provider' => 'smtp', 'class' => $cls, 'error' => 'refused', 'info' => $info, 'provider_msg_id' => '', 'retry_after' => 0 ) );
	$out  = mlab_smtp_engine_settle( $row, (string) $row['lock_token'], array(), mlab_smtp_engine_outcome( $res ) );
	return array( $out, mlab_smtp_store_get( $id ) );
};
list( $out, $r ) = $settle( $all450 );
assert_same( 'released', $out, 'worker: all refused 450 is released for a retry' );
assert_same( 'temp_failed', $r['status'], 'worker: temp_failed' );
assert_same( 1, (int) $r['has_payload'], 'worker: payload kept for the retry' );
list( $out, $r ) = $settle( $all550 );
assert_same( 'failed', $out, 'worker: all refused 550 is permanent' );
assert_same( 'failed', $r['status'], 'worker: failed' );
list( $out, $r ) = $settle( $one );
assert_same( 'partial', $out, 'worker: one of two refused is partial' );
assert_same( 0, (int) $r['has_payload'], 'worker: partial clears the payload (some recipients got it)' );

// Minor 7: exakter Adressvergleich (ob@ ist kein Teil von bob@).
$sub = eng_smtp_info( array( 'bob@example.test', 'ob@example.test' ), array( 'ob@example.test' ), 550 );
assert_same( true, $sub['partial'], 'suffix address: only ob@ refused is partial' );
$sub = eng_smtp_info( array( 'bob@example.test', 'ob@example.test' ), array( 'bob@example.test' ), 550 );
assert_same( true, $sub['partial'], 'suffix address: only bob@ refused is partial (ob@ is a substring)' );
$sub = eng_smtp_info( array( 'bob@example.test', 'ob@example.test' ), array( 'bob@example.test', 'ob@example.test' ), 550 );
assert_same( false, $sub['partial'], 'suffix address: both refused is not partial' );
