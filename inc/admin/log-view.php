<?php
/**
 * Log view: filter chips, search, entry list, detail panel, clearing with in-page confirmation.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Log tab.
 */
function mlab_smtp_render_log(): void {
	$status  = mlab_smtp_query( 'status', 'all' );
	$status  = in_array( $status, array( 'all', 'ok', 'bad' ), true ) ? $status : 'all';
	$search  = mlab_smtp_query( 's' );
	$entries = mlab_smtp_log_get(
		array(
			'status' => $status,
			'search' => $search,
			'limit'  => 200,
		)
	);
	$current = mlab_smtp_log_current( $entries );
	mlab_smtp_render_log_toolbar( $status, $search );
	echo '<div class="mlab-two is-split">';
	mlab_smtp_render_log_table( $entries, $current, $status, $search );
	mlab_smtp_render_log_detail( $current );
	echo '</div>';
	mlab_smtp_render_log_footer();
}

/**
 * Entry shown in the detail panel: the requested one if it is in the list, otherwise the first.
 *
 * @param array $entries Entries of the current filter.
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
 * Filter chips and search field.
 *
 * @param string $status Active filter.
 * @param string $search Search text.
 */
function mlab_smtp_render_log_toolbar( string $status, string $search ): void {
	$chips = array(
		'all' => __( 'Alle', 'mlab-smtp' ),
		'ok'  => __( 'Zugestellt', 'mlab-smtp' ),
		'bad' => __( 'Fehlgeschlagen', 'mlab-smtp' ),
	);
	echo '<div class="mlab-toolbar"><div class="mlab-chips" role="group" aria-label="' . esc_attr__( 'Filter', 'mlab-smtp' ) . '">';
	foreach ( $chips as $value => $label ) {
		$url = mlab_smtp_page_url(
			array(
				'view'   => 'log',
				'status' => $value,
				's'      => $search,
			)
		);
		echo '<a class="mlab-chip" href="' . esc_url( $url ) . '" ' . ( $value === $status ? ' aria-current="true"' : '' ) . '>' . esc_html( $label ) . '</a>';
	}
	echo '</div><form method="get" class="mlab-search" role="search"><input type="hidden" name="page" value="mlab-smtp"><input type="hidden" name="view" value="log"><input type="hidden" name="status" value="' . esc_attr( $status ) . '">';
	echo '<label class="screen-reader-text" for="mlab-log-search">' . esc_html__( 'Empfänger oder Betreff suchen', 'mlab-smtp' ) . '</label>';
	echo '<input type="search" id="mlab-log-search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Empfänger oder Betreff suchen', 'mlab-smtp' ) . '"></form></div>';
}

/**
 * List of entries. Each row links to its detail panel.
 *
 * @param array      $entries Entries.
 * @param array|null $current Selected entry.
 * @param string     $status  Active filter.
 * @param string     $search  Search text.
 */
function mlab_smtp_render_log_table( array $entries, ?array $current, string $status, string $search ): void {
	echo '<div class="mlab-tablewrap"><table class="mlab-table"><thead><tr><th scope="col">' . esc_html__( 'Zeit', 'mlab-smtp' ) . '</th><th scope="col">' . esc_html__( 'Empfänger · Betreff', 'mlab-smtp' ) . '</th><th scope="col">' . esc_html__( 'Ergebnis', 'mlab-smtp' ) . '</th></tr></thead><tbody>';
	if ( ! $entries ) {
		echo '<tr><td colspan="3" class="mlab-muted">' . esc_html__( 'Keine Einträge für diesen Filter.', 'mlab-smtp' ) . '</td></tr>';
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
		echo '<tr' . ( $sel ? ' class="is-selected"' : '' ) . '><td class="mlab-time">' . esc_html( mlab_smtp_ui_time( (int) $entry['time'] ) ) . '</td>';
		echo '<td><a href="' . esc_url( $url ) . '"' . ( $sel ? ' aria-current="true"' : '' ) . '>' . esc_html( $entry['subject'] ) . '</a><div class="mlab-muted mlab-small">' . esc_html( $entry['to'] ) . '</div></td><td>';
		mlab_smtp_ui_entry_pill( $entry );
		echo '</td></tr>';
	}
	echo '</tbody></table></div>';
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
 * Detail panel of one entry with the plain-language explanation of an error.
 *
 * @param array|null $entry Selected entry.
 */
function mlab_smtp_render_log_detail( ?array $entry ): void {
	echo '<aside class="mlab-card mlab-drawer" aria-label="' . esc_attr__( 'Details', 'mlab-smtp' ) . '">';
	if ( ! $entry ) {
		echo '<p class="mlab-muted">' . esc_html__( 'Wählen Sie einen Eintrag, um Details zu sehen.', 'mlab-smtp' ) . '</p></aside>';
		return;
	}
	echo '<h2>' . esc_html( $entry['subject'] ) . '</h2>';
	mlab_smtp_ui_entry_pill( $entry );
	echo '<dl class="mlab-dl"><dt>' . esc_html__( 'Zeit', 'mlab-smtp' ) . '</dt><dd>' . esc_html( wp_date( 'd.m.Y H:i', (int) $entry['time'] ) ) . '</dd>';
	echo '<dt>' . esc_html__( 'Empfänger', 'mlab-smtp' ) . '</dt><dd>' . esc_html( $entry['to'] ) . '</dd>';
	echo '<dt>' . esc_html__( 'Versandweg', 'mlab-smtp' ) . '</dt><dd>' . esc_html( mlab_smtp_log_route_label( $entry ) ) . '</dd></dl>';
	if ( '' !== (string) $entry['error'] ) {
		mlab_smtp_render_log_explanation( $entry );
	} else {
		echo '<p class="mlab-muted mlab-small">' . esc_html__( 'Der Inhalt der Mail wird aus Datenschutzgründen nicht gespeichert.', 'mlab-smtp' ) . '</p>';
	}
	echo '</aside>';
}

/**
 * Explanation block for an entry that had an error.
 *
 * @param array $entry Log entry with an error.
 */
function mlab_smtp_render_log_explanation( array $entry ): void {
	$texts = mlab_smtp_ui_error_texts( (string) $entry['error'] );
	$level = ! empty( $entry['ok'] ) ? 'warn' : 'bad';
	echo '<div class="mlab-hint is-' . esc_attr( $level ) . '"><div><strong>' . esc_html__( 'Was ist passiert?', 'mlab-smtp' ) . '</strong><p>' . esc_html( $texts['message'] ) . '</p>';
	if ( ! empty( $entry['fallback'] ) ) {
		echo '<p>' . esc_html__( 'Die Mail wurde über den Ersatzweg zugestellt.', 'mlab-smtp' ) . '</p>';
	}
	mlab_smtp_ui_technical( $texts['detail'] );
	echo '</div></div>';
}

/**
 * Retention note and the clear button with its in-page confirmation.
 */
function mlab_smtp_render_log_footer(): void {
	echo '<div class="mlab-foot"><p class="mlab-muted mlab-small">';
	/* translators: %d: number of days */
	echo esc_html( sprintf( __( 'Einträge werden nach %d Tagen automatisch gelöscht. Inhalte werden nie gespeichert.', 'mlab-smtp' ), (int) mlab_smtp_get( 'log_retention' ) ) );
	echo '</p>';
	if ( 'clear' === mlab_smtp_query( 'confirm' ) ) {
		mlab_smtp_ui_form_open( 'mlab_smtp_clear_log_confirmed', 'mlab-confirm' );
		echo '<span role="alert">' . esc_html__( 'Alle Einträge löschen?', 'mlab-smtp' ) . '</span> <button type="submit" class="button mlab-danger">' . esc_html__( 'Ja, löschen', 'mlab-smtp' ) . '</button> ';
		echo '<a class="button-link" href="' . esc_url( mlab_smtp_page_url( array( 'view' => 'log' ) ) ) . '">' . esc_html__( 'Abbrechen', 'mlab-smtp' ) . '</a>';
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
