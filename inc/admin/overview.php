<?php
/**
 * Overview view: status banner, set-up steps, key figures, latest mails, active connection, health checks, quick test mail.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Overview tab.
 */
function mlab_smtp_render_overview(): void {
	$status = mlab_smtp_status();
	$stats  = mlab_smtp_stats();
	echo '<div class="mlab-stack">';
	if ( 'none' === $status['state'] ) {
		mlab_smtp_render_onboarding( $status );
	} else {
		mlab_smtp_render_status_card( $status );
		mlab_smtp_render_quick_test();
		mlab_smtp_render_steps();
		mlab_smtp_render_kpis( $stats );
		echo '<div class="mlab-two">';
		mlab_smtp_render_recent();
		mlab_smtp_render_connection_card( $status );
		echo '</div>';
		mlab_smtp_render_health();
	}
	echo '</div>';
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
 * @param string $level   ok, warn, bad or info.
 * @param string $title   Bold line.
 * @param string $text    Short explanation.
 * @param string $label   Label of the action.
 * @param string $url     Target of the action.
 * @param bool   $primary Whether the action is the primary button of the screen.
 */
function mlab_smtp_render_banner( string $level, string $title, string $text, string $label, string $url, bool $primary = false ): void {
	echo '<div class="mlab-hint is-banner is-' . esc_attr( $level ) . '"><span class="mlab-ic is-' . esc_attr( $level ) . '" aria-hidden="true">' . esc_html( mlab_smtp_ui_icon( $level ) ) . '</span>';
	echo '<div class="mlab-hint-body"><strong>' . esc_html( $title ) . '</strong><p>' . esc_html( $text ) . '</p></div>';
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
 * Banner with the overall state of sending.
 *
 * @param array $status Result of mlab_smtp_status().
 */
function mlab_smtp_render_status_card( array $status ): void {
	$url   = mlab_smtp_wizard_url( 2, (string) mlab_smtp_get( 'provider' ) );
	$label = 'ok' === $status['state'] ? __( 'Verbindung prüfen', 'mlab-smtp' ) : __( 'Beheben', 'mlab-smtp' );
	mlab_smtp_render_banner( $status['state'], $status['title'], '' !== $status['text'] ? $status['text'] : mlab_smtp_route_summary(), $label, $url );
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
		array( __( 'Gesendet, 7 Tage', 'mlab-smtp' ), (string) (int) $stats['sent'], '', $sent, 'info' ),
		array( __( 'Erfolgsquote', 'mlab-smtp' ), $rate, '', mlab_smtp_rate_series( $daily, $failed ), 'ok' ),
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
		$expand = empty( $entry['ok'] ) && ! $opened;
		$opened = $opened || $expand;
		mlab_smtp_render_recent_row( $entry, $expand );
	}
	if ( ! $entries ) {
		echo '<li class="mlab-li"><span class="mlab-muted">' . esc_html__( 'Noch keine Nachrichten.', 'mlab-smtp' ) . '</span></li>';
	}
	echo '</ul></section>';
}

/**
 * One row of the latest mails.
 *
 * @param array $entry  Log entry.
 * @param bool  $expand Whether a failed row starts open.
 */
function mlab_smtp_render_recent_row( array $entry, bool $expand ): void {
	if ( ! empty( $entry['ok'] ) ) {
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
	$fix = mlab_smtp_wizard_url( 2, (string) mlab_smtp_get( 'provider' ) );
	mlab_smtp_ui_hint(
		'bad',
		'',
		mlab_smtp_ui_error_texts( (string) $entry['error'] )['message'],
		static function () use ( $fix ): void {
			echo '<a href="' . esc_url( $fix ) . '">' . esc_html__( 'Fehler beheben', 'mlab-smtp' ) . '</a>';
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
	echo '<span class="mlab-ic is-' . esc_attr( $level ) . '" aria-hidden="true">' . esc_html( mlab_smtp_ui_icon( $level ) ) . '</span>';
	echo '<span class="mlab-li-main"><strong>' . esc_html( $entry['subject'] ) . '</strong><span class="mlab-muted">' . esc_html( $entry['to'] ) . '</span></span>';
	mlab_smtp_ui_entry_pill( $entry );
	echo '<span class="mlab-time">' . esc_html( mlab_smtp_ui_time( (int) $entry['time'] ) ) . '</span>';
}

/**
 * Card with the active connection and the way to manage it.
 *
 * @param array $status Result of mlab_smtp_status().
 */
function mlab_smtp_render_connection_card( array $status ): void {
	$provider = (string) mlab_smtp_get( 'provider' );
	echo '<section class="mlab-card mlab-active"><h2>' . esc_html__( 'Aktive Verbindung', 'mlab-smtp' ) . '</h2><div class="mlab-conn">';
	mlab_smtp_ui_logo( $provider );
	echo '<div class="mlab-li-main"><strong>' . esc_html( mlab_smtp_ui_providers()[ $provider ]['name'] ?? '' ) . '</strong><span class="mlab-muted">' . esc_html( (string) mlab_smtp_get( 'from_email' ) ) . '</span></div></div>';
	if ( 'ok' === $status['state'] ) {
		mlab_smtp_ui_pill( 'ok', __( 'Verbunden', 'mlab-smtp' ) );
	} else {
		mlab_smtp_ui_pill( $status['state'], __( 'Gestört', 'mlab-smtp' ) );
	}
	echo '<a class="button" href="' . esc_url( mlab_smtp_page_url( array( 'view' => 'settings' ) ) ) . '">' . mlab_smtp_ui_svg( 'gear' ) . esc_html__( 'Verwalten', 'mlab-smtp' ) . '</a></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
}
