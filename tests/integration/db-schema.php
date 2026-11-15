<?php
// Schema: Tabellen, Idempotenz, Version, ausgefallene Tabellen.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/store-helpers.php';
global $wpdb;

mlab_t_reset_store();
assert_true( mlab_smtp_db_tables_exist(), 'all four tables exist after install' );
assert_same( MLAB_SMTP_SCHEMA_VERSION, get_option( 'mlab_smtp_db_version' ), 'schema version is stored in mlab_smtp_db_version' );
assert_true( mlab_smtp_db_install(), 'install is idempotent (second run succeeds)' );
foreach ( array( 'messages', 'attempts', 'events', 'recipients' ) as $name ) {
	assert_same( $wpdb->prefix . 'mlab_smtp_' . $name, mlab_smtp_table( $name ), "table name $name" );
}
$cols = $wpdb->get_col( 'DESCRIBE ' . mlab_smtp_table( 'messages' ) );
assert_true( in_array( 'payload', $cols, true ) && in_array( 'legacy_key', $cols, true ) && in_array( 'lock_token', $cols, true ), 'messages has payload, legacy_key and lock_token' );
assert_true( in_array( mlab_smtp_table( 'events' ), apply_filters( 'wpmu_drop_tables', array() ), true ), 'wpmu_drop_tables lists our tables' );
assert_same( array( 'queued', 'processing', 'accepted', 'delivered', 'temp_failed', 'failed', 'unclear', 'partial', 'blocked' ), mlab_smtp_db_statuses(), 'status vocabulary' );

// Eindeutigkeit von uuid und (provider, event_id).
$id = mlab_t_msg( array( 'uuid' => '11111111-1111-4111-8111-111111111111' ) );
assert_true( $id > 0, 'create returns an id' );
assert_same( false, mlab_t_msg( array( 'uuid' => '11111111-1111-4111-8111-111111111111' ) ), 'duplicate uuid is refused' );
assert_true( false !== mlab_smtp_store_add_event( array( 'provider' => 'resend', 'event_id' => 'evt_1', 'type' => 'email.sent', 'message_id' => $id ) ), 'event stored' );
assert_same( false, mlab_smtp_store_add_event( array( 'provider' => 'resend', 'event_id' => 'evt_1', 'type' => 'email.sent' ) ), 'same event id of a provider is stored once' );

// Fehlende Tabellen: kein Fatal, leere Werte, wp_mail-fähig.
mlab_smtp_db_drop();
assert_true( ! mlab_smtp_db_tables_exist(), 'tables are gone after drop' );
assert_same( false, mlab_t_msg(), 'create returns false when the tables are missing' );
assert_same( 0, array_sum( mlab_smtp_store_counts() ), 'counts are zero without tables' );
assert_same( array(), mlab_smtp_store_list()['items'], 'list is empty without tables' );
assert_same( array(), mlab_smtp_log_get(), 'log_get is empty without tables' );
assert_same( array(), mlab_smtp_store_claim_batch(), 'claim is empty without tables' );
mlab_smtp_log_add( array( 'to' => array( 'user@example.test' ), 'subject' => 'x' ), null );
assert_true( true, 'log_add does not fatal without tables' );

// Lazy-Upgrade: alte Version -> init legt Tabellen an.
update_option( 'mlab_smtp_db_version', '1.2.0', false );
mlab_smtp_db_maybe_upgrade();
assert_true( mlab_smtp_db_tables_exist(), 'lazy upgrade on init recreates the tables' );
assert_same( MLAB_SMTP_SCHEMA_VERSION, get_option( 'mlab_smtp_db_version' ), 'version updated after lazy upgrade' );
t_finish();
