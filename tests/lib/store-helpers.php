<?php
// Hilfen für die Store-Tests: Tabellen sicherstellen und leeren.
function mlab_t_reset_store(): void {
	global $wpdb;
	mlab_smtp_db_install();
	foreach ( mlab_smtp_db_tables() as $table ) {
		$wpdb->query( "DELETE FROM `$table`" );
	}
	delete_option( 'mlab_smtp_migration' );
	delete_option( 'mlab_smtp_migration_lock' );
}

function mlab_t_msg( array $extra = array() ) {
	return mlab_smtp_store_create( array_merge( array(
		'route'      => 'primary',
		'provider'   => 'resend',
		'recipients' => array( 'user@example.test' ),
		'subject'    => 'Betreff',
		'mode'       => 'queued',
	), $extra ) );
}
