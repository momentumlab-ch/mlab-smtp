<?php
/**
 * Overview view: status banner, set-up steps, key figures, latest mails, active connection, health checks, quick test mail.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Overview tab: answer, to-do list, the three cards, key figures, latest mails, health.
 */
function mlab_smtp_render_overview(): void {
	$status = mlab_smtp_status();
	echo '<div class="mlab-stack">';
	mlab_smtp_staging_banner( true );
	if ( 'none' === $status['state'] ) {
		mlab_smtp_render_onboarding( $status );
		echo '</div>';
		return;
	}
	$problem = mlab_smtp_ui_store_problem();
	$engine  = $problem && 'bad' === $problem['level'] ? null : mlab_smtp_health_engine( true );
	mlab_smtp_render_status_card( $status, $engine );
	if ( $problem ) {
		mlab_smtp_ui_hint( $problem['level'], $problem['title'], $problem['text'] );
	}
	mlab_smtp_render_todo( mlab_smtp_overview_todos( $status, $engine ) );
	mlab_smtp_render_quick_test();
	mlab_smtp_render_steps();
	mlab_smtp_render_cards( $engine );
	mlab_smtp_render_figures( $engine );
	mlab_smtp_render_health();
	echo '</div>';
}

/**
 * Key figures and latest mails, or the explicit reason why there is no data.
 *
 * @param array|null $engine Engine summary, null when the tables are missing.
 */
function mlab_smtp_render_figures( ?array $engine ): void {
	if ( null === $engine ) {
		mlab_smtp_ui_hint( 'info', __( 'Keine Zahlen verfügbar', 'mlab-smtp' ), __( 'Solange Protokoll und Warteschlange nicht verfügbar sind, kann die Übersicht keine Mails zählen.', 'mlab-smtp' ) );
		return;
	}
	mlab_smtp_render_kpis( mlab_smtp_stats() );
	mlab_smtp_render_recent();
}

/**
 * Primary action of the page header: opens the test mail form. Only shown once the setup exists.
 */
function mlab_smtp_render_head_action(): void {
	if ( 'none' === mlab_smtp_status()['state'] ) {
		return;
	}
	echo '<a class="button button-primary mlab-head-act" href="#mlab-quick">' . mlab_smtp_ui_svg( 'send' ) . esc_html__( 'Testmail senden', 'mlab-smtp' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
}

/**
 * Full-width status banner with one action. It is the shared hint in its large form.
 *
 * @param string $level    ok, warn, bad or info.
 * @param string $title    Bold line.
 * @param string $text     Short explanation.
 * @param string $label    Label of the action.
 * @param string $url      Target of the action.
 * @param bool   $primary  Whether the action is the primary button of the screen.
 * @param string $question Small question above the title, may be empty.
 */
function mlab_smtp_render_banner( string $level, string $title, string $text, string $label, string $url, bool $primary = false, string $question = '' ): void {
	echo '<div class="mlab-hint is-banner is-' . esc_attr( $level ) . '"><span class="mlab-ic is-' . esc_attr( $level ) . '" aria-hidden="true">' . esc_html( mlab_smtp_ui_icon( $level ) ) . '</span>';
	echo '<div class="mlab-hint-body">' . ( '' !== $question ? '<h2 class="mlab-kicker">' . esc_html( $question ) . '</h2>' : '' ) . '<strong>' . esc_html( $title ) . '</strong><p>' . esc_html( $text ) . '</p></div>';
	echo '<a class="button' . ( $primary ? ' button-primary' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></div>';
}

/**
 * Card for a site that is not set up yet, with the four steps ahead.
 *
 * @param array $status Result of mlab_smtp_status().
 */
function mlab_smtp_render_onboarding( array $status ): void {
	mlab_smtp_render_banner( 'info', $status['title'], $status['text'], __( 'Einrichtung starten', 'mlab-smtp' ), mlab_smtp_page_url( array( 'view' => 'wizard' ) ), true );
	$steps = array(
		array( __( 'Mailanbieter wählen', 'mlab-smtp' ), __( 'Wir schlagen den passenden anhand Ihrer Domain vor.', 'mlab-smtp' ) ),
		array( __( 'Verbinden', 'mlab-smtp' ), __( 'Zugangsdaten eingeben oder mit dem Konto anmelden. Wir prüfen die Verbindung sofort.', 'mlab-smtp' ) ),
		array( __( 'Absender festlegen', 'mlab-smtp' ), __( 'Ist bereits ausgefüllt.', 'mlab-smtp' ) ),
		array( __( 'Testmail senden', 'mlab-smtp' ), __( 'Erst danach ist die Einrichtung abgeschlossen.', 'mlab-smtp' ) ),
	);
	echo '<section class="mlab-card"><h2>' . esc_html__( 'So geht es weiter', 'mlab-smtp' ) . '</h2><p class="mlab-muted">' . esc_html__( 'Dauert etwa 3 Minuten. Sie brauchen den Zugang zum Mailkonto Ihrer Firma.', 'mlab-smtp' ) . '</p><ol class="mlab-todo">';
	foreach ( $steps as $step ) {
		echo '<li><div><h3>' . esc_html( $step[0] ) . '</h3><p class="mlab-muted">' . esc_html( $step[1] ) . '</p></div></li>';
	}
	echo '</ol></section>';
}

/**
 * One entry of the answer banner or the to-do list.
 *
 * @param string $level ok, warn, bad or info.
 * @param string $title Bold line.
 * @param string $text  Explanation.
 * @param string $label Button text.
 * @param string $url   Button target.
 * @return array{level:string, title:string, text:string, label:string, url:string}
 */
function mlab_smtp_overview_item( string $level, string $title, string $text, string $label, string $url ): array {
	return array(
		'level' => $level,
		'title' => $title,
		'text'  => $text,
		'label' => $label,
		'url'   => $url,
	);
}

/**
 * Answer to «Funktioniert der Versand?»: level, title, text and the one next action.
 *
 * @param array      $status Result of mlab_smtp_status().
 * @param array|null $engine Engine summary or null.
 * @return array{level:string, title:string, text:string, label:string, url:string}
 */
function mlab_smtp_overview_answer( array $status, ?array $engine ): array {
	$fix   = mlab_smtp_wizard_url( 2, (string) mlab_smtp_get( 'provider' ) );
	$queue = mlab_smtp_page_url( array( 'view' => 'queue' ) );
	$stage = mlab_smtp_staging_status();
	if ( 'block' === $stage['effective'] ) {
		return mlab_smtp_overview_item( 'bad', __( 'Nein, der Versand ist blockiert', 'mlab-smtp' ), $stage['text'], __( 'Einstellungen öffnen', 'mlab-smtp' ), mlab_smtp_page_url( array( 'view' => 'settings' ) ) );
	}
	if ( 'bad' === $status['state'] ) {
		return mlab_smtp_overview_item( 'bad', __( 'Nein, Mails werden nicht gesendet', 'mlab-smtp' ), $status['text'], __( 'Fehler beheben', 'mlab-smtp' ), $fix );
	}
	if ( 'warn' === $status['state'] ) {
		return mlab_smtp_overview_item( 'warn', __( 'Eingeschränkt: Der Ersatzweg übernimmt', 'mlab-smtp' ), $status['text'], __( 'Fehler beheben', 'mlab-smtp' ), $fix );
	}
	if ( $engine && ( $engine['failed'] > 0 || $engine['unclear'] > 0 || $engine['stale'] ) ) {
		return mlab_smtp_overview_item( 'warn', __( 'Ja, aber einige Mails brauchen Ihre Aufmerksamkeit', 'mlab-smtp' ), __( 'Der Versand läuft, doch es gibt Mails, die warten, fehlgeschlagen oder unklar sind.', 'mlab-smtp' ), __( 'Wartende E-Mails ansehen', 'mlab-smtp' ), $queue );
	}
	if ( ! mlab_smtp_state_get()['last_success'] ) {
		return mlab_smtp_overview_item( 'info', __( 'Noch nicht erprobt', 'mlab-smtp' ), $status['text'], __( 'Testmail senden', 'mlab-smtp' ), '#mlab-quick' );
	}
	return mlab_smtp_overview_item( 'ok', __( 'Ja, der Anbieter nimmt Ihre Mails an', 'mlab-smtp' ), mlab_smtp_route_summary() . '. ' . __( 'Angenommen heisst: Der Anbieter hat die Mail übernommen. Zugestellt ist sie erst mit einem Zustellbeleg.', 'mlab-smtp' ), __( 'Verbindung prüfen', 'mlab-smtp' ), $fix );
}

/**
 * Banner with the overall state of sending.
 *
 * @param array      $status Result of mlab_smtp_status().
 * @param array|null $engine Engine summary or null.
 */
function mlab_smtp_render_status_card( array $status, ?array $engine = null ): void {
	$a = mlab_smtp_overview_answer( $status, $engine );
	mlab_smtp_render_banner( $a['level'], $a['title'], $a['text'], $a['label'], $a['url'], false, __( 'Funktioniert der Versand?', 'mlab-smtp' ) );
}

/**
 * Short prioritised list of what to do, at most four items, each with one button.
 *
 * @param array      $status Result of mlab_smtp_status().
 * @param array|null $engine Engine summary or null.
 * @return array<int, array{level:string, title:string, text:string, label:string, url:string}>
 */
function mlab_smtp_overview_todos( array $status, ?array $engine ): array {
	$items = array();
	$add   = static function ( string $level, string $title, string $text, string $label, string $url ) use ( &$items ): void {
		$items[] = mlab_smtp_overview_item( $level, $title, $text, $label, $url );
	};
	$queue = static fn( string $filter ): string => mlab_smtp_page_url(
		array(
			'view'   => 'queue',
			'filter' => $filter,
		)
	);
	if ( 'bad' === $status['state'] || 'warn' === $status['state'] ) {
		$add( 'bad' === $status['state'] ? 'bad' : 'warn', __( 'Der Hauptweg hat ein Problem', 'mlab-smtp' ), $status['text'], __( 'Fehler beheben', 'mlab-smtp' ), mlab_smtp_wizard_url( 2, (string) mlab_smtp_get( 'provider' ) ) );
	}
	if ( $engine && $engine['failed'] > 0 ) {
		/* translators: %d: number of messages */
		$add( 'bad', sprintf( _n( '%d Mail ist endgültig fehlgeschlagen', '%d Mails sind endgültig fehlgeschlagen', $engine['failed'], 'mlab-smtp' ), $engine['failed'] ), __( 'Diese Mails wurden nicht gesendet. Sie können sie erneut versuchen, solange der Inhalt aufbewahrt wird.', 'mlab-smtp' ), __( 'Fehlgeschlagene ansehen', 'mlab-smtp' ), $queue( 'failed' ) );
	}
	if ( $engine && $engine['unclear'] > 0 ) {
		/* translators: %d: number of messages */
		$add( 'warn', sprintf( _n( '%d Mail hat einen unklaren Versandstatus', '%d Mails haben einen unklaren Versandstatus', $engine['unclear'], 'mlab-smtp' ), $engine['unclear'] ), __( 'Der Anbieter hat sie möglicherweise angenommen. Bitte entscheiden Sie, ob sie erneut gesendet werden soll.', 'mlab-smtp' ), __( 'Unklare Mails ansehen', 'mlab-smtp' ), $queue( 'unclear' ) );
	}
	if ( $engine && $engine['stale'] ) {
		$add( 'warn', __( 'Wartende E-Mails werden nicht abgearbeitet', 'mlab-smtp' ), $engine['cron_text'], __( 'Wartende E-Mails ansehen', 'mlab-smtp' ), $queue( 'all' ) );
	}
	$items = array_merge( $items, mlab_smtp_overview_route_todos() );
	return array_slice( $items, 0, 4 );
}

/**
 * To-do items about the readiness of the two routes.
 *
 * @return array<int, array{level:string, title:string, text:string, label:string, url:string}>
 */
function mlab_smtp_overview_route_todos(): array {
	$items    = array();
	$primary  = mlab_smtp_route_state( 'primary' );
	$fallback = mlab_smtp_fallback_tested_answer();
	if ( ! $primary['getestet'] ) {
		$items[] = array(
			'level' => 'warn',
			'title' => __( 'Der Hauptweg ist noch nicht getestet', 'mlab-smtp' ),
			'text'  => $primary['text'],
			'label' => __( 'Verbindung prüfen', 'mlab-smtp' ),
			'url'   => mlab_smtp_wizard_url( 2, (string) mlab_smtp_get( 'provider' ) ),
		);
	}
	if ( ! $fallback['tested'] || ! $fallback['enabled'] ) {
		$filled  = mlab_smtp_route_state( 'fallback' )['eingetragen'];
		$items[] = array(
			'level' => 'warn',
			'title' => $filled ? __( 'Der Ersatzweg ist nicht getestet', 'mlab-smtp' ) : __( 'Es gibt noch keinen Ersatzweg', 'mlab-smtp' ),
			'text'  => $fallback['text'],
			'label' => $filled ? __( 'Ersatzweg testen', 'mlab-smtp' ) : __( 'Ersatzweg einrichten', 'mlab-smtp' ),
			'url'   => mlab_smtp_page_url(
				$filled ? array(
					'view' => 'fallback',
					'step' => 6,
				) : array( 'view' => 'fallback' )
			),
		);
	}
	return $items;
}

/**
 * The list «Was muss ich tun?»; when empty, a calm confirmation.
 *
 * @param array $items Result of mlab_smtp_overview_todos().
 */
function mlab_smtp_render_todo( array $items ): void {
	echo '<section class="mlab-card mlab-todolist" aria-labelledby="mlab-todo-h"><h2 id="mlab-todo-h">' . esc_html__( 'Was muss ich tun?', 'mlab-smtp' ) . '</h2>';
	if ( ! $items ) {
		echo '<p class="mlab-li"><span class="mlab-ic is-ok" aria-hidden="true">✓</span><span>' . esc_html__( 'Nichts. Alles ist in Ordnung.', 'mlab-smtp' ) . '</span></p></section>';
		return;
	}
	echo '<ul class="mlab-list">';
	foreach ( $items as $item ) {
		echo '<li class="mlab-li mlab-todo-item"><span class="mlab-ic is-' . esc_attr( $item['level'] ) . '" aria-hidden="true">' . esc_html( mlab_smtp_ui_icon( $item['level'] ) ) . '</span><span class="mlab-li-main"><strong>' . esc_html( $item['title'] ) . '</strong><span class="mlab-muted">' . esc_html( $item['text'] ) . '</span></span>';
		echo '<a class="button" href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['label'] ) . '</a></li>';
	}
	echo '</ul></section>';
}

/**
 * The three cards: main route, fallback, queue.
 *
 * @param array|null $engine Engine summary or null.
 */
function mlab_smtp_render_cards( ?array $engine ): void {
	echo '<div class="mlab-cards">';
	mlab_smtp_render_route_card( 'primary' );
	mlab_smtp_render_route_card( 'fallback' );
	mlab_smtp_render_queue_card( $engine );
	echo '</div>';
}

/**
 * Card of one route: provider, sender and its state in words.
 *
 * @param string $route primary or fallback.
 */
function mlab_smtp_render_route_card( string $route ): void {
	$state    = mlab_smtp_route_state( $route );
	$fallback = 'fallback' === $route;
	$title    = $fallback ? __( 'Ersatzweg', 'mlab-smtp' ) : __( 'Hauptweg', 'mlab-smtp' );
	echo '<section class="mlab-card mlab-route-card"><h2>' . esc_html( $title ) . '</h2>';
	if ( ! $state['eingetragen'] ) {
		mlab_smtp_ui_icon_pill( 'info', __( 'Nicht eingerichtet', 'mlab-smtp' ) );
		echo '<p class="mlab-muted">' . esc_html( $fallback ? __( 'Ohne Ersatzweg gehen Mails verloren, wenn der Hauptweg ausfällt.', 'mlab-smtp' ) : $state['text'] ) . '</p>';
		$url = $fallback ? mlab_smtp_page_url( array( 'view' => 'fallback' ) ) : mlab_smtp_page_url( array( 'view' => 'wizard' ) );
		echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( $fallback ? __( 'Ersatzweg einrichten', 'mlab-smtp' ) : __( 'Einrichtung starten', 'mlab-smtp' ) ) . '</a></section>';
		return;
	}
	echo '<div class="mlab-conn">';
	mlab_smtp_ui_logo( $state['provider'] );
	echo '<div class="mlab-li-main"><strong>' . esc_html( mlab_smtp_ui_providers()[ $state['provider'] ]['name'] ?? $state['provider'] ) . '</strong><span class="mlab-muted">' . esc_html( (string) mlab_smtp_get( 'from_email' ) ) . '</span></div></div>';
	mlab_smtp_ui_icon_pill( $state['level'], $state['label'] );
	echo '<p class="mlab-muted">' . esc_html( $state['text'] ) . '</p>';
	if ( $fallback ) {
		$answer = mlab_smtp_fallback_tested_answer();
		echo '<p><strong>' . esc_html( $answer['question'] ) . '</strong> ' . esc_html( $answer['tested'] ? __( 'Ja.', 'mlab-smtp' ) : __( 'Nein.', 'mlab-smtp' ) ) . '</p>';
		echo '<p class="mlab-muted">' . esc_html( $answer['text'] ) . '</p>';
	}
	$url   = $fallback ? mlab_smtp_page_url(
		array(
			'view' => 'fallback',
			'step' => 6,
		)
	) : mlab_smtp_page_url( array( 'view' => 'settings' ) );
	$label = $fallback ? __( 'Ersatzweg testen', 'mlab-smtp' ) : __( 'Verwalten', 'mlab-smtp' );
	echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></section>';
}

/**
 * Card of the queue: counts, oldest wait, delivery mode in words, link.
 *
 * @param array|null $engine Engine summary or null when the tables are missing.
 */
function mlab_smtp_render_queue_card( ?array $engine ): void {
	echo '<section class="mlab-card mlab-queue-card"><h2>' . esc_html__( 'Warteschlange', 'mlab-smtp' ) . '</h2>';
	if ( null === $engine ) {
		mlab_smtp_ui_icon_pill( 'bad', __( 'Nicht verfügbar', 'mlab-smtp' ) );
		echo '<p class="mlab-muted">' . esc_html__( 'Die Datenbanktabellen fehlen, deshalb gibt es keine Warteschlange.', 'mlab-smtp' ) . '</p></section>';
		return;
	}
	$counts = mlab_smtp_store_counts();
	$rows   = array(
		'queued'      => __( 'Wartend', 'mlab-smtp' ),
		'processing'  => __( 'In Bearbeitung', 'mlab-smtp' ),
		'temp_failed' => __( 'Wiederholung geplant', 'mlab-smtp' ),
		'unclear'     => __( 'Unklar', 'mlab-smtp' ),
		'failed'      => __( 'Endgültig fehlgeschlagen', 'mlab-smtp' ),
	);
	echo '<dl class="mlab-dl mlab-counts">';
	foreach ( $rows as $status => $label ) {
		echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) (int) $counts[ $status ] ) . '</dd>';
	}
	echo '</dl><p class="mlab-muted">';
	echo esc_html(
		$engine['oldest_wait'] > 0 ? sprintf(
		/* translators: %s: duration, e.g. 5 Minuten */
			__( 'Älteste fällige Mail wartet seit %s.', 'mlab-smtp' ),
			human_time_diff( time() - $engine['oldest_wait'] )
		) : __( 'Keine fällige Mail wartet.', 'mlab-smtp' )
	);
	echo ' ' . esc_html( mlab_smtp_ui_mode_text( $engine ) ) . '</p>';
	echo '<a class="button" href="' . esc_url( mlab_smtp_page_url( array( 'view' => 'queue' ) ) ) . '">' . esc_html__( 'Warteschlange öffnen', 'mlab-smtp' ) . '</a></section>';
}

/**
 * One line naming the active connection and sender, e.g. "Über Microsoft 365 als noreply@example.ch".
 */
function mlab_smtp_route_summary(): string {
	$name = mlab_smtp_ui_providers()[ (string) mlab_smtp_get( 'provider' ) ]['name'] ?? '';
	/* translators: 1: provider name, 2: sender address */
	return sprintf( __( 'Über %1$s als %2$s', 'mlab-smtp' ), $name, (string) mlab_smtp_get( 'from_email' ) );
}

/**
 * Test mail form; it opens through the link in the page header (CSS :target), so it works without JavaScript.
 */
function mlab_smtp_render_quick_test(): void {
	echo '<section class="mlab-card mlab-quick" id="mlab-quick" aria-label="' . esc_attr__( 'Testmail senden', 'mlab-smtp' ) . '">';
	mlab_smtp_ui_form_open( 'mlab_smtp_test', 'mlab-quick-form', array( 'data-mlab-test-form' => '1' ) );
	echo '<div class="mlab-field"><label for="mlab-quick-to">' . esc_html__( 'Testmail an', 'mlab-smtp' ) . '</label>';
	echo '<input type="email" id="mlab-quick-to" name="to" required value="' . esc_attr( wp_get_current_user()->user_email ) . '"></div>';
	echo '<div class="mlab-foot"><a class="button button-link" href="#">' . esc_html__( 'Abbrechen', 'mlab-smtp' ) . '</a>';
	echo '<button type="submit" class="button button-primary" data-mlab-test>' . mlab_smtp_ui_svg( 'send' ) . esc_html__( 'Senden', 'mlab-smtp' ) . '</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
	mlab_smtp_ui_result_area();
	mlab_smtp_ui_form_close();
	echo '</section>';
}

/**
 * Three-step checklist: connection, sender, test mail.
 */
function mlab_smtp_render_steps(): void {
	$steps = array(
		array( __( 'Verbindung', 'mlab-smtp' ), mlab_smtp_has_credentials() ),
		array( __( 'Absender', 'mlab-smtp' ), '' !== (string) mlab_smtp_get( 'from_email' ) ),
		array( __( 'Testmail', 'mlab-smtp' ), (bool) mlab_smtp_state_get()['last_success'] ),
	);
	echo '<ol class="mlab-card mlab-steps" aria-label="' . esc_attr__( 'Einrichtung', 'mlab-smtp' ) . '">';
	foreach ( $steps as $index => $step ) {
		echo '<li class="' . ( $step[1] ? 'is-done' : '' ) . '"><span class="mlab-ic ' . ( $step[1] ? 'is-ok' : 'is-neu' ) . '" aria-hidden="true">' . esc_html( $step[1] ? '✓' : (string) ( $index + 1 ) ) . '</span>' . esc_html( $step[0] );
		echo $step[1] ? '<span class="screen-reader-text"> ' . esc_html__( 'Erledigt', 'mlab-smtp' ) . '</span>' : '';
		echo '</li>';
	}
	echo '</ol>';
}

/**
 * Tiny line chart with a soft area below, drawn as inline SVG.
 *
 * @param int[]|float[] $values Values, oldest first.
 * @param string        $tone   Colour class: info, ok or bad.
 * @param string        $label  Text for assistive technology.
 */
function mlab_smtp_ui_spark( array $values, string $tone, string $label ): string {
	$values = array_values( array_map( 'floatval', $values ) );
	$count  = count( $values );
	if ( $count < 2 ) {
		return '';
	}
	$min    = min( $values );
	$span   = max( $values ) - $min;
	$points = array();
	foreach ( $values as $index => $value ) {
		$y        = $span > 0 ? 34 - ( $value - $min ) / $span * 28 : 20;
		$points[] = round( $index * 120 / ( $count - 1 ), 1 ) . ' ' . round( $y, 1 );
	}
	$line = 'M' . implode( ' L', $points );
	return '<svg class="mlab-spark is-' . esc_attr( $tone ) . '" viewBox="0 0 120 40" preserveAspectRatio="none" role="img" aria-label="' . esc_attr( $label ) . '"><path d="' . esc_attr( $line . ' L120 40 L0 40 Z' ) . '" fill="currentColor" fill-opacity="0.1"/><path d="' . esc_attr( $line ) . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/></svg>';
}

/**
 * Daily success rate in percent; days without mails repeat the previous value.
 *
 * @param int[] $daily  Mails per day.
 * @param int[] $failed Failures per day.
 * @return float[]
 */
function mlab_smtp_rate_series( array $daily, array $failed ): array {
	$rate   = 100.0;
	$series = array();
	foreach ( array_values( $daily ) as $index => $count ) {
		$rate     = (int) $count > 0 ? ( (int) $count - (int) ( $failed[ $index ] ?? 0 ) ) / (int) $count * 100 : $rate;
		$series[] = $rate;
	}
	return $series;
}

/**
 * The three key figures: label, value, value class, daily series and sparkline tone.
 *
 * @param array $stats Result of mlab_smtp_stats().
 * @return array<int, array{0:string, 1:string, 2:string, 3:array, 4:string}>
 */
function mlab_smtp_kpi_items( array $stats ): array {
	$daily  = array_map( 'intval', (array) $stats['daily'] );
	$failed = array_map( 'intval', (array) $stats['daily_failed'] );
	$total  = (int) $stats['sent'] + (int) $stats['failed'];
	$rate   = $total > 0 ? number_format_i18n( round( (int) $stats['sent'] / $total * 100, 1 ), 1 ) . ' %' : '–';
	$sent   = array_map(
		static function ( int $all, int $bad ): int {
			return max( 0, $all - $bad );
		},
		$daily,
		$failed
	);
	return array(
		array( __( 'Vom Anbieter angenommen, 7 Tage', 'mlab-smtp' ), (string) (int) $stats['sent'], '', $sent, 'info' ),
		array( __( 'Annahmequote', 'mlab-smtp' ), $rate, '', mlab_smtp_rate_series( $daily, $failed ), 'ok' ),
		array( __( 'Fehlgeschlagen', 'mlab-smtp' ), (string) (int) $stats['failed'], $stats['failed'] > 0 ? 'is-bad' : '', $failed, 'bad' ),
	);
}

/**
 * Three key figures with a sparkline each.
 *
 * @param array $stats Result of mlab_smtp_stats().
 */
function mlab_smtp_render_kpis( array $stats ): void {
	/* translators: %d: number of days */
	$label = sprintf( __( 'Mails pro Tag, letzte %d Tage', 'mlab-smtp' ), count( (array) $stats['daily'] ) );
	echo '<dl class="mlab-kpis">';
	foreach ( mlab_smtp_kpi_items( $stats ) as $item ) {
		echo '<div class="mlab-card mlab-kpi"><dt>' . esc_html( $item[0] ) . '</dt><dd class="' . esc_attr( $item[2] ) . '">' . esc_html( $item[1] ) . '</dd>' . mlab_smtp_ui_spark( $item[3], $item[4], $label ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
	}
	echo '</dl>';
}

/**
 * Health checks as grouped list rows.
 */
function mlab_smtp_render_health(): void {
	echo '<section class="mlab-card"><div class="mlab-card-head"><div><h2>' . esc_html__( 'Gesundheit', 'mlab-smtp' ) . '</h2><p class="mlab-muted">' . esc_html__( 'Wird täglich automatisch geprüft.', 'mlab-smtp' ) . '</p></div></div><ul class="mlab-list">';
	foreach ( mlab_smtp_health_checks() as $check ) {
		echo '<li class="mlab-li"><span class="mlab-ic is-' . esc_attr( $check['level'] ) . '" aria-hidden="true">' . esc_html( mlab_smtp_ui_icon( $check['level'] ) ) . '</span><span class="mlab-li-main"><strong>' . esc_html( $check['title'] ) . '</strong><span class="mlab-muted">' . esc_html( $check['text'] ) . '</span></span>';
		if ( '' !== $check['action_url'] ) {
			echo '<a href="' . esc_url( $check['action_url'] ) . '">' . esc_html( $check['action_label'] ) . '</a>';
		}
		echo '</li>';
	}
	echo '</ul></section>';
}

/**
 * Card with the latest mails as list rows. A failed row opens to a plain-language error with a link to the fix.
 */
function mlab_smtp_render_recent(): void {
	echo '<section class="mlab-card"><div class="mlab-card-head"><h2>' . esc_html__( 'Letzte Mails', 'mlab-smtp' ) . '</h2><a href="' . esc_url( mlab_smtp_page_url( array( 'view' => 'log' ) ) ) . '">' . esc_html__( 'Ganzes Protokoll', 'mlab-smtp' ) . '</a></div><ul class="mlab-list">';
	$entries = mlab_smtp_log_get( array( 'limit' => 5 ) );
	$opened  = false;
	foreach ( $entries as $entry ) {
		$expand = mlab_smtp_recent_has_detail( $entry ) && ! $opened;
		$opened = $opened || $expand;
		mlab_smtp_render_recent_row( $entry, $expand );
	}
	if ( ! $entries ) {
		echo '<li class="mlab-li"><span class="mlab-muted">' . esc_html__( 'Noch keine Nachrichten.', 'mlab-smtp' ) . '</span></li>';
	}
	echo '</ul></section>';
}

/**
 * Whether a row opens to an explanation: it has an error text and did not succeed.
 *
 * @param array $entry Log entry.
 */
function mlab_smtp_recent_has_detail( array $entry ): bool {
	return empty( $entry['ok'] ) && '' !== (string) $entry['error'];
}

/**
 * One row of the latest mails.
 *
 * @param array $entry  Log entry.
 * @param bool  $expand Whether a failed row starts open.
 */
function mlab_smtp_render_recent_row( array $entry, bool $expand ): void {
	if ( ! mlab_smtp_recent_has_detail( $entry ) ) {
		$url = mlab_smtp_page_url(
			array(
				'view'  => 'log',
				'entry' => $entry['id'],
			)
		);
		echo '<li><a class="mlab-li" href="' . esc_url( $url ) . '">';
		mlab_smtp_render_entry_summary( $entry );
		echo '</a></li>';
		return;
	}
	echo '<li><details' . ( $expand ? ' open' : '' ) . '><summary class="mlab-li">';
	mlab_smtp_render_entry_summary( $entry );
	echo mlab_smtp_ui_svg( 'chevron' ) . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
	if ( in_array( $entry['status'], array( 'unclear', 'partial', 'blocked' ), true ) ) {
		$fix   = mlab_smtp_page_url( array( 'view' => 'queue' ) );
		$label = __( 'Warteschlange öffnen', 'mlab-smtp' );
	} else {
		$fix   = mlab_smtp_wizard_url( 2, (string) mlab_smtp_get( 'provider' ) );
		$label = __( 'Fehler beheben', 'mlab-smtp' );
	}
	mlab_smtp_ui_hint(
		mlab_smtp_ui_entry_level( $entry ),
		'',
		mlab_smtp_ui_error_texts( (string) $entry['error'] )['message'],
		static function () use ( $fix, $label ): void {
			echo '<a href="' . esc_url( $fix ) . '">' . esc_html( $label ) . '</a>';
		}
	);
	echo '</details></li>';
}

/**
 * Content of a mail row: status icon, subject, recipient, result pill and time.
 *
 * @param array $entry Log entry.
 */
function mlab_smtp_render_entry_summary( array $entry ): void {
	$level = mlab_smtp_ui_entry_level( $entry );
	echo '<span class="mlab-ic is-' . esc_attr( $level ) . '" aria-hidden="true">' . esc_html( mlab_smtp_ui_message_status( (string) $entry['status'] )['icon'] ) . '</span>';
	echo '<span class="mlab-li-main"><strong>' . esc_html( $entry['subject'] ) . '</strong><span class="mlab-muted">' . esc_html( $entry['to'] ) . '</span></span>';
	mlab_smtp_ui_entry_pill( $entry );
	echo '<span class="mlab-time">' . esc_html( mlab_smtp_ui_time( (int) $entry['time'] ) ) . '</span>';
}
