<?php
/**
 * Queue tab: open, unclear and failed messages with filters, paging and manual actions that work without JavaScript.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;


/**
 * Problem with the storage of the log and queue that every list has to name: missing tables or an unfinished migration.
 *
 * @return array{level:string, title:string, text:string}|null Null when everything is in order.
 */
function mlab_smtp_ui_store_problem(): ?array {
	if ( ! mlab_smtp_engine_store_ready() || ! mlab_smtp_db_tables_exist() ) {
		return array(
			'level' => 'bad',
			'title' => __( 'Protokoll und Warteschlange sind nicht verfügbar', 'mlab-smtp' ),
			'text'  => __( 'Die Datenbanktabellen des Plugins fehlen. Deaktivieren und aktivieren Sie das Plugin erneut, damit sie angelegt werden. Mails werden weiterhin gesendet, aber nicht aufgezeichnet.', 'mlab-smtp' ),
		);
	}
	$migration = mlab_smtp_migration_state();
	if ( (int) $migration['failed'] > 0 || (int) $migration['stuck'] > 0 || '' !== (string) $migration['last_error'] ) {
		return array(
			'level' => 'warn',
			'title' => __( 'Das alte Protokoll konnte nicht vollständig übernommen werden', 'mlab-smtp' ),
			'text'  => __( 'Einzelne frühere Einträge fehlen in der Liste. Die Übernahme wird täglich wiederholt, die alten Daten bleiben bis dahin erhalten.', 'mlab-smtp' ),
		);
	}
	if ( ! $migration['done'] && (int) $migration['total'] > 0 ) {
		return array(
			'level' => 'info',
			/* translators: 1: migrated entries, 2: total entries */
			'title' => sprintf( __( 'Das alte Protokoll wird übernommen (%1$d von %2$d)', 'mlab-smtp' ), (int) $migration['migrated'], (int) $migration['total'] ),
			'text'  => __( 'Die Übernahme läuft im Hintergrund. Ältere Einträge erscheinen nach und nach in der Liste.', 'mlab-smtp' ),
		);
	}
	return null;
}

/**
 * Filters of the queue tab: label and the statuses each one shows.
 *
 * @return array<string, array{0:string, 1:string[]}>
 */
function mlab_smtp_queue_filters(): array {
	return array(
		'waiting' => array( __( 'Wartend', 'mlab-smtp' ), array( 'queued' ) ),
		'running' => array( __( 'In Bearbeitung / Wiederholung', 'mlab-smtp' ), array( 'processing', 'temp_failed' ) ),
		'unclear' => array( __( 'Unklar', 'mlab-smtp' ), array( 'unclear' ) ),
		'failed'  => array( __( 'Endgültig fehlgeschlagen', 'mlab-smtp' ), array( 'failed' ) ),
		'all'     => array( __( 'Alle', 'mlab-smtp' ), array( 'queued', 'processing', 'temp_failed', 'unclear', 'failed' ) ),
	);
}

/**
 * Number of messages that need attention: open, unclear and failed. Shown as badge on the tab.
 */
function mlab_smtp_queue_badge_count(): int {
	if ( ! mlab_smtp_engine_store_ready() ) {
		return 0;
	}
	$counts = mlab_smtp_store_counts();
	return array_sum( array_map( static fn( string $s ): int => (int) ( $counts[ $s ] ?? 0 ), mlab_smtp_queue_filters()['all'][1] ) );
}

/**
 * Queue tab.
 */
function mlab_smtp_render_queue(): void {
	if ( ! current_user_can( mlab_smtp_capability() ) ) {
		return;
	}
	echo '<div class="mlab-stack">';
	$problem = mlab_smtp_ui_store_problem();
	if ( $problem && 'bad' === $problem['level'] ) {
		mlab_smtp_ui_hint( $problem['level'], $problem['title'], $problem['text'] );
		echo '</div>';
		return;
	}
	if ( $problem ) {
		mlab_smtp_ui_hint( $problem['level'], $problem['title'], $problem['text'] );
	}
	$filters = mlab_smtp_queue_filters();
	$filter  = mlab_smtp_query( 'filter', 'all' );
	$filter  = isset( $filters[ $filter ] ) ? $filter : 'all';
	$summary = mlab_smtp_engine_summary();
	mlab_smtp_render_queue_notes( $summary, $filter );
	mlab_smtp_render_queue_toolbar( $filter );
	$result = mlab_smtp_store_list(
		array(
			'status'   => $filters[ $filter ][1],
			'page'     => max( 1, absint( mlab_smtp_query( 'paged', '1' ) ) ),
			'per_page' => 20,
		)
	);
	mlab_smtp_render_queue_list( $result, $filter );
	mlab_smtp_ui_pagination(
		$result,
		array(
			'view'   => 'queue',
			'filter' => $filter,
		)
	);
	echo '</div>';
}

/**
 * Notes above the list: delivery mode, stale queue with cron advice, explanation of «unklar».
 *
 * @param array  $summary Result of mlab_smtp_engine_summary().
 * @param string $filter  Active filter.
 */
function mlab_smtp_render_queue_notes( array $summary, string $filter ): void {
	echo '<p class="mlab-muted">' . esc_html( mlab_smtp_ui_mode_text( $summary ) ) . '</p>';
	if ( $summary['stale'] ) {
		mlab_smtp_ui_hint(
			'warn',
			__( 'Die Warteschlange läuft nicht regelmässig', 'mlab-smtp' ),
			sprintf(
				/* translators: %d: minutes */
				__( 'Die älteste fällige Mail wartet seit %d Minuten. ', 'mlab-smtp' ),
				(int) floor( $summary['oldest_wait'] / MINUTE_IN_SECONDS )
			) . $summary['cron_text'],
			'mlab_smtp_render_cron_help'
		);
	}
	if ( $summary['unclear'] > 0 || 'unclear' === $filter ) {
		mlab_smtp_render_unclear_help( 'unclear' === $filter );
	}
}

/**
 * Delivery mode in words.
 *
 * @param array $summary Result of mlab_smtp_engine_summary().
 */
function mlab_smtp_ui_mode_text( array $summary ): string {
	if ( 'queue' === $summary['mode'] ) {
		return __( 'Versandart: Warteschlange. Mails werden gespeichert und im Hintergrund gesendet.', 'mlab-smtp' );
	}
	if ( $summary['retry_in_background'] ) {
		return __( 'Versandart: sofort senden. Bei einem vorübergehenden Fehler wird im Hintergrund wiederholt.', 'mlab-smtp' );
	}
	return __( 'Versandart: sofort senden. Mails kommen nur dann in die Warteschlange, wenn Sie das einstellen.', 'mlab-smtp' );
}

/**
 * Short instructions for a system cron, inside the plugin so no external page is needed.
 */
function mlab_smtp_render_cron_help(): void {
	echo '<details class="mlab-technical"><summary>' . esc_html__( 'So richten Sie einen System-Cron ein', 'mlab-smtp' ) . '</summary><ol>';
	echo '<li>' . esc_html__( 'Fügen Sie in der Datei wp-config.php die Zeile define( \'DISABLE_WP_CRON\', true ); ein.', 'mlab-smtp' ) . '</li>';
	echo '<li>' . esc_html__( 'Lassen Sie den Server (Cron-Dienst Ihres Hostings) jede Minute die Adresse Ihrer Website mit /wp-cron.php?doing_wp_cron aufrufen oder «wp cron event run --due-now» ausführen.', 'mlab-smtp' ) . '</li>';
	echo '<li>' . esc_html__( 'Laden Sie diese Seite nach etwa fünf Minuten neu. Die Warnung verschwindet, sobald die Warteschlange abgearbeitet wird.', 'mlab-smtp' ) . '</li></ol></details>';
}

/**
 * Explanation of the status «unklar»: meaning, why nothing is resent by itself, what to do.
 *
 * @param bool $open Whether the panel starts open.
 */
function mlab_smtp_render_unclear_help( bool $open ): void {
	echo '<details class="mlab-card mlab-help"' . ( $open ? ' open' : '' ) . '><summary><strong>' . esc_html__( 'Was bedeutet «Versandstatus unklar»?', 'mlab-smtp' ) . '</strong></summary>';
	echo '<p>' . esc_html__( 'Die Verbindung brach ab oder lief in eine Zeitüberschreitung, nachdem die Mail an den Anbieter gesendet wurde. Der Anbieter hat sie möglicherweise angenommen, wir wissen es nicht.', 'mlab-smtp' ) . '</p>';
	echo '<p>' . esc_html__( 'Das Plugin sendet solche Mails nicht von selbst erneut, weil der Empfänger sie sonst doppelt erhalten könnte.', 'mlab-smtp' ) . '</p>';
	echo '<p>' . esc_html__( 'Was tun? Prüfen Sie beim Empfänger oder im Konto Ihres Anbieters, ob die Mail angekommen ist. Wenn ja, wählen Sie «Als zugestellt markieren». Wenn nein, wählen Sie «Erneut senden». Dabei kann die Mail doppelt ankommen.', 'mlab-smtp' ) . '</p></details>';
}

/**
 * Filter segments with the number of messages in each.
 *
 * @param string $filter Active filter.
 */
function mlab_smtp_render_queue_toolbar( string $filter ): void {
	$counts = mlab_smtp_store_counts();
	echo '<div class="mlab-toolbar"><div class="mlab-segmented" role="group" aria-label="' . esc_attr__( 'Filter', 'mlab-smtp' ) . '">';
	foreach ( mlab_smtp_queue_filters() as $slug => $def ) {
		$n   = array_sum( array_map( static fn( string $s ): int => (int) ( $counts[ $s ] ?? 0 ), $def[1] ) );
		$url = mlab_smtp_page_url(
			array(
				'view'   => 'queue',
				'filter' => $slug,
			)
		);
		echo '<a class="mlab-seg-item" href="' . esc_url( $url ) . '"' . ( $slug === $filter ? ' aria-current="true"' : '' ) . '>' . esc_html( $def[0] ) . ' <span class="mlab-seg-n">' . esc_html( (string) $n ) . '</span></a>';
	}
	echo '</div></div>';
}

/**
 * The list of messages, or an explicit empty state.
 *
 * @param array  $result Result of mlab_smtp_store_list().
 * @param string $filter Active filter.
 */
function mlab_smtp_render_queue_list( array $result, string $filter ): void {
	echo '<section class="mlab-card mlab-queue" aria-label="' . esc_attr__( 'Nachrichten', 'mlab-smtp' ) . '"><ul class="mlab-list">';
	if ( ! $result['items'] ) {
		echo '<li class="mlab-li mlab-empty"><span class="mlab-ic is-ok" aria-hidden="true">✓</span><span class="mlab-muted">' . esc_html__( 'Nichts zu tun: In dieser Ansicht gibt es keine Mails.', 'mlab-smtp' ) . '</span></li>';
	}
	foreach ( $result['items'] as $row ) {
		echo '<li class="mlab-qrow">';
		mlab_smtp_render_queue_row(
			$row,
			array(
				'view'   => 'queue',
				'filter' => $filter,
				'paged'  => (string) $result['page'],
			)
		);
		echo '</li>';
	}
	echo '</ul></section>';
}

/**
 * Label of the way a message took: route and provider.
 *
 * @param array $row Store row.
 */
function mlab_smtp_queue_route_label( array $row ): string {
	$route = 'fallback' === $row['route'] ? __( 'Ersatzweg', 'mlab-smtp' ) : __( 'Hauptweg', 'mlab-smtp' );
	$name  = mlab_smtp_ui_providers()[ (string) $row['provider'] ]['name'] ?? (string) ( '' !== (string) $row['via'] ? $row['via'] : $row['provider'] );
	return '' !== $name ? $route . ': ' . $name : $route;
}

/**
 * Line with attempts and the time of the next or the last attempt.
 *
 * @param array $row Store row.
 */
function mlab_smtp_queue_attempt_text( array $row ): string {
	/* translators: 1: attempts made, 2: maximum attempts */
	$text = sprintf( __( 'Versuch %1$d von %2$d', 'mlab-smtp' ), (int) $row['attempts'], max( (int) $row['max_attempts'], (int) $row['attempts'] ) );
	if ( in_array( $row['status'], array( 'queued', 'temp_failed' ), true ) ) {
		$next  = (int) $row['next_attempt_at'];
		$text .= ' · ' . ( $next <= time() ? __( 'wird gleich versucht', 'mlab-smtp' ) : sprintf(
			/* translators: %s: time */
			__( 'nächster Versuch %s', 'mlab-smtp' ),
			mlab_smtp_ui_time( $next )
		) );
	} elseif ( (int) $row['updated_at'] > 0 ) {
		/* translators: %s: time */
		$text .= ' · ' . sprintf( __( 'letzter Versuch %s', 'mlab-smtp' ), mlab_smtp_ui_time( (int) $row['updated_at'] ) );
	}
	return $text;
}

/**
 * One message of the queue: recipients, subject, status, attempts, error and actions.
 *
 * @param array $row  Store row.
 * @param array $back Query arguments of the page to return to after an action.
 */
function mlab_smtp_render_queue_row( array $row, array $back ): void {
	$info = mlab_smtp_ui_message_status( (string) $row['status'] );
	echo '<div class="mlab-qmain"><span class="mlab-ic is-' . esc_attr( $info['level'] ) . '" aria-hidden="true">' . esc_html( $info['icon'] ) . '</span>';
	echo '<div class="mlab-li-main"><strong>' . esc_html( (string) $row['subject'] ) . '</strong><span class="mlab-muted">' . esc_html( (string) $row['recipients'] ) . '</span>';
	echo '<span class="mlab-muted">' . esc_html( mlab_smtp_queue_attempt_text( $row ) . ' · ' . mlab_smtp_queue_route_label( $row ) ) . '</span></div>';
	mlab_smtp_ui_status_pill( (string) $row['status'] );
	echo '</div>';
	if ( '' !== (string) $row['last_error'] ) {
		$texts = mlab_smtp_ui_error_texts( (string) $row['last_error'] );
		echo '<p class="mlab-qerr">' . esc_html( $texts['message'] ) . '</p>';
		mlab_smtp_ui_technical( $texts['detail'] );
	}
	mlab_smtp_render_message_actions( $row, $back );
}

/**
 * Manual actions of a message, only those its status allows. Each one is a form with nonce, so no JavaScript is needed.
 *
 * @param array $row  Store row.
 * @param array $back Query arguments of the page to return to (view plus filter, paged, status, s, entry).
 */
function mlab_smtp_render_message_actions( array $row, array $back ): void {
	if ( ! current_user_can( mlab_smtp_capability() ) || ! in_array( $row['status'], array( 'failed', 'unclear' ), true ) ) {
		return;
	}
	$id = (int) $row['id'];
	echo '<div class="mlab-qacts">';
	if ( 'failed' === $row['status'] ) {
		if ( ! empty( $row['has_payload'] ) ) {
			mlab_smtp_render_action_form( $id, 'retry', __( 'Erneut versuchen', 'mlab-smtp' ), $back, true );
		} else {
			echo '<p class="mlab-muted">' . esc_html__( 'Erneut versuchen ist nicht möglich: Der Inhalt der Mail wird nicht mehr aufbewahrt. Lösen Sie den Versand in der Anwendung neu aus.', 'mlab-smtp' ) . '</p>';
		}
	} else {
		mlab_smtp_render_action_form( $id, 'delivered', __( 'Als zugestellt markieren', 'mlab-smtp' ), $back, true );
		mlab_smtp_render_resend( $row, $back );
	}
	echo '</div>';
}

/**
 * «Erneut senden» for an unclear message with the confirmation step in plain text.
 *
 * @param array $row  Store row.
 * @param array $back Return arguments.
 */
function mlab_smtp_render_resend( array $row, array $back ): void {
	$id = (int) $row['id'];
	if ( empty( $row['has_payload'] ) ) {
		echo '<p class="mlab-muted">' . esc_html__( 'Erneut senden ist nicht möglich: Der Inhalt der Mail wird nicht mehr aufbewahrt.', 'mlab-smtp' ) . '</p>';
		return;
	}
	if ( 'resend' !== mlab_smtp_query( 'confirm' ) || mlab_smtp_query( 'id' ) !== (string) $id ) {
		$url = mlab_smtp_page_url(
			array_merge(
				$back,
				array(
					'confirm' => 'resend',
					'id'      => $id,
				)
			)
		);
		echo '<a class="button" href="' . esc_url( $url ) . '#mlab-confirm-' . esc_attr( (string) $id ) . '">' . esc_html__( 'Erneut senden (Doppelversand möglich)', 'mlab-smtp' ) . '</a>';
		return;
	}
	echo '<div class="mlab-confirm" id="mlab-confirm-' . esc_attr( (string) $id ) . '" role="alert"><p>' . esc_html__( 'Möglicherweise hat der Empfänger diese Mail schon erhalten. Wenn Sie erneut senden, kann sie doppelt ankommen. Wirklich erneut senden?', 'mlab-smtp' ) . '</p>';
	mlab_smtp_render_action_form( $id, 'resend', __( 'Ja, erneut senden', 'mlab-smtp' ), $back, false );
	echo '<a class="button button-link" href="' . esc_url( mlab_smtp_page_url( $back ) ) . '">' . esc_html__( 'Abbrechen', 'mlab-smtp' ) . '</a></div>';
}

/**
 * One action as a small form: action, nonce, message id and the page to return to.
 *
 * @param int    $id      Message id.
 * @param string $operation      retry, resend or delivered.
 * @param string $label   Button text.
 * @param array  $back    Return arguments.
 * @param bool   $plain   True for a plain button, false for the highlighted one.
 */
function mlab_smtp_render_action_form( int $id, string $operation, string $label, array $back, bool $plain ): void {
	// The nonce belongs to this action on this message: a nonce for «retry» of message 5 does not work for anything else.
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mlab-inline-form"><input type="hidden" name="action" value="mlab_smtp_message_action">';
	wp_nonce_field( mlab_smtp_message_nonce_action( $id, $operation ) );
	echo '<input type="hidden" name="do" value="' . esc_attr( $operation ) . '"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
	foreach ( $back as $key => $value ) {
		echo '<input type="hidden" name="back[' . esc_attr( (string) $key ) . ']" value="' . esc_attr( (string) $value ) . '">';
	}
	echo '<button type="submit" class="button' . ( $plain ? '' : ' button-primary' ) . '">' . esc_html( $label ) . '</button>';
	mlab_smtp_ui_form_close();
}

/**
 * Reads the page to return to after an action. Only known view keys with sanitized values are accepted.
 *
 * @return array<string,string>
 */
function mlab_smtp_queue_back_args(): array {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in mlab_smtp_guard(), values sanitized below.
	$raw  = isset( $_REQUEST['back'] ) && is_array( $_REQUEST['back'] ) ? wp_unslash( $_REQUEST['back'] ) : array();
	$args = array();
	foreach ( array( 'view', 'filter', 'paged', 'status', 's', 'entry' ) as $key ) {
		if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) && '' !== (string) $raw[ $key ] ) {
			$args[ $key ] = sanitize_text_field( (string) $raw[ $key ] );
		}
	}
	$args['view'] = in_array( $args['view'] ?? '', array( 'queue', 'log' ), true ) ? $args['view'] : 'queue';
	return $args;
}

/**
 * Plain-language result of an action.
 *
 * @param string $operation Action name.
 */
function mlab_smtp_queue_done_text( string $operation ): string {
	$texts = array(
		'retry'     => __( 'Die Mail wurde zum erneuten Versuch eingereiht.', 'mlab-smtp' ),
		'resend'    => __( 'Die Mail wurde erneut eingereiht. Sie kann beim Empfänger doppelt ankommen.', 'mlab-smtp' ),
		'delivered' => __( 'Die Mail ist als zugestellt markiert. Der Anbieter hat sie nicht bestätigt, Sie haben das selbst festgehalten.', 'mlab-smtp' ),
	);
	return $texts[ $operation ] ?? __( 'Die Aktion wurde ausgeführt.', 'mlab-smtp' );
}
