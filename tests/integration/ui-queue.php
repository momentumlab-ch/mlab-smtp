<?php
// Warteschlange: Filter, Seiten, Zeilen, Aktionen mit Nonce, Rückkehr mit Meldung, Zugriff, Maskierung.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/ui-helpers.php';

class UQ_Redirect extends RuntimeException {}
class UQ_Die extends RuntimeException {}
add_filter( 'wp_redirect', static function ( $location ) {
	throw new UQ_Redirect( (string) $location );
} );
add_filter( 'wp_die_handler', static function () {
	return static function ( $message, $title = '', $args = array() ) {
		throw new UQ_Die( (string) ( is_array( $args ) ? ( $args['response'] ?? 0 ) : 0 ) );
	};
} );

function uq_rows( string $html ): int {
	return substr_count( $html, 'class="mlab-qrow"' );
}

ui_all_ok();
$payload = array( 'subject' => 'x', 'to' => array( 'a@example.test' ) );
for ( $i = 0; $i < 3; $i++ ) {
	mlab_t_msg( array( 'status' => 'queued', 'subject' => "Wartend $i", 'next_attempt_at' => time() + 600 ) );
}
for ( $i = 0; $i < 2; $i++ ) {
	mlab_t_msg( array( 'status' => 'processing', 'subject' => "Läuft $i" ) );
}
mlab_t_msg( array( 'status' => 'temp_failed', 'subject' => 'Wiederholung', 'attempts' => 2, 'max_attempts' => 6, 'next_attempt_at' => time() + 120, 'last_error' => 'Connection timed out' ) );
$unclear_a = mlab_t_msg( array( 'status' => 'unclear', 'subject' => 'Unklar mit Inhalt' ) );
$unclear_b = mlab_t_msg( array( 'status' => 'unclear', 'subject' => 'Unklar ohne Inhalt' ) );
$failed_a  = mlab_t_msg( array( 'status' => 'failed', 'subject' => 'Fehler mit Inhalt', 'last_error' => 'invalid_grant', 'attempts' => 6, 'max_attempts' => 6 ) );
$failed_b  = mlab_t_msg( array( 'status' => 'failed', 'subject' => 'Fehler ohne Inhalt', 'last_error' => 'Server nicht erreichbar' ) );
$failed_c  = mlab_t_msg( array( 'status' => 'failed', 'subject' => '<script>alert(1)</script>', 'recipients' => array( '<b>x</b>@example.test' ) ) );
global $wpdb;
$wpdb->update( mlab_smtp_table( 'messages' ), array( 'subject' => '<script>alert(1)</script>', 'recipients' => '<b>x</b>@example.test', 'last_error' => '<img src=x onerror=alert(2)>' ), array( 'id' => $failed_c ) );
$wpdb->query( "UPDATE `" . mlab_smtp_table( 'messages' ) . "` SET attempts = 2 WHERE status = 'temp_failed'" );
foreach ( array( $unclear_a, $failed_a ) as $id ) {
	assert_same( true, mlab_smtp_store_set_payload( $id, $payload, time() + DAY_IN_SECONDS ), "payload stored for $id" );
}

// Filter und Zählung.
$expect = array( 'waiting' => 3, 'running' => 3, 'unclear' => 2, 'failed' => 3, 'all' => 11 );
foreach ( $expect as $filter => $n ) {
	$html = ui_render( 'mlab_smtp_render_queue', array( 'view' => 'queue', 'filter' => $filter ) );
	assert_same( $n, uq_rows( $html ), "filter $filter shows $n rows" );
	assert_contains( "$n Einträge", ui_text( $html ), "filter $filter shows the total" );
}
$html = ui_render( 'mlab_smtp_render_queue', array( 'view' => 'queue' ) );
assert_same( 11, uq_rows( $html ), 'default filter is all open, unclear and failed' );
assert_true( 1 === preg_match( '#aria-current="true">Alle <span class="mlab-seg-n">11</span>#', $html ), 'active filter has aria-current and its count' );
foreach ( array( 'Wartend', 'In Bearbeitung / Wiederholung', 'Unklar', 'Endgültig fehlgeschlagen', 'Alle' ) as $label ) {
	assert_contains( $label, $html, "segment $label" );
}
assert_same( 11, mlab_smtp_queue_badge_count(), 'badge counts open, unclear and failed' );
$text = ui_text( $html );
assert_contains( 'Versuch 2 von 6 · nächster Versuch', $text, 'row: attempts and next attempt' );
assert_contains( 'Hauptweg: Resend', $text, 'row: route and provider' );
assert_contains( 'Technische Meldung', $html, 'row: technical details collapsed' );
assert_contains( '<details class="mlab-technical">', $html, 'technical details are a details element' );
assert_contains( 'Versandstatus unklar', $text, 'unclear status pill' );
assert_contains( 'Was bedeutet «Versandstatus unklar»?', $text, 'unclear explanation panel' );
assert_contains( 'nicht von selbst erneut', $text, 'explains why nothing is resent' );

// Aktionen nur im erlaubten Zustand.
$failed = ui_render( 'mlab_smtp_render_queue', array( 'view' => 'queue', 'filter' => 'failed' ) );
assert_same( 1, substr_count( $failed, '>Erneut versuchen<' ), 'retry only for the failed mail with content' );
assert_contains( 'Erneut versuchen ist nicht möglich: Der Inhalt der Mail wird nicht mehr aufbewahrt', $failed, 'explains why retry is impossible' );
assert_true( ! str_contains( $failed, 'name="do" value="delivered"' ), 'no mark-delivered for failed mails' );
$unclear = ui_render( 'mlab_smtp_render_queue', array( 'view' => 'queue', 'filter' => 'unclear' ) );
assert_same( 2, substr_count( $unclear, '>Als zugestellt markieren<' ), 'mark delivered for every unclear mail' );
assert_same( 1, substr_count( $unclear, 'Erneut senden (Doppelversand möglich)' ), 'resend only where content exists' );
assert_contains( 'Erneut senden ist nicht möglich', $unclear, 'explains missing content for resend' );
foreach ( array( 'waiting', 'running' ) as $filter ) {
	$h = ui_render( 'mlab_smtp_render_queue', array( 'view' => 'queue', 'filter' => $filter ) );
	assert_true( ! str_contains( $h, 'mlab_smtp_message_action' ), "no actions for $filter" );
}
assert_same( 1, substr_count( $failed, 'name="_wpnonce"' ), 'one nonce per action form' );
assert_contains( 'name="action" value="mlab_smtp_message_action"', $failed, 'form posts the message action' );
assert_contains( 'name="do" value="retry"', $failed, 'retry field' );
assert_contains( 'name="id" value="' . $failed_a . '"', $failed, 'id field' );
assert_contains( 'name="back[filter]" value="failed"', $failed, 'return target is carried' );
assert_true( ! str_contains( $failed . $unclear, 'onclick' ) && ! str_contains( $failed . $unclear, 'confirm(' ), 'no browser confirm' );

// Bestätigungsschritt in Klartext.
$confirm = ui_render( 'mlab_smtp_render_queue', array( 'view' => 'queue', 'filter' => 'unclear', 'confirm' => 'resend', 'id' => (string) $unclear_a ) );
assert_contains( 'kann sie doppelt ankommen. Wirklich erneut senden?', ui_text( $confirm ), 'confirm text' );
assert_contains( 'name="do" value="resend"', $confirm, 'resend form after confirm' );
assert_contains( 'role="alert"', $confirm, 'confirm is announced' );
assert_true( ! str_contains( $unclear, 'name="do" value="resend"' ), 'no resend form before the confirm step' );

// Maskierung.
$all = ui_render( 'mlab_smtp_render_queue', array( 'view' => 'queue', 'filter' => 'failed' ) );
assert_true( ! str_contains( $all, '<script>alert(1)' ), 'subject is escaped' );
assert_contains( '&lt;script&gt;alert(1)&lt;/script&gt;', $all, 'escaped subject is visible' );
assert_true( ! str_contains( $all, '<b>x</b>' ) && ! str_contains( $all, '<img src=x' ), 'recipient and error are escaped' );

// Seiten.
for ( $i = 0; $i < 25; $i++ ) {
	mlab_t_msg( array( 'status' => 'failed', 'subject' => "Seite $i" ) );
}
$p1 = ui_render( 'mlab_smtp_render_queue', array( 'view' => 'queue', 'filter' => 'failed' ) );
$p2 = ui_render( 'mlab_smtp_render_queue', array( 'view' => 'queue', 'filter' => 'failed', 'paged' => '2' ) );
assert_same( 20, uq_rows( $p1 ), 'page 1 has 20 rows' );
assert_same( 8, uq_rows( $p2 ), 'page 2 has the rest' );
assert_contains( '28 Einträge', ui_text( $p1 ), 'total on page 1' );
assert_contains( 'aria-current="page"', $p2, 'current page marked' );
assert_contains( '>Zurück<', $p2, 'previous link on page 2' );
assert_contains( '>Weiter<', $p1, 'next link on page 1' );
assert_true( ! str_contains( $p1, '>Zurück<' ) && ! str_contains( $p2, '>Weiter<' ), 'no dead links at the ends' );
assert_contains( 'aria-label="Seite 2"', $p1, 'page numbers have labels' );

// Cron-Hinweis bei Stau.
ui_all_ok();
$none = ui_render( 'mlab_smtp_render_queue', array( 'view' => 'queue' ) );
assert_contains( 'Nichts zu tun', $none, 'empty state is explicit' );
assert_true( ! str_contains( $none, 'System-Cron' ), 'no cron hint while nothing waits' );
mlab_t_msg( array( 'status' => 'queued', 'next_attempt_at' => time() - 3600 ) );
$stale = ui_render( 'mlab_smtp_render_queue', array( 'view' => 'queue' ) );
assert_contains( 'Die Warteschlange läuft nicht regelmässig', $stale, 'stale queue hint' );
assert_contains( 'System-Cron', $stale, 'cron recommendation' );
assert_contains( 'So richten Sie einen System-Cron ein', $stale, 'how-to inside the plugin' );

// Tabs.
$page = ui_page( array( 'view' => 'queue' ) );
assert_true( 1 === preg_match( '/nav-tab nav-tab-active" href="[^"]*view=queue" aria-current="page">Warteschlange/', $page ), 'queue tab is current' );
assert_same( 1, substr_count( $page, 'aria-current="page"' ), 'exactly one current tab' );
assert_contains( '<span class="mlab-count">1<span class="screen-reader-text"> Hinweis</span></span>', $page, 'tab badge with spoken text' );
add_filter( 'mlab_smtp_admin_tabs', static function ( $tabs ) {
	$tabs['pro'] = 'Pro';
	return $tabs;
} );
assert_true( array_key_exists( 'pro', mlab_smtp_admin_tabs() ), 'tabs are filterable' );
assert_contains( '>Pro<', ui_page(), 'extension tab is shown' );
remove_all_filters( 'mlab_smtp_admin_tabs' );

// Aktion ausführen: zurück auf dieselbe Seite mit Meldung.
ui_all_ok();
$id = mlab_t_msg( array( 'status' => 'failed', 'last_error' => 'x' ) );
mlab_smtp_store_set_payload( $id, $payload, time() + DAY_IN_SECONDS );
$unclear_a = mlab_t_msg( array( 'status' => 'unclear' ) );
$_REQUEST = $_POST = array( 'do' => 'retry', 'id' => (string) $id, 'back' => array( 'view' => 'queue', 'filter' => 'failed', 'paged' => '2', 'evil' => 'x' ), '_wpnonce' => wp_create_nonce( mlab_smtp_message_nonce_action( (int) $id, 'retry' ) ), 'action' => 'mlab_smtp_message_action' );
$_GET = array();
try {
	mlab_smtp_handle_message_action();
	$location = '';
} catch ( UQ_Redirect $e ) {
	$location = $e->getMessage();
}
assert_contains( 'view=queue', $location, 'action returns to the queue' );
assert_contains( 'filter=failed', $location, 'action keeps the filter' );
assert_contains( 'paged=2', $location, 'action keeps the page' );
assert_true( ! str_contains( $location, 'evil' ), 'unknown return fields are dropped' );
assert_same( 'queued', mlab_smtp_store_get( $id )['status'], 'retry re-queued the mail' );
$flash = get_transient( 'mlab_smtp_flash_' . get_current_user_id() );
assert_same( 'success', $flash['type'] ?? '', 'flash message set' );
$shown = ui_render( 'mlab_smtp_render_flash' );
assert_contains( 'zum erneuten Versuch eingereiht', $shown, 'flash is shown once' );
assert_contains( 'role="status"', $shown, 'flash is a live region' );

// Zustandsfehler erscheint als Fehlermeldung.
$_REQUEST = $_POST = array( 'do' => 'delivered', 'id' => (string) $id, 'back' => array( 'view' => 'log' ), '_wpnonce' => wp_create_nonce( mlab_smtp_message_nonce_action( (int) $id, 'delivered' ) ) );
try {
	mlab_smtp_handle_message_action();
} catch ( UQ_Redirect $e ) {
	assert_contains( 'view=log', $e->getMessage(), 'wrong state returns to the log' );
}
assert_contains( 'anderen Status', ui_render( 'mlab_smtp_render_flash' ), 'wrong state explained' );

// Ohne Nonce keine Aktion.
$_REQUEST = $_POST = array( 'do' => 'delivered', 'id' => (string) $unclear_a, 'back' => array( 'view' => 'queue' ) );
assert_throws( 'mlab_smtp_handle_message_action', 'missing nonce is refused', Throwable::class );
assert_same( 'unclear', mlab_smtp_store_get( $unclear_a )['status'], 'mail untouched without nonce' );
$_REQUEST = $_POST = array( 'do' => 'delivered', 'id' => '1' );
mlab_smtp_handle_message_action();
assert_true( true, 'requests without return field are left to the standard handler' );

// Zugriff: Abonnent und anonym.
$sub = get_user_by( 'login', 'ui_sub' ) ? get_user_by( 'login', 'ui_sub' )->ID : wp_insert_user( array( 'user_login' => 'ui_sub', 'user_pass' => 'pw-Sub-123456!', 'role' => 'subscriber', 'user_email' => 'sub@example.test' ) );
foreach ( array( $sub, 0 ) as $uid ) {
	wp_set_current_user( $uid );
	assert_same( '', trim( ui_render( 'mlab_smtp_render_queue', array( 'view' => 'queue' ) ) ), "user $uid: queue view is empty" );
	assert_same( '', trim( ui_page( array( 'view' => 'queue' ) ) ), "user $uid: page is empty" );
	ob_start();
	mlab_smtp_render_message_actions( array( 'id' => $unclear_a, 'status' => 'unclear', 'has_payload' => 1 ), array( 'view' => 'queue' ) );
	assert_same( '', ob_get_clean(), "user $uid: no action buttons" );
	$_REQUEST = $_POST = array( 'do' => 'delivered', 'id' => (string) $unclear_a, 'back' => array( 'view' => 'queue' ), '_wpnonce' => wp_create_nonce( mlab_smtp_message_nonce_action( (int) $unclear_a, 'delivered' ) ) );
	assert_throws( 'mlab_smtp_handle_message_action', "user $uid: action is refused", UQ_Die::class );
	assert_same( 'unclear', mlab_smtp_store_get( $unclear_a )['status'], "user $uid: mail untouched" );
}
ui_admin();

// Tabellen fehlen.
global $wpdb;
foreach ( mlab_smtp_db_tables() as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `$table`" );
}
$gone = ui_render( 'mlab_smtp_render_queue', array( 'view' => 'queue' ) );
assert_contains( 'Protokoll und Warteschlange sind nicht verfügbar', ui_text( $gone ), 'missing tables are explained in the queue' );
assert_same( 0, mlab_smtp_queue_badge_count(), 'badge is zero without tables' );
mlab_smtp_db_install();

t_finish();
