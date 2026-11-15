<?php
// Staging-Schutz im Worker: Nachrichten aus einer anderen Umgebung werden nie gesendet.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/engine-helpers.php';
global $wpdb;

$GLOBALS['st_env']  = 'production';
$GLOBALS['st_home'] = '';
add_filter( 'mlab_smtp_environment_type', static fn() => $GLOBALS['st_env'] );
add_filter( 'home_url', static fn( $u ) => '' !== $GLOBALS['st_home'] ? $GLOBALS['st_home'] : $u );

function st_setup( array $staging = array(), array $settings = array() ): void {
	mlab_e_reset( $settings );
	delete_option( MLAB_SMTP_STAGING_OPTION );
	$GLOBALS['st_env']  = 'production';
	$GLOBALS['st_home'] = '';
	mlab_smtp_staging_init();
	if ( $staging ) {
		mlab_smtp_staging_put( $staging );
	}
	mlab_e_reply( '#api\.resend\.com/emails#', static fn() => array( 'code' => 200, 'body' => array( 'id' => 'em_s' ) ) );
}
function st_msg( string $origin = 'own', string $staged = 'off', array $extra = array() ): int {
	$snap = array();
	if ( 'none' !== $origin ) {
		$snap['origin']  = 'own' === $origin ? mlab_smtp_staging_origin_id() : hash( 'sha256', 'https://production.example' );
		$snap['staging'] = $staged;
	}
	return mlab_e_queue_message( $extra, $snap );
}
function st_run( int $id ): array {
	mlab_smtp_process_queue();
	return array( mlab_smtp_store_get( $id ), count( mlab_e_resend_requests() ) );
}

// Eigene Nachricht, Schutz aus: wird gesendet.
st_setup();
list( $row, $n ) = st_run( st_msg() );
assert_same( 'accepted', $row['status'], 'own row in a production environment is sent' );

// Kopie: Adresse geändert (auto erkennt url_changed), Zeile aus der Produktion: nicht senden, Nutzlast weg.
st_setup();
$id = st_msg( 'foreign' );
$GLOBALS['st_home'] = 'https://staging.example';
list( $row, $n ) = st_run( $id );
assert_same( 'blocked', $row['status'], 'cloned row: blocked' );
assert_same( 0, $n, 'cloned row: nothing sent' );
assert_same( 0, (int) $row['has_payload'], 'cloned row: payload cleared' );
assert_contains( 'Staging-Schutz', $row['last_error'], 'cloned row: reason' );

// Umgebungstyp staging, unbestätigt: auch Zeilen ohne Marke und eigene Zeilen werden nicht gesendet.
st_setup();
$a = st_msg( 'none' );
$b = st_msg( 'own' );
$GLOBALS['st_env'] = 'staging';
mlab_smtp_process_queue();
assert_same( array( 'blocked', 'blocked' ), array( mlab_smtp_store_get( $a )['status'], mlab_smtp_store_get( $b )['status'] ), 'unconfirmed environment: blocked' );
assert_same( 0, count( mlab_e_resend_requests() ), 'unconfirmed environment: nothing sent' );

// Manuelles Wiederholen/Erneut senden einer fremden Zeile bleibt blockiert.
st_setup();
$id = st_msg( 'foreign', 'off', array( 'status' => 'failed', 'completed_at' => time() ) );
$GLOBALS['st_home'] = 'https://staging.example';
assert_true( true === mlab_smtp_engine_manual_retry( $id ), 'foreign row: manual retry is queued' );
list( $row, $n ) = st_run( $id );
assert_same( 'blocked', $row['status'], 'foreign row: manual retry ends blocked' );
assert_same( 0, $n, 'foreign row: manual retry sends nothing' );
$id = st_msg( 'foreign', 'off', array( 'status' => 'unclear', 'completed_at' => time() ) );
assert_true( true === mlab_smtp_engine_manual_resend( $id ), 'foreign row: resend is queued' );
list( $row, $n ) = st_run( $id );
assert_same( 'blocked', $row['status'], 'foreign row: manual resend ends blocked' );
assert_same( 0, $n, 'foreign row: manual resend sends nothing' );

// Modus «off» sendet auch fremde Zeilen (bewusste Entscheidung).
st_setup( array( 'mode' => 'off' ) );
$id = st_msg( 'foreign' );
$GLOBALS['st_home'] = 'https://staging.example';
list( $row, $n ) = st_run( $id );
assert_same( 'accepted', $row['status'], 'mode off: sends' );

// Umleiten: eigene, schon umgeschriebene Nachricht geht durch, ohne Marke oder fremd nicht.
st_setup( array( 'mode' => 'redirect', 'test_email' => 'test@example.test' ), array( 'delivery_mode' => 'queue' ) );
wp_mail( 'kunde@example.test', 'Betreff', 'Text' );
$own = mlab_e_last_row();
$snap = mlab_smtp_store_get_payload( (int) $own['id'] );
assert_same( mlab_smtp_staging_origin_id(), $snap['origin'] ?? '', 'redirect: origin stored at enqueue' );
$legacy  = st_msg( 'none' );
$foreign = st_msg( 'foreign', 'redirect' );
mlab_smtp_process_queue();
assert_same( 'accepted', mlab_smtp_store_get( (int) $own['id'] )['status'], 'redirect: own queued mail passes' );
$body = json_decode( mlab_e_resend_requests()[0]['args']['body'], true );
assert_same( array( 'test@example.test' ), $body['to'], 'redirect: it went to the test address only' );
assert_same( 'blocked', mlab_smtp_store_get( $legacy )['status'], 'redirect: row without origin is blocked' );
assert_same( 'blocked', mlab_smtp_store_get( $foreign )['status'], 'redirect: foreign row is blocked' );
assert_same( 1, count( mlab_e_resend_requests() ), 'redirect: exactly one request' );
// Eigene Zeile, die vor dem Umschalten auf «umleiten» eingereiht wurde (nicht umgeschrieben): nicht senden.
$plain = st_msg( 'own', 'off' );
mlab_smtp_process_queue();
assert_same( 'blocked', mlab_smtp_store_get( $plain )['status'], 'redirect: own row queued before the switch is not sent unredirected' );
