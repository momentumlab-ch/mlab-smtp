<?php
/**
 * Log view: filter segments, search, entry list, detail panel, clearing with in-page confirmation.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Filters of the log: label and the store query each one stands for.
 *
 * @return array<string, array{0:string, 1:array}>
 */
function mlab_smtp_log_filters(): array {
	return array(
		'all'       => array( __( 'Alle', 'mlab-smtp' ), array() ),
		'accepted'  => array( __( 'Vom Anbieter angenommen', 'mlab-smtp' ), array( 'status' => array( 'accepted' ) ) ),
		'delivered' => array( __( 'Zustellung bestätigt', 'mlab-smtp' ), array( 'status' => array( 'delivered' ) ) ),
		'open'      => array( __( 'Wartend', 'mlab-smtp' ), array( 'status' => array( 'queued', 'processing', 'temp_failed' ) ) ),
		'unclear'   => array( __( 'Unklar', 'mlab-smtp' ), array( 'status' => array( 'unclear' ) ) ),
		'failed'    => array( __( 'Fehlgeschlagen', 'mlab-smtp' ), array( 'status' => array( 'failed' ) ) ),
		'other'     => array( __( 'Weitere Probleme', 'mlab-smtp' ), array( 'status' => array( 'partial', 'blocked', 'bounced', 'complained' ) ) ),
	);
}

/**
 * Log tab.
 */
function mlab_smtp_render_log(): void {
	$filters = mlab_smtp_log_filters();
	$status  = mlab_smtp_query( 'status', 'all' );
	$status  = isset( $filters[ $status ] ) ? $status : 'all';
	$search  = mlab_smtp_query( 's' );
	echo '<div class="mlab-stack">';
	$problem = mlab_smtp_ui_store_problem();
	if ( $problem ) {
		mlab_smtp_ui_hint( $problem['level'], $problem['title'], $problem['text'] );
	}
	$list    = mlab_smtp_store_list(
		array_merge(
			$filters[ $status ][1],
			array(
				'search'   => $search,
				'page'     => max( 1, absint( mlab_smtp_query( 'paged', '1' ) ) ),
				'per_page' => 20,
			)
		)
	);
	$entries = array_map( 'mlab_smtp_log_entry', $list['items'] );
	$current = mlab_smtp_log_current( $entries );
	mlab_smtp_render_log_toolbar( $status, $search );
	echo '<div class="mlab-two">';
	mlab_smtp_render_log_list( $entries, $current, $status, $search );
	mlab_smtp_render_log_detail(
		$current,
		array_filter(
			array(
				'view'   => 'log',
				'status' => $status,
				's'      => $search,
				'paged'  => (string) $list['page'],
				'entry'  => $current['id'] ?? '',
			)
		)
	);
	echo '</div>';
	mlab_smtp_ui_pagination(
		$list,
		array_filter(
			array(
				'view'   => 'log',
				'status' => $status,
				's'      => $search,
			)
		)
	);
	mlab_smtp_render_log_footer();
	echo '</div>';
}

/**
 * Entry shown in the detail panel: the requested one if it is in the list, otherwise the first.
 *
 * @param array $entries Entries of the current page.
 * @return array|null
 */
function mlab_smtp_log_current( array $entries ): ?array {
	$id = mlab_smtp_query( 'entry' );
	foreach ( $entries as $entry ) {
		if ( $entry['id'] === $id ) {
			return $entry;
		}
	}
	if ( '' !== $id ) {
		$found = mlab_smtp_log_find( $id );
		if ( $found ) {
			return $found;
		}
	}
	return $entries[0] ?? null;
}

/**
 * Filter segments and search field.
 *
 * @param string $status Active filter.
 * @param string $search Search text.
 */
function mlab_smtp_render_log_toolbar( string $status, string $search ): void {
	echo '<div class="mlab-toolbar"><div class="mlab-segmented" role="group" aria-label="' . esc_attr__( 'Filter', 'mlab-smtp' ) . '">';
	foreach ( mlab_smtp_log_filters() as $value => $def ) {
		$url = mlab_smtp_page_url(
			array(
				'view'   => 'log',
				'status' => $value,
				's'      => $search,
			)
		);
		echo '<a class="mlab-seg-item" href="' . esc_url( $url ) . '"' . ( $value === $status ? ' aria-current="true"' : '' ) . '>' . esc_html( $def[0] ) . '</a>';
	}
	echo '</div><form method="get" class="mlab-search" role="search"><input type="hidden" name="page" value="mlab-smtp"><input type="hidden" name="view" value="log"><input type="hidden" name="status" value="' . esc_attr( $status ) . '">';
	echo '<label class="screen-reader-text" for="mlab-log-search">' . esc_html__( 'Empfänger oder Betreff suchen', 'mlab-smtp' ) . '</label>' . mlab_smtp_ui_svg( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
	echo '<input type="search" id="mlab-log-search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Empfänger oder Betreff suchen', 'mlab-smtp' ) . '"></form></div>';
}

/**
 * List of entries as rows. Each row links to its detail panel.
 *
 * @param array      $entries Entries.
 * @param array|null $current Selected entry.
 * @param string     $status  Active filter.
 * @param string     $search  Search text.
 */
function mlab_smtp_render_log_list( array $entries, ?array $current, string $status, string $search ): void {
	echo '<div class="mlab-card mlab-logbox"><ul class="mlab-list">';
	if ( ! $entries ) {
		echo '<li class="mlab-li"><span class="mlab-muted">' . esc_html( '' !== $search || 'all' !== $status ? __( 'Keine Einträge für diesen Filter.', 'mlab-smtp' ) : __( 'Noch keine Nachrichten aufgezeichnet.', 'mlab-smtp' ) ) . '</span></li>';
	}
	foreach ( $entries as $entry ) {
		$url = mlab_smtp_page_url(
			array(
				'view'   => 'log',
				'status' => $status,
				's'      => $search,
				'entry'  => $entry['id'],
			)
		);
		$sel = $current && $current['id'] === $entry['id'];
		echo '<li><a class="mlab-li' . ( $sel ? ' is-selected' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $sel ? ' aria-current="true"' : '' ) . '>';
		mlab_smtp_render_entry_summary( $entry );
		echo '</a></li>';
	}
	echo '</ul></div>';
}

/**
 * Readable name of the route a mail took.
 *
 * @param array $entry Log entry.
 */
function mlab_smtp_log_route_label( array $entry ): string {
	$providers = mlab_smtp_ui_providers();
	$via       = (string) $entry['via'];
	$label     = $providers[ $via ]['name'] ?? $via;
	if ( ! empty( $entry['fallback'] ) ) {
		$label .= ' → ' . __( 'Eigener Mailserver (Ersatzweg)', 'mlab-smtp' );
	}
	return $label;
}

/**
 * Delivery events of a message from the provider, oldest first.
 *
 * @param int $message_id Message id.
 * @return array[]
 */
function mlab_smtp_log_events( int $message_id ): array {
	return (array) mlab_smtp_store_run(
		static function () use ( $message_id ) {
			global $wpdb;
			return $wpdb->get_results( $wpdb->prepare( 'SELECT type, occurred_at FROM %i WHERE message_id = %d ORDER BY occurred_at ASC, id ASC', mlab_smtp_table( 'events' ), $message_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
		},
		array()
	);
}

/**
 * Plain-language name of a provider event.
 *
 * @param string $type Event type, e.g. delivered.
 */
function mlab_smtp_log_event_label( string $type ): string {
	$short  = preg_replace( '/^email\./', '', $type );
	$labels = array(
		'sent'             => __( 'Vom Anbieter versendet', 'mlab-smtp' ),
		'delivered'        => __( 'Zustellung bestätigt', 'mlab-smtp' ),
		'delivery_delayed' => __( 'Zustellung verzögert', 'mlab-smtp' ),
		'bounced'          => __( 'Zurückgewiesen', 'mlab-smtp' ),
		'complained'       => __( 'Als Spam gemeldet', 'mlab-smtp' ),
		'failed'           => __( 'Anbieter meldet Fehler', 'mlab-smtp' ),
	);
	return $labels[ $short ] ?? $short;
}

/**
 * Plain-language outcome of one attempt.
 *
 * @param string $outcome accepted, temporary, permanent or unknown.
 * @return array{0:string, 1:string} Label and level.
 */
function mlab_smtp_log_outcome( string $outcome ): array {
	$map = array(
		'accepted'  => array( __( 'Angenommen', 'mlab-smtp' ), 'ok' ),
		'temporary' => array( __( 'Vorübergehender Fehler', 'mlab-smtp' ), 'warn' ),
		'permanent' => array( __( 'Dauerhafter Fehler', 'mlab-smtp' ), 'bad' ),
		'unknown'   => array( __( 'Ergebnis unklar', 'mlab-smtp' ), 'warn' ),
	);
	return $map[ $outcome ] ?? array( __( 'Offen', 'mlab-smtp' ), 'info' );
}

/**
 * Attempts of a message per route (main route and fallback listed separately).
 *
 * @param int $message_id Message id.
 */
function mlab_smtp_render_log_attempts( int $message_id ): void {
	$attempts = mlab_smtp_store_attempts( $message_id );
	echo '<h3>' . esc_html__( 'Versuche', 'mlab-smtp' ) . '</h3>';
	if ( ! $attempts ) {
		echo '<p class="mlab-muted">' . esc_html__( 'Es wurde noch kein Versuch aufgezeichnet.', 'mlab-smtp' ) . '</p>';
		return;
	}
	$providers = mlab_smtp_ui_providers();
	echo '<ol class="mlab-attempts">';
	foreach ( $attempts as $attempt ) {
		$out   = mlab_smtp_log_outcome( (string) $attempt['outcome'] );
		$route = 'fallback' === $attempt['route'] ? __( 'Ersatzweg', 'mlab-smtp' ) : __( 'Hauptweg', 'mlab-smtp' );
		$name  = $providers[ (string) $attempt['provider'] ]['name'] ?? (string) $attempt['provider'];
		echo '<li><strong>' . esc_html( $route . ': ' . $name ) . '</strong> ';
		mlab_smtp_ui_pill( $out[1], $out[0] );
		$meta = array( wp_date( 'd.m.Y H:i', (int) $attempt['started_at'] ) );
		if ( (int) $attempt['http_status'] > 0 ) {
			/* translators: %d: HTTP status code */
			$meta[] = sprintf( __( 'Antwortcode %d', 'mlab-smtp' ), (int) $attempt['http_status'] );
		}
		echo '<span class="mlab-muted">' . esc_html( implode( ' · ', $meta ) ) . '</span>';
		if ( '' !== (string) $attempt['error_text'] ) {
			$texts = mlab_smtp_ui_error_texts( (string) $attempt['error_text'] );
			echo '<p class="mlab-qerr">' . esc_html( $texts['message'] ) . '</p>';
		}
		echo '</li>';
	}
	echo '</ol>';
}

/**
 * Delivery events of a message.
 *
 * @param int $message_id Message id.
 */
function mlab_smtp_render_log_events( int $message_id ): void {
	$events = mlab_smtp_log_events( $message_id );
	if ( ! $events ) {
		return;
	}
	echo '<h3>' . esc_html__( 'Meldungen des Anbieters', 'mlab-smtp' ) . '</h3><ul class="mlab-events">';
	foreach ( $events as $event ) {
		echo '<li>' . esc_html( mlab_smtp_log_event_label( (string) $event['type'] ) ) . ' <span class="mlab-muted">' . esc_html( wp_date( 'd.m.Y H:i', (int) $event['occurred_at'] ) ) . '</span></li>';
	}
	echo '</ul>';
}

/**
 * Detail panel of one entry: status, attempts per route, provider events, plain-language error, actions.
 *
 * @param array|null $entry Selected entry.
 * @param array      $back  Query arguments to return to after an action.
 */
function mlab_smtp_render_log_detail( ?array $entry, array $back = array( 'view' => 'log' ) ): void {
	echo '<aside class="mlab-card mlab-drawer" aria-label="' . esc_attr__( 'Details', 'mlab-smtp' ) . '">';
	if ( ! $entry ) {
		echo '<p class="mlab-muted">' . esc_html__( 'Wählen Sie einen Eintrag, um Details zu sehen.', 'mlab-smtp' ) . '</p></aside>';
		return;
	}
	echo '<h2>' . esc_html( $entry['subject'] ) . '</h2>';
	mlab_smtp_ui_entry_pill( $entry );
	echo '<p class="mlab-muted">' . esc_html( mlab_smtp_ui_message_status( (string) $entry['status'] )['text'] ) . '</p>';
	echo '<dl class="mlab-dl"><dt>' . esc_html__( 'Zeit', 'mlab-smtp' ) . '</dt><dd>' . esc_html( wp_date( 'd.m.Y H:i', (int) $entry['time'] ) ) . '</dd>';
	echo '<dt>' . esc_html__( 'Empfänger', 'mlab-smtp' ) . '</dt><dd>' . esc_html( $entry['to'] ) . '</dd>';
	echo '<dt>' . esc_html__( 'Versandweg', 'mlab-smtp' ) . '</dt><dd>' . esc_html( mlab_smtp_log_route_label( $entry ) ) . '</dd>';
	echo '<dt>' . esc_html__( 'Kennung', 'mlab-smtp' ) . '</dt><dd>';
	mlab_smtp_ui_copy( (string) $entry['uuid'] );
	echo '</dd></dl>';
	if ( '' !== (string) $entry['error'] ) {
		mlab_smtp_render_log_explanation( $entry );
	}
	mlab_smtp_render_log_attempts( (int) $entry['message_id'] );
	mlab_smtp_render_log_events( (int) $entry['message_id'] );
	$row = mlab_smtp_store_get( (int) $entry['message_id'] );
	if ( $row ) {
		mlab_smtp_render_message_actions( $row, $back );
	}
	echo '<p class="mlab-muted">' . esc_html__( 'Der Inhalt der Mail wird aus Datenschutzgründen nicht im Protokoll gespeichert.', 'mlab-smtp' ) . '</p></aside>';
}

/**
 * Explanation block for an entry that had an error: the shared hint, like the error of the overview.
 *
 * @param array $entry Log entry with an error.
 */
function mlab_smtp_render_log_explanation( array $entry ): void {
	$texts = mlab_smtp_ui_error_texts( (string) $entry['error'] );
	$level = mlab_smtp_ui_entry_level( $entry );
	$fix   = mlab_smtp_wizard_url( 2, (string) mlab_smtp_get( 'provider' ) );
	mlab_smtp_ui_hint(
		'ok' === $level ? 'warn' : $level,
		__( 'Was ist passiert?', 'mlab-smtp' ),
		$texts['message'],
		static function () use ( $entry, $texts, $fix ): void {
			if ( ! empty( $entry['fallback'] ) ) {
				echo '<p>' . esc_html__( 'Der Ersatzweg hat die Mail angenommen.', 'mlab-smtp' ) . '</p>';
			}
			if ( in_array( $entry['status'], array( 'failed', 'temp_failed' ), true ) ) {
				echo '<p><a href="' . esc_url( $fix ) . '">' . esc_html__( 'Fehler beheben', 'mlab-smtp' ) . '</a></p>';
			}
			mlab_smtp_ui_technical( $texts['detail'] );
		}
	);
}

/**
 * Retention note and the clear button with its in-page confirmation.
 */
function mlab_smtp_render_log_footer(): void {
	echo '<div class="mlab-foot"><p class="mlab-muted">';
	/* translators: %d: number of days */
	echo esc_html( sprintf( __( 'Einträge werden nach %d Tagen automatisch gelöscht. Inhalte werden nie gespeichert.', 'mlab-smtp' ), (int) mlab_smtp_get( 'log_retention' ) ) );
	echo '</p>';
	if ( 'clear' === mlab_smtp_query( 'confirm' ) ) {
		mlab_smtp_ui_form_open( 'mlab_smtp_clear_log_confirmed', 'mlab-confirm' );
		echo '<span role="alert">' . esc_html__( 'Alle Einträge löschen?', 'mlab-smtp' ) . '</span> <button type="submit" class="button mlab-danger">' . esc_html__( 'Ja, löschen', 'mlab-smtp' ) . '</button> ';
		echo '<a class="button button-link" href="' . esc_url( mlab_smtp_page_url( array( 'view' => 'log' ) ) ) . '">' . esc_html__( 'Abbrechen', 'mlab-smtp' ) . '</a>';
		mlab_smtp_ui_form_close();
	} else {
		echo '<a class="button" href="' . esc_url(
			mlab_smtp_page_url(
				array(
					'view'    => 'log',
					'confirm' => 'clear',
				)
			)
		) . '">' . esc_html__( 'Protokoll leeren', 'mlab-smtp' ) . '</a>';
	}
	echo '</div>';
}
