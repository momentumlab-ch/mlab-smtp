<?php
// Übersicht: Antwort, Aufgabenliste, drei Karten und Sonderfälle je Szenario.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/ui-helpers.php';
global $wpdb;

// 1. Alles in Ordnung.
ui_all_ok();
$html = ui_render( 'mlab_smtp_render_overview' );
$text = ui_text( $html );
assert_contains( 'Funktioniert der Versand?', $text, 'all ok: question' );
assert_contains( 'Ja, der Anbieter nimmt Ihre Mails an', $text, 'all ok: honest answer' );
assert_contains( 'Nichts. Alles ist in Ordnung.', $text, 'all ok: nothing to do' );
assert_true( ! str_contains( $text, 'Mails werden zugestellt' ), 'all ok: no claim of delivery' );
assert_true( ! preg_match( '/>\s*Zugestellt\s*</u', $html ), 'all ok: no «Zugestellt» label in any element' );
assert_contains( 'Hauptweg', $text, 'all ok: main route card' );
assert_contains( 'Getestet', $text, 'all ok: state label' );
assert_contains( 'Ist der Ersatzweg tatsächlich getestet? Ja.', $text, 'all ok: fallback answer' );
assert_contains( 'Warteschlange öffnen', $text, 'all ok: queue card link' );
assert_contains( 'Keine fällige Mail wartet.', $text, 'all ok: no oldest wait' );
assert_contains( 'Noch keine Nachrichten.', $text, 'all ok: empty recent list is explicit' );
assert_true( 1 === preg_match_all( '/<h2[^>]*class="mlab-kicker"/', $html ), 'one banner question' );
assert_true( strpos( $html, 'mlab-kicker' ) < strpos( $html, 'mlab-todolist' ) && strpos( $html, 'mlab-todolist' ) < strpos( $html, 'mlab-cards' ), 'order: banner, to-do, cards' );

// 1b. Checkliste folgt dem Bereitschaftsmodell, nicht dem alten Flag.
$html = ui_render( 'mlab_smtp_render_steps' );
assert_true( ! str_contains( $html, 'Testmail<span class="screen-reader-text"> Erledigt' ) === false, 'checklist: test mail step done when the main route is tested' );
ui_all_ok();
mlab_smtp_state_set( array( 'last_success' => 0 ) );
assert_contains( 'Testmail<span class="screen-reader-text"> Erledigt', ui_render( 'mlab_smtp_render_steps' ), 'checklist: done without the old flag' );
delete_option( MLAB_SMTP_READINESS_OPTION );
mlab_smtp_state_set( array( 'last_success' => time() ) );
assert_true( ! str_contains( ui_render( 'mlab_smtp_render_steps' ), 'Testmail<span class="screen-reader-text"> Erledigt' ), 'checklist: not done while the main route is untested' );

// 2. Hauptweg gestört.
ui_all_ok();
mlab_smtp_state_set( array( 'failing_since' => time() - 600, 'failures_since' => 3, 'last_error' => 'invalid_grant', 'last_success' => time() - 3600 ) );
$text = ui_text( ui_render( 'mlab_smtp_render_overview' ) );
assert_contains( 'Nein, Mails werden nicht gesendet', $text, 'primary failing: answer' );
assert_contains( 'Fehler beheben', $text, 'primary failing: button' );
assert_contains( 'Der Hauptweg hat ein Problem', $text, 'primary failing: to-do' );

// 3. Ersatzweg nicht getestet.
ui_all_ok();
mlab_smtp_readiness_record( 'fallback', 'check', true );
delete_option( MLAB_SMTP_READINESS_OPTION );
mlab_smtp_readiness_record( 'primary', 'test', true );
$html = ui_render( 'mlab_smtp_render_overview' );
$text = ui_text( $html );
assert_contains( 'Der Ersatzweg ist nicht getestet', $text, 'fallback untested: to-do' );
assert_contains( 'Ersatzweg testen', $text, 'fallback untested: button' );
assert_contains( 'view=fallback&#038;step=6', $html, 'fallback untested: goes to step 6' );
assert_contains( 'Ist der Ersatzweg tatsächlich getestet? Nein.', $text, 'fallback untested: answer' );

// 4. Ersatzweg fehlt ganz.
ui_all_ok();
mlab_smtp_store( array( 'fb_smtp_host' => '' ) );
$text = ui_text( ui_render( 'mlab_smtp_render_overview' ) );
assert_contains( 'Nicht eingerichtet', $text, 'no fallback: card says so' );
assert_contains( 'Ersatzweg einrichten', $text, 'no fallback: button' );

// 5. Warteschlange staut sich.
ui_all_ok();
mlab_t_msg( array( 'status' => 'queued', 'next_attempt_at' => time() - 3600, 'created_at' => time() - 3600 ) );
mlab_t_msg( array( 'status' => 'temp_failed', 'next_attempt_at' => time() - 1800, 'attempts' => 1 ) );
$html = ui_render( 'mlab_smtp_render_overview' );
$text = ui_text( $html );
assert_contains( 'Wartende E-Mails ansehen', $text, 'stalled: button' );
assert_contains( 'Wartende E-Mails werden nicht abgearbeitet', $text, 'stalled: to-do' );
assert_contains( 'view=queue', $html, 'stalled: links to the queue' );
assert_contains( 'Älteste fällige Mail wartet seit', $text, 'stalled: oldest wait in the queue card' );
assert_contains( 'Wiederholung geplant', $text, 'stalled: counts' );

// 6. Unklare Nachrichten und endgültige Fehler, höchstens vier Aufgaben.
ui_all_ok();
mlab_t_msg( array( 'status' => 'unclear' ) );
mlab_t_msg( array( 'status' => 'failed', 'last_error' => 'Server nicht erreichbar' ) );
mlab_smtp_store( array( 'fb_smtp_host' => '' ) );
mlab_smtp_state_set( array( 'failing_since' => time() - 60, 'failures_since' => 1, 'last_error' => 'x' ) );
mlab_t_msg( array( 'status' => 'queued', 'next_attempt_at' => time() - 3600 ) );
$html = ui_render( 'mlab_smtp_render_overview' );
$text = ui_text( $html );
assert_contains( 'Unklare Mails ansehen', $text, 'unclear: to-do button' );
assert_contains( 'Fehlgeschlagene ansehen', $text, 'failed: to-do button' );
assert_true( substr_count( $html, 'mlab-todo-item' ) <= 4, 'at most four to-dos' );
assert_contains( 'Versandstatus unklar', $text, 'unclear status shows in the recent list' );

// 7. Staging-Schutz oben.
ui_all_ok();
mlab_smtp_staging_put( array( 'mode' => 'block' ) );
$html = ui_render( 'mlab_smtp_render_overview' );
assert_true( strpos( $html, 'mlab-smtp-staging-banner' ) < strpos( $html, 'mlab-kicker' ), 'staging banner comes first' );
assert_contains( 'Nein, der Versand ist blockiert', ui_text( $html ), 'staging: answer' );
mlab_smtp_staging_put( array( 'mode' => 'off' ) );

// 8. Migration unvollständig.
ui_all_ok();
update_option( 'mlab_smtp_migration', array( 'done' => false, 'total' => 10, 'migrated' => 6, 'failed' => 2 ) );
$text = ui_text( ui_render( 'mlab_smtp_render_overview' ) );
assert_contains( 'konnte nicht vollständig übernommen werden', $text, 'migration error is named' );
update_option( 'mlab_smtp_migration', array( 'done' => false, 'total' => 10, 'migrated' => 6 ) );
assert_contains( 'wird übernommen (6 von 10)', ui_text( ui_render( 'mlab_smtp_render_overview' ) ), 'migration pending is named' );
delete_option( 'mlab_smtp_migration' );

// 9. Tabellen fehlen.
ui_all_ok();
foreach ( mlab_smtp_db_tables() as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `$table`" );
}
$html = ui_render( 'mlab_smtp_render_overview' );
$text = ui_text( $html );
assert_contains( 'Protokoll und Warteschlange sind nicht verfügbar', $text, 'tables missing: explicit' );
assert_contains( 'Nicht verfügbar', $text, 'tables missing: queue card' );
assert_contains( 'Keine Zahlen verfügbar', $text, 'tables missing: no figures' );
assert_contains( 'Funktioniert der Versand?', $text, 'tables missing: page still renders' );
mlab_smtp_db_install();

// 10. Nicht eingerichtet: Einrichtung statt Antwort.
delete_option( MLAB_SMTP_OPTION );
$text = ui_text( ui_render( 'mlab_smtp_render_overview' ) );
assert_contains( 'Einrichtung starten', $text, 'unconfigured: onboarding' );

t_finish();
