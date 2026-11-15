<?php
/**
 * Datenbankschema ab 1.5.0: Nachrichten, Versuche, Ereignisse und Empfänger-Index (pro Site, `$wpdb->prefix`).
 *
 * Zeiten sind Unix-Sekunden (UTC) als BIGINT, damit Vergleiche auf MySQL und SQLite gleich laufen.
 * Fehlen die Tabellen oder sind sie nicht bereit, liefern die Store-Funktionen false oder leere Werte,
 * `wp_mail()` läuft dann ohne Protokoll weiter.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_SCHEMA_VERSION  = '1.5.0.3';
const MLAB_SMTP_DB_ERROR_OPTION = 'mlab_smtp_db_error';
// A failed upgrade is repeated at most this often by the automatic check (an explicit install runs at once).
const MLAB_SMTP_DB_RETRY_SECONDS = 600;

add_action( 'init', 'mlab_smtp_db_maybe_upgrade', 5 );
add_action( 'wp_initialize_site', 'mlab_smtp_db_new_site', 20 );
add_filter( 'wpmu_drop_tables', 'mlab_smtp_db_drop_tables' );
add_action( 'admin_notices', 'mlab_smtp_db_error_notice' );

/**
 * Status vocabulary of a message.
 *
 * @return string[]
 */
function mlab_smtp_db_statuses(): array {
	return array( 'queued', 'processing', 'accepted', 'delivered', 'temp_failed', 'failed', 'unclear', 'partial', 'blocked' );
}

/**
 * Short names of the four tables.
 *
 * @return string[]
 */
function mlab_smtp_db_table_names(): array {
	return array( 'messages', 'attempts', 'events', 'recipients' );
}

/**
 * Full table name of the current site.
 *
 * @param string $name messages, attempts, events or recipients.
 */
function mlab_smtp_table( string $name ): string {
	global $wpdb;
	return $wpdb->prefix . 'mlab_smtp_' . $name;
}

/**
 * Table names of the current site, as a map short name => full name.
 *
 * @return array<string,string>
 */
function mlab_smtp_db_tables(): array {
	$tables = array();
	foreach ( mlab_smtp_db_table_names() as $name ) {
		$tables[ $name ] = mlab_smtp_table( $name );
	}
	return $tables;
}

/**
 * CREATE TABLE statements in the dbDelta format.
 *
 * @param string $collate Charset and collation clause.
 * @return string[]
 */
function mlab_smtp_db_schema( string $collate ): array {
	$t = mlab_smtp_db_tables();
	return array(
		"CREATE TABLE {$t['messages']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid varchar(36) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'queued',
  mode varchar(10) NOT NULL DEFAULT 'sync',
  route varchar(20) NOT NULL DEFAULT '',
  provider varchar(32) NOT NULL DEFAULT '',
  via varchar(64) NOT NULL DEFAULT '',
  fallback tinyint(1) NOT NULL DEFAULT 0,
  provider_msg_id varchar(191) NOT NULL DEFAULT '',
  recipients text NOT NULL,
  subject text NOT NULL,
  attempts int(10) unsigned NOT NULL DEFAULT 0,
  max_attempts int(10) unsigned NOT NULL DEFAULT 6,
  next_attempt_at bigint(20) unsigned NOT NULL DEFAULT 0,
  lock_token varchar(64) DEFAULT NULL,
  locked_until bigint(20) unsigned DEFAULT NULL,
  error_class varchar(20) NOT NULL DEFAULT '',
  last_error text NOT NULL,
  payload longblob DEFAULT NULL,
  payload_expires_at bigint(20) unsigned DEFAULT NULL,
  created_at bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
  completed_at bigint(20) unsigned DEFAULT NULL,
  legacy_key varchar(64) DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  UNIQUE KEY legacy_key (legacy_key),
  KEY status_next (status,next_attempt_at),
  KEY created_at (created_at),
  KEY provider_msg_id (provider_msg_id)
) $collate;",
		"CREATE TABLE {$t['attempts']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  message_id bigint(20) unsigned NOT NULL,
  route varchar(20) NOT NULL DEFAULT '',
  provider varchar(32) NOT NULL DEFAULT '',
  started_at bigint(20) unsigned NOT NULL DEFAULT 0,
  request_sent_at bigint(20) unsigned DEFAULT NULL,
  finished_at bigint(20) unsigned DEFAULT NULL,
  outcome varchar(12) NOT NULL DEFAULT '',
  http_status smallint(5) unsigned NOT NULL DEFAULT 0,
  error_code varchar(64) NOT NULL DEFAULT '',
  error_text text NOT NULL,
  provider_msg_id varchar(191) NOT NULL DEFAULT '',
  conn_hash varchar(64) NOT NULL DEFAULT '',
  idem_key varchar(64) NOT NULL DEFAULT '',
  mail_message_id varchar(255) NOT NULL DEFAULT '',
  retry_after bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY message_id (message_id)
) $collate;",
		"CREATE TABLE {$t['events']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  message_id bigint(20) unsigned DEFAULT NULL,
  provider varchar(32) NOT NULL DEFAULT '',
  event_id varchar(191) NOT NULL,
  type varchar(64) NOT NULL DEFAULT '',
  occurred_at bigint(20) unsigned NOT NULL DEFAULT 0,
  received_at bigint(20) unsigned NOT NULL DEFAULT 0,
  processed_at bigint(20) unsigned DEFAULT NULL,
  retry_at bigint(20) unsigned NOT NULL DEFAULT 0,
  tries smallint(5) unsigned NOT NULL DEFAULT 0,
  ref_email varchar(100) NOT NULL DEFAULT '',
  ref_uuid char(32) NOT NULL DEFAULT '',
  detail text NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY provider_event (provider,event_id),
  KEY message_id (message_id),
  KEY received_at (received_at),
  KEY due (processed_at,retry_at,id),
  KEY ref_email (ref_email),
  KEY ref_uuid (ref_uuid)
) $collate;",
		"CREATE TABLE {$t['recipients']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  message_id bigint(20) unsigned NOT NULL,
  hmac char(64) NOT NULL,
  PRIMARY KEY  (id),
  KEY hmac (hmac),
  KEY message_id (message_id)
) $collate;",
	);
}

/**
 * Creates or updates the tables of the current site (idempotent) and stores the schema version.
 *
 * @return bool True when all four tables exist afterwards.
 */
function mlab_smtp_db_install(): bool {
	global $wpdb;
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	// Old options first: the shared version option must not skip the 1.0.x migration.
	if ( function_exists( 'mlab_smtp_maybe_migrate' ) ) {
		mlab_smtp_maybe_migrate();
	}
	foreach ( mlab_smtp_db_schema( $wpdb->get_charset_collate() ) as $sql ) {
		dbDelta( $sql );
	}
	$missing = mlab_smtp_db_missing();
	if ( ! $missing ) {
		update_option( 'mlab_smtp_db_version', MLAB_SMTP_SCHEMA_VERSION, false );
		delete_option( MLAB_SMTP_DB_ERROR_OPTION );
		return true;
	}
	// Tables, columns or indexes are missing: the version stays old, so the next run repeats the upgrade (dbDelta is idempotent).
	update_option(
		MLAB_SMTP_DB_ERROR_OPTION,
		array(
			'time'    => time(),
			'missing' => array_slice( $missing, 0, 20 ),
			'error'   => function_exists( 'mlab_smtp_redact' ) ? mlab_smtp_redact( substr( (string) $wpdb->last_error, 0, 300 ) ) : '',
		),
		false
	);
	return false;
}

/**
 * Expected columns and keys, read from the CREATE TABLE statements (one source of truth).
 *
 * @return array<string, array{columns:string[], keys:string[]}> Table => columns and key names.
 */
function mlab_smtp_db_expected(): array {
	$expected = array();
	foreach ( mlab_smtp_db_schema( '' ) as $sql ) {
		if ( ! preg_match( '/CREATE TABLE\s+(\S+)\s*\(/', $sql, $table ) ) {
			continue;
		}
		$entry = array(
			'columns' => array(),
			'keys'    => array(),
		);
		foreach ( explode( "\n", $sql ) as $line ) {
			if ( preg_match( '/^\s+(?:UNIQUE\s+)?KEY\s+(\w+)/i', $line, $key ) ) {
				$entry['keys'][] = $key[1];
			} elseif ( preg_match( '/^\s+(\w+)\s+(?:bigint|int|smallint|tinyint|varchar|char|text|longtext|mediumtext)\b/i', $line, $col ) ) {
				$entry['columns'][] = $col[1];
			}
		}
		$expected[ $table[1] ] = $entry;
	}
	return $expected;
}

/**
 * Tables, columns and keys that the schema requires but the database does not have (checked after install/upgrade only).
 *
 * @return string[] For example «events.processed_at», «events (key due)» or «events (table)».
 */
function mlab_smtp_db_missing(): array {
	global $wpdb;
	$missing = array();
	foreach ( mlab_smtp_db_expected() as $table => $entry ) {
		$short = (string) preg_replace( '/^' . preg_quote( $wpdb->prefix, '/' ) . 'mlab_smtp_/', '', $table );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema check; the table name is validated above.
		// The table name is the prefix plus a fixed name; unquoted because the SQLite driver of the test environment rejects backticks here.
		$columns = preg_match( '/^[A-Za-z0-9_]+$/', $table ) ? $wpdb->get_col( 'SHOW COLUMNS FROM ' . $table, 0 ) : array();
		$keys    = $columns ? $wpdb->get_results( 'SHOW INDEX FROM ' . $table, ARRAY_A ) : array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( ! $columns ) {
			$missing[] = $short . ' (table)';
			continue;
		}
		$have = array_map( 'strtolower', $columns );
		foreach ( $entry['columns'] as $column ) {
			if ( ! in_array( strtolower( $column ), $have, true ) ) {
				$missing[] = $short . '.' . $column;
			}
		}
		$have_keys = array_map( 'strtolower', array_column( (array) $keys, 'Key_name' ) );
		foreach ( $entry['keys'] as $key ) {
			if ( ! in_array( strtolower( $key ), $have_keys, true ) ) {
				$missing[] = $short . ' (key ' . $key . ')';
			}
		}
	}
	return $missing;
}

/**
 * Admin notice while the database upgrade is incomplete: says what is missing and what keeps working.
 */
function mlab_smtp_db_error_notice(): void {
	$error = get_option( MLAB_SMTP_DB_ERROR_OPTION );
	if ( ! is_array( $error ) || ! current_user_can( mlab_smtp_capability() ) ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
		esc_html__( 'Die Datenbank von MomentumLab SMTP ist nicht vollständig aktualisiert.', 'mlab-smtp' ),
		esc_html(
			sprintf(
				/* translators: %s: list of missing database fields */
				__( 'Fehlend: %s. E-Mails werden weiter versendet, aber ohne Protokoll und Warteschlange. Die Aktualisierung wird automatisch wiederholt; hilft das nicht, melden Sie den Fehler dem Support.', 'mlab-smtp' ),
				implode( ', ', array_map( 'strval', (array) ( $error['missing'] ?? array() ) ) )
			)
		)
	);
}

/**
 * True when all four tables exist in the database.
 */
function mlab_smtp_db_tables_exist(): bool {
	global $wpdb;
	foreach ( mlab_smtp_db_tables() as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return false;
		}
	}
	return true;
}

/**
 * Cheap check on init: compares the stored schema version, installs or upgrades only when it is older.
 * Every site of a multisite network runs this for itself on first access.
 */
function mlab_smtp_db_maybe_upgrade(): void {
	if ( version_compare( (string) get_option( 'mlab_smtp_db_version', '0' ), MLAB_SMTP_SCHEMA_VERSION, '>=' ) ) {
		return;
	}
	$error = get_option( MLAB_SMTP_DB_ERROR_OPTION );
	if ( is_array( $error ) && time() - (int) ( $error['time'] ?? 0 ) < MLAB_SMTP_DB_RETRY_SECONDS ) {
		return; // No schema work on every request while the problem persists.
	}
	mlab_smtp_db_install();
}

/**
 * True when the schema version of this site is current (no database access beyond the option).
 */
function mlab_smtp_db_ready(): bool {
	return version_compare( (string) get_option( 'mlab_smtp_db_version', '0' ), MLAB_SMTP_SCHEMA_VERSION, '>=' );
}

/**
 * Plugin activation: creates the tables (on every site when activated for the whole network), then the usual activation.
 *
 * @param bool $network_wide True when activated for the whole network.
 */
function mlab_smtp_db_activate( $network_wide = false ): void {
	if ( $network_wide && is_multisite() ) {
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			mlab_smtp_db_install();
			mlab_smtp_schedule();
			restore_current_blog();
		}
		return;
	}
	mlab_smtp_db_install();
	mlab_smtp_activate();
}

/**
 * Creates the tables for a site created later in a network.
 *
 * @param WP_Site $site New site.
 */
function mlab_smtp_db_new_site( $site ): void {
	if ( ! is_multisite() || ! is_plugin_active_for_network( plugin_basename( MLAB_SMTP_FILE ) ) ) {
		return;
	}
	switch_to_blog( (int) $site->blog_id );
	mlab_smtp_db_install();
	restore_current_blog();
}

/**
 * Adds our tables to the list WordPress drops with a deleted site.
 *
 * @param string[] $tables Table names.
 * @return string[]
 */
function mlab_smtp_db_drop_tables( $tables ): array {
	return array_merge( (array) $tables, array_values( mlab_smtp_db_tables() ) );
}

/**
 * Drops the four tables of the current site.
 */
function mlab_smtp_db_drop(): void {
	global $wpdb;
	foreach ( mlab_smtp_db_tables() as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table names are fixed.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}
}
