<?php
// Protokoll: Seiten, Filter, Suche, Detail mit Versuchen je Weg, Ereignisse, Kennung, Maskierung, Zugriff.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/ui-helpers.php';
global $wpdb;

function ul_rows( string $html ): int {
	return preg_match_all( '#<li><a class="mlab-li[^"]*" href="[^"]*view=log[^"]*entry=#', $html );
}

ui_all_ok();
for ( $i = 0; $i < 23; $i++ ) {
	mlab_t_msg( array( 'status' => 'accepted', 'subject' => "Angenommen $i", 'created_at' => time() - 1000 - $i ) );
}
mlab_t_msg( array( 'status' => 'delivered', 'subject' => 'Bestätigt', 'created_at' => time() - 10 ) );
mlab_t_msg( array( 'status' => 'failed', 'subject' => 'Kaputt', 'last_error' => 'Server nicht erreichbar', 'created_at' => time() - 20 ) );
$unclear = mlab_t_msg( array( 'status' => 'unclear', 'subject' => 'Unklare Mail', 'created_at' => time() - 30, 'recipients' => array( 'anna@example.test' ) ) );
mlab_t_msg( array( 'status' => 'queued', 'subject' => 'Wartet', 'created_at' => time() - 40 ) );
mlab_t_msg( array( 'status' => 'blocked', 'subject' => 'Gesperrt', 'created_at' => time() - 50 ) );

// Seiten.
$p1 = ui_render( 'mlab_smtp_render_log', array( 'view' => 'log' ) );
$p2 = ui_render( 'mlab_smtp_render_log', array( 'view' => 'log', 'paged' => '2' ) );
assert_same( 20, ul_rows( $p1 ), 'page 1 has 20 rows' );
assert_same( 8, ul_rows( $p2 ), 'page 2 has the rest' );
assert_contains( '28 Einträge', ui_text( $p1 ), 'total shown' );
assert_contains( '>Weiter<', $p1, 'next link' );
assert_contains( '>Zurück<', $p2, 'previous link' );

// Filter.
$expect = array( 'accepted' => 23, 'delivered' => 1, 'open' => 1, 'unclear' => 1, 'failed' => 1, 'other' => 1, 'all' => 20 );
foreach ( $expect as $status => $n ) {
	$html = ui_render( 'mlab_smtp_render_log', array( 'view' => 'log', 'status' => $status ) );
	assert_same( min( $n, 20 ), ul_rows( $html ), "filter $status" );
	assert_contains( ( 'all' === $status ? 28 : $n ) . ' Eintr', ui_text( $html ), "filter $status total" );
	assert_contains( 'aria-current="true">', $html, "filter $status marked" );
}
foreach ( array( 'Vom Anbieter angenommen', 'Zustellung bestätigt', 'Unklar', 'Weitere Probleme' ) as $label ) {
	assert_contains( $label, $p1, "filter label $label" );
}

// Suche.
$html = ui_render( 'mlab_smtp_render_log', array( 'view' => 'log', 's' => 'anna@example.test' ) );
assert_same( 1, ul_rows( $html ), 'search by recipient' );
assert_contains( 'for="mlab-log-search"', $html, 'search has a label' );
assert_same( 0, ul_rows( ui_render( 'mlab_smtp_render_log', array( 'view' => 'log', 's' => 'gibt-es-nicht' ) ) ), 'search without hit' );
assert_contains( 'Keine Einträge für diesen Filter.', ui_render( 'mlab_smtp_render_log', array( 'view' => 'log', 's' => 'gibt-es-nicht' ) ), 'empty result is explicit' );

// Detail mit Versuchen je Weg, Ereignissen und Kennung.
$row = mlab_smtp_store_get( $unclear );
$a1  = mlab_smtp_store_add_attempt( $unclear, array( 'route' => 'primary', 'provider' => 'resend' ) );
mlab_smtp_store_finish_attempt( $a1, array( 'outcome' => 'temporary', 'http_status' => 503, 'error_text' => 'Server nicht erreichbar' ) );
$a2 = mlab_smtp_store_add_attempt( $unclear, array( 'route' => 'fallback', 'provider' => 'smtp' ) );
mlab_smtp_store_finish_attempt( $a2, array( 'outcome' => 'unknown', 'error_text' => 'Connection timed out' ) );
mlab_smtp_store_add_event( array( 'provider' => 'resend', 'event_id' => 'evt-1', 'type' => 'email.delivered', 'message_id' => $unclear, 'occurred_at' => time() - 5 ) );
mlab_smtp_store_add_event( array( 'provider' => 'resend', 'event_id' => 'evt-2', 'type' => 'email.bounced', 'message_id' => $unclear, 'occurred_at' => time() ) );
$html = ui_render( 'mlab_smtp_render_log', array( 'view' => 'log', 'entry' => $row['uuid'] ) );
$text = ui_text( $html );
assert_contains( 'Hauptweg: Resend', $text, 'detail: primary attempt' );
assert_contains( 'Ersatzweg: ' . mlab_smtp_ui_providers()['smtp']['name'], $text, 'detail: fallback attempt listed separately' );
assert_contains( 'Vorübergehender Fehler', $text, 'detail: outcome of the primary attempt' );
assert_contains( 'Ergebnis unklar', $text, 'detail: outcome of the fallback attempt' );
assert_contains( 'Antwortcode 503', $text, 'detail: http status' );
assert_contains( 'Zustellung bestätigt', $text, 'detail: delivered event' );
assert_contains( 'Zurückgewiesen', $text, 'detail: bounced event' );
assert_contains( 'Meldungen des Anbieters', $text, 'detail: events heading' );
assert_contains( 'data-mlab-copy="' . $row['uuid'] . '"', $html, 'detail: copyable correlation id' );
assert_contains( 'Kennung', $text, 'detail: correlation id label' );
assert_contains( 'nicht im Protokoll gespeichert', $text, 'detail: no content stored' );
assert_contains( 'name="do" value="delivered"', $html, 'detail: actions for an unclear mail' );
assert_contains( 'name="back[view]" value="log"', $html, 'detail: actions return to the log' );
assert_contains( 'name="back[entry]" value="' . $row['uuid'] . '"', $html, 'detail: return keeps the entry' );
assert_true( ! str_contains( $html, 'Zugestellt<' ), 'detail: no claim of delivery' );

// Detail ohne Versuche.
$plain = mlab_t_msg( array( 'status' => 'accepted', 'subject' => 'Ohne Versuche' ) );
$html  = ui_render( 'mlab_smtp_render_log', array( 'view' => 'log', 'entry' => mlab_smtp_store_get( $plain )['uuid'] ) );
assert_contains( 'Es wurde noch kein Versuch aufgezeichnet.', $html, 'detail: empty attempts are explicit' );
assert_true( ! str_contains( $html, 'name="do"' ), 'detail: accepted mails have no actions' );

// Maskierung.
$xss = mlab_t_msg( array( 'status' => 'failed', 'subject' => 'x', 'created_at' => time() + 5 ) );
$wpdb->update( mlab_smtp_table( 'messages' ), array( 'subject' => '<script>alert(1)</script>', 'recipients' => '<img src=x onerror=alert(2)>', 'last_error' => '<svg onload=alert(3)>' ), array( 'id' => $xss ) );
$wpdb->insert( mlab_smtp_table( 'attempts' ), array( 'message_id' => $xss, 'route' => 'primary', 'provider' => 'x"><script>', 'started_at' => time(), 'outcome' => 'permanent', 'error_text' => '<b onmouseover=alert(4)>', 'idem_key' => '', 'mail_message_id' => '' ) );
$html = ui_render( 'mlab_smtp_render_log', array( 'view' => 'log', 'entry' => mlab_smtp_store_get( $xss )['uuid'] ) );
foreach ( array( '<script>alert', '<img src=x', '<svg onload', '<b onmouseover', 'x"><script>' ) as $raw ) {
	assert_true( ! str_contains( $html, $raw ), "escaped: $raw" );
}
assert_contains( '&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'escaped subject visible' );

// Leeres Protokoll.
mlab_t_reset_store();
assert_contains( 'Noch keine Nachrichten aufgezeichnet.', ui_render( 'mlab_smtp_render_log', array( 'view' => 'log' ) ), 'empty log is explicit' );
assert_contains( 'Wählen Sie einen Eintrag', ui_render( 'mlab_smtp_render_log', array( 'view' => 'log' ) ), 'empty detail is explicit' );

// Leeren und Export-Bestätigung bleiben.
$html = ui_render( 'mlab_smtp_render_log', array( 'view' => 'log' ) );
assert_contains( 'Protokoll leeren', $html, 'clear stays' );
$html = ui_render( 'mlab_smtp_render_log', array( 'view' => 'log', 'confirm' => 'clear' ) );
assert_contains( 'name="action" value="mlab_smtp_clear_log_confirmed"', $html, 'clear confirmation keeps its form' );

// Tabellen fehlen.
foreach ( mlab_smtp_db_tables() as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `$table`" );
}
assert_contains( 'nicht verfügbar', ui_text( ui_render( 'mlab_smtp_render_log', array( 'view' => 'log' ) ) ), 'missing tables are explained in the log' );
mlab_smtp_db_install();

// Zugriff.
$sub = get_user_by( 'login', 'ui_sub' ) ? get_user_by( 'login', 'ui_sub' )->ID : wp_insert_user( array( 'user_login' => 'ui_sub', 'user_pass' => 'pw-Sub-123456!', 'role' => 'subscriber', 'user_email' => 'sub@example.test' ) );
foreach ( array( $sub, 0 ) as $uid ) {
	wp_set_current_user( $uid );
	assert_same( '', trim( ui_page( array( 'view' => 'log' ) ) ), "user $uid: log page is empty" );
}
ui_admin();

t_finish();
