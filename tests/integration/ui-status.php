<?php
// Statusvokabular: vollständig, ehrlich (angenommen ist nicht zugestellt), Symbol plus Text.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/ui-helpers.php';
ui_reset();

$all = array( 'queued', 'processing', 'accepted', 'delivered', 'temp_failed', 'failed', 'unclear', 'partial', 'blocked', 'bounced', 'complained' );
foreach ( $all as $status ) {
	$s = mlab_smtp_ui_message_status( $status );
	assert_true( '' !== $s['label'] && '' !== $s['text'] && '' !== $s['icon'], "$status has label, text and icon" );
	assert_true( in_array( $s['level'], array( 'ok', 'warn', 'bad', 'info' ), true ), "$status has a known level" );
}
foreach ( mlab_smtp_db_statuses() as $status ) {
	assert_true( 'Status unbekannt' !== mlab_smtp_ui_message_status( $status )['label'], "store status $status is covered" );
}
assert_same( 'Vom Anbieter angenommen', mlab_smtp_ui_message_status( 'accepted' )['label'], 'accepted wording' );
assert_same( 'Zustellung bestätigt', mlab_smtp_ui_message_status( 'delivered' )['label'], 'delivered wording' );
assert_same( 'Versandstatus unklar', mlab_smtp_ui_message_status( 'unclear' )['label'], 'unclear wording' );
assert_same( 'Teilweise angenommen', mlab_smtp_ui_message_status( 'partial' )['label'], 'partial wording' );
assert_same( 'Nicht gesendet (Staging-Schutz)', mlab_smtp_ui_message_status( 'blocked' )['label'], 'blocked wording' );
foreach ( $all as $status ) {
	$s = mlab_smtp_ui_message_status( $status );
	if ( 'delivered' === $status ) {
		continue;
	}
	assert_true( ! preg_match( '/zugestellt/iu', $s['label'] ), "$status label does not claim delivery" );
}
assert_true( ! str_contains( mlab_smtp_ui_message_status( 'accepted' )['text'], 'zugestellt' ), 'accepted text does not claim delivery' );
$labels = array_map( static fn( $s ) => mlab_smtp_ui_message_status( $s )['label'], $all );
assert_same( count( $all ), count( array_unique( $labels ) ), 'labels are distinct' );

// Pille: Symbol und Text im selben Element.
ob_start();
mlab_smtp_ui_status_pill( 'unclear' );
$pill = ob_get_clean();
assert_contains( 'Versandstatus unklar', $pill, 'pill has text' );
assert_contains( 'aria-hidden="true">?', $pill, 'pill has a symbol' );
assert_same( 'warn', mlab_smtp_ui_message_status( 'unclear' )['level'], 'unclear is a warning' );
assert_same( 'info', mlab_smtp_ui_message_status( 'nonsense' )['level'], 'unknown status is handled' );

t_finish();
