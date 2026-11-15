<?php
// Barrierefreiheit der neuen Ansichten: aktuelle Seite, Beschriftungen, Symbole, Live-Bereiche, eindeutige IDs.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/ui-helpers.php';

ui_all_ok();
mlab_t_msg( array( 'status' => 'unclear' ) );
mlab_t_msg( array( 'status' => 'failed', 'last_error' => 'invalid_grant' ) );
mlab_t_msg( array( 'status' => 'accepted' ) );

foreach ( array( 'overview', 'queue', 'log' ) as $view ) {
	$html = ui_page( 'overview' === $view ? array() : array( 'view' => $view ) );
	assert_same( 1, substr_count( $html, 'aria-current="page"' ), "$view: exactly one current tab" );
	assert_contains( '<nav class="nav-tab-wrapper mlab-tabs" aria-label="Bereiche">', $html, "$view: tabs are a labelled navigation" );
	assert_true( 1 === substr_count( $html, '<h1>' ), "$view: one h1" );
	preg_match_all( '/class="mlab-ic[^"]*"([^>]*)>/', $html, $icons );
	$loose = array_filter( $icons[1], static fn( $a ) => ! str_contains( $a, 'aria-hidden="true"' ) );
	assert_same( 0, count( $loose ), "$view: every status icon is hidden from screen readers" );
	preg_match_all( '/\sid="([^"]+)"/', $html, $ids );
	assert_same( count( $ids[1] ), count( array_unique( $ids[1] ) ), "$view: ids are unique" );
	preg_match_all( '/<input(?![^>]*type="hidden")[^>]+id="([^"]+)"[^>]*>/', $html, $inputs );
	foreach ( $inputs[1] as $id ) {
		assert_true( str_contains( $html, 'for="' . $id . '"' ), "$view: input $id has a label" );
	}
	preg_match_all( '#<a [^>]*>\s*</a>#', $html, $empty );
	assert_same( 0, count( $empty[0] ), "$view: no empty links" );
	preg_match_all( '#<button[^>]*>(.*?)</button>#s', $html, $buttons );
	foreach ( $buttons[1] as $b ) {
		assert_true( '' !== trim( wp_strip_all_tags( $b ) ), "$view: button has a text" );
	}
}

// Jeder Status trägt Symbol und Text im selben Element.
foreach ( mlab_smtp_db_statuses() as $status ) {
	ob_start();
	mlab_smtp_ui_status_pill( $status );
	$pill = ob_get_clean();
	assert_true( 1 === preg_match( '#<span class="mlab-pill[^"]*"><span aria-hidden="true">[^<]+</span>[^<]+</span>#u', $pill ), "pill of $status has icon and text" );
}

// Live-Bereiche: Testmail-Ergebnis und Meldung nach einer Aktion.
$html = ui_page();
assert_contains( 'role="status" aria-live="polite"', $html, 'test result is a live region' );
mlab_smtp_flash( 'error', 'Fehler' );
assert_contains( 'role="alert"', ui_render( 'mlab_smtp_render_flash' ), 'error flash is announced' );
mlab_smtp_flash( 'success', 'Gut' );
assert_contains( 'role="status"', ui_render( 'mlab_smtp_render_flash' ), 'success flash is polite' );

// Bestätigung ohne Skript, Seitenzahlen mit Beschriftung.
$html = ui_render( 'mlab_smtp_render_queue', array( 'view' => 'queue', 'filter' => 'unclear' ) );
assert_contains( '<nav class="mlab-pager" aria-label="Seiten">', $html, 'pager is a labelled navigation' );

// CSS: sichtbarer Fokus und 44 px Tippfläche für die neuen Steuerelemente.
$css = (string) file_get_contents( MLAB_SMTP_DIR . 'assets/css/admin.css' );
assert_contains( '.mlab-smtp .mlab-qacts .button:focus-visible', $css, 'focus style for queue actions' );
assert_contains( 'min-height: var(--mlab-tap);', $css, 'tap target token used' );
assert_contains( '@media (max-width: 600px)', $css, 'narrow layout exists' );

t_finish();
