<?php
// Webhook-Ereignisse gelten nur für Nachrichten, die über den Weg des Endpunkts gesendet wurden.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/engine-helpers.php';
global $wpdb;

function wr_event( string $provider, string $eid, string $uuid, string $email ): void {
	mlab_smtp_webhook_process( $provider, $eid, array( 'type' => 'email.delivered', 'created_at' => gmdate( 'c' ), 'data' => array( 'email_id' => $email, 'tags' => '' === $uuid ? new stdClass() : array( 'mlab_id' => str_replace( '-', '', $uuid ) ) ) ) );
}
function wr_msg( string $route ): int {
	$id = mlab_e_queue_message( array( 'status' => 'accepted', 'completed_at' => time(), 'provider_msg_id' => 'em_' . $route ) );
	$a  = mlab_smtp_store_add_attempt( $id, array( 'route' => $route, 'provider' => 'resend' ) );
	mlab_smtp_store_finish_attempt( $a, array( 'outcome' => 'accepted', 'provider_msg_id' => 'em_' . $route ) );
	return $id;
}
function wr_matched( string $eid ): bool {
	global $wpdb;
	return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT message_id FROM ' . mlab_smtp_table( 'events' ) . ' WHERE event_id = %s', $eid ) );
}

mlab_e_reset();
$p = wr_msg( 'primary' );
$f = wr_msg( 'fallback' );
$puuid = mlab_smtp_store_get( $p )['uuid'];
$fuuid = mlab_smtp_store_get( $f )['uuid'];

// Falscher Endpunkt: Ereignis wird gespeichert, ändert aber nichts und bleibt unzugeordnet.
wr_event( 'resend-fallback', 'e1', $puuid, 'em_primary' );
assert_same( 'accepted', mlab_smtp_store_get( $p )['status'], 'fallback endpoint cannot change a primary message (by tag)' );
assert_true( ! wr_matched( 'e1' ), 'event stored unmatched' );
wr_event( 'resend-fallback', 'e2', '', 'em_primary' );
assert_same( 'accepted', mlab_smtp_store_get( $p )['status'], 'fallback endpoint cannot change a primary message (by id)' );
wr_event( 'resend', 'e3', $fuuid, 'em_fallback' );
assert_same( 'accepted', mlab_smtp_store_get( $f )['status'], 'primary endpoint cannot change a fallback message' );
wr_event( 'resend', 'e4', '', 'em_fallback' );
assert_same( 'accepted', mlab_smtp_store_get( $f )['status'], 'primary endpoint cannot change a fallback message (by id)' );
// Richtige Endpunkte wirken.
wr_event( 'resend', 'e5', $puuid, 'em_primary' );
assert_same( 'delivered', mlab_smtp_store_get( $p )['status'], 'primary endpoint changes a primary message' );
wr_event( 'resend-fallback', 'e6', $fuuid, 'em_fallback' );
assert_same( 'delivered', mlab_smtp_store_get( $f )['status'], 'fallback endpoint changes a fallback message' );
// Rematch ordnet die falschen Ereignisse nicht nachträglich zu.
mlab_smtp_webhook_rematch();
assert_true( ! wr_matched( 'e1' ) && ! wr_matched( 'e3' ), 'rematch keeps wrong-route events unmatched' );
