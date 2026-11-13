<?php
/**
 * Overview view: status, key figures, health checks, latest mails, quick test mail.
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
		mlab_smtp_render_kpis( $stats );
		echo '<div class="mlab-two">';
		mlab_smtp_render_health();
		mlab_smtp_render_recent( $stats );
		echo '</div>';
	}
	echo '</div>';
}

/**
 * Card for a site that is not set up yet, with the four steps ahead.
 *
 * @param array $status Result of mlab_smtp_status().
 */
function mlab_smtp_render_onboarding( array $status ): void {
	echo '<div class="mlab-card mlab-status is-none"><div class="mlab-dot" aria-hidden="true">!</div><div><h2>' . esc_html( $status['title'] ) . '</h2><p class="mlab-muted">' . esc_html( $status['text'] ) . '</p></div>';
	echo '<div class="mlab-act"><a class="button button-primary button-hero" href="' . esc_url( mlab_smtp_page_url( array( 'view' => 'wizard' ) ) ) . '">' . esc_html__( 'Einrichtung starten', 'mlab-smtp' ) . '</a></div></div>';
	$steps = array(
		array( __( 'Mailanbieter wählen', 'mlab-smtp' ), __( 'Wir schlagen den passenden anhand Ihrer Domain vor.', 'mlab-smtp' ) ),
		array( __( 'Verbinden', 'mlab-smtp' ), __( 'Zugangsdaten eingeben oder mit dem Konto anmelden. Wir prüfen die Verbindung sofort.', 'mlab-smtp' ) ),
		array( __( 'Absender festlegen', 'mlab-smtp' ), __( 'Ist bereits ausgefüllt.', 'mlab-smtp' ) ),
		array( __( 'Testmail senden', 'mlab-smtp' ), __( 'Erst danach ist die Einrichtung abgeschlossen.', 'mlab-smtp' ) ),
	);
	echo '<div class="mlab-card"><h2>' . esc_html__( 'So geht es weiter', 'mlab-smtp' ) . '</h2><p class="mlab-muted mlab-small">' . esc_html__( 'Dauert etwa 3 Minuten. Sie brauchen den Zugang zum Mailkonto Ihrer Firma.', 'mlab-smtp' ) . '</p><ol class="mlab-todo">';
	foreach ( $steps as $step ) {
		echo '<li><div><h3>' . esc_html( $step[0] ) . '</h3><p class="mlab-muted mlab-small">' . esc_html( $step[1] ) . '</p></div></li>';
	}
	echo '</ol></div>';
}

/**
 * Card with the overall state of sending.
 *
 * @param array $status Result of mlab_smtp_status().
 */
function mlab_smtp_render_status_card( array $status ): void {
	$icon = array(
		'ok'   => '✓',
		'warn' => '!',
		'bad'  => '✕',
	);
	echo '<div class="mlab-card mlab-status is-' . esc_attr( $status['state'] ) . '"><div class="mlab-dot" aria-hidden="true">' . esc_html( $icon[ $status['state'] ] ?? '!' ) . '</div>';
	echo '<div><h2>' . esc_html( $status['title'] ) . '</h2><p>' . esc_html( '' !== $status['text'] ? $status['text'] : mlab_smtp_route_summary() ) . '</p></div>';
	if ( 'ok' !== $status['state'] ) {
		$url = mlab_smtp_wizard_url( 2, (string) mlab_smtp_get( 'provider' ) );
		echo '<div class="mlab-act"><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Verbindung prüfen', 'mlab-smtp' ) . '</a></div>';
	}
	echo '</div>';
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
 * Collapsible test mail form; works with and without JavaScript.
 */
function mlab_smtp_render_quick_test(): void {
	echo '<details class="mlab-card mlab-quick"><summary class="button">' . esc_html__( 'Testmail senden', 'mlab-smtp' ) . '</summary>';
	mlab_smtp_ui_form_open( 'mlab_smtp_test', 'mlab-quick-form', array( 'data-mlab-test-form' => '1' ) );
	echo '<div class="mlab-field"><label for="mlab-quick-to">' . esc_html__( 'Testmail an', 'mlab-smtp' ) . '</label>';
	echo '<input type="email" id="mlab-quick-to" name="to" required value="' . esc_attr( wp_get_current_user()->user_email ) . '"></div>';
	echo '<button type="submit" class="button button-primary" data-mlab-test>' . esc_html__( 'Senden', 'mlab-smtp' ) . '</button>';
	mlab_smtp_ui_result_area();
	mlab_smtp_ui_form_close();
	echo '</details>';
}

/**
 * Four key figures.
 *
 * @param array $stats Result of mlab_smtp_stats().
 */
function mlab_smtp_render_kpis( array $stats ): void {
	$items = array(
		array( __( 'Gesendet, 7 Tage', 'mlab-smtp' ), (string) (int) $stats['sent'], '' ),
		array( __( 'Fehlgeschlagen', 'mlab-smtp' ), (string) (int) $stats['failed'], $stats['failed'] > 0 ? 'is-bad' : '' ),
		array( __( 'Über Ersatzweg', 'mlab-smtp' ), (string) (int) $stats['fallback'], '' ),
		array( __( 'Letzte Mail', 'mlab-smtp' ), mlab_smtp_ui_time( (int) $stats['last'] ), '' ),
	);
	echo '<dl class="mlab-kpis">';
	foreach ( $items as $item ) {
		echo '<div class="mlab-kpi"><dt>' . esc_html( $item[0] ) . '</dt><dd class="' . esc_attr( $item[2] ) . '">' . esc_html( $item[1] ) . '</dd></div>';
	}
	echo '</dl>';
}

/**
 * Health checks card.
 */
function mlab_smtp_render_health(): void {
	echo '<div class="mlab-card"><h2>' . esc_html__( 'Gesundheit', 'mlab-smtp' ) . '</h2><p class="mlab-muted mlab-small">' . esc_html__( 'Wird täglich automatisch geprüft.', 'mlab-smtp' ) . '</p><ul class="mlab-checks">';
	foreach ( mlab_smtp_health_checks() as $check ) {
		echo '<li><span class="mlab-ic is-' . esc_attr( $check['level'] ) . '" aria-hidden="true">' . esc_html( mlab_smtp_ui_icon( $check['level'] ) ) . '</span><div><h3>' . esc_html( $check['title'] ) . '</h3><p class="mlab-muted mlab-small">' . esc_html( $check['text'] ) . '</p></div>';
		if ( '' !== $check['action_url'] ) {
			echo '<div class="mlab-small"><a href="' . esc_url( $check['action_url'] ) . '">' . esc_html( $check['action_label'] ) . '</a></div>';
		}
		echo '</li>';
	}
	echo '</ul></div>';
}

/**
 * Card with the daily chart and the latest three log entries.
 *
 * @param array $stats Result of mlab_smtp_stats().
 */
function mlab_smtp_render_recent( array $stats ): void {
	echo '<div class="mlab-card"><h2>' . esc_html__( 'Letzte Mails', 'mlab-smtp' ) . '</h2>';
	mlab_smtp_render_chart( (array) $stats['daily'], (array) $stats['daily_failed'] );
	echo '<ul class="mlab-checks mlab-recent">';
	$entries = mlab_smtp_log_get( array( 'limit' => 3 ) );
	foreach ( $entries as $entry ) {
		$url = mlab_smtp_page_url(
			array(
				'view'  => 'log',
				'entry' => $entry['id'],
			)
		);
		echo '<li><div class="mlab-ellipsis"><a href="' . esc_url( $url ) . '">' . esc_html( $entry['subject'] ) . '</a><div class="mlab-muted mlab-small">' . esc_html( mlab_smtp_ui_time( (int) $entry['time'] ) . ' · ' . $entry['to'] ) . '</div></div>';
		mlab_smtp_ui_entry_pill( $entry );
		echo '</li>';
	}
	if ( ! $entries ) {
		echo '<li><p class="mlab-muted">' . esc_html__( 'Noch keine Nachrichten.', 'mlab-smtp' ) . '</p></li>';
	}
	echo '</ul><p><a href="' . esc_url( mlab_smtp_page_url( array( 'view' => 'log' ) ) ) . '">' . esc_html__( 'Ganzes Protokoll', 'mlab-smtp' ) . '</a></p></div>';
}

/**
 * Bar chart of mails per day.
 *
 * @param int[] $daily  Mails per day, oldest first.
 * @param int[] $failed Failures per day.
 */
function mlab_smtp_render_chart( array $daily, array $failed ): void {
	$max = max( 1, ...array_map( 'intval', $daily ? $daily : array( 0 ) ) );
	/* translators: %d: number of days */
	echo '<div class="mlab-mini" role="img" aria-label="' . esc_attr( sprintf( __( 'Mails pro Tag, letzte %d Tage', 'mlab-smtp' ), count( $daily ) ) ) . '">';
	foreach ( array_values( $daily ) as $index => $count ) {
		$class = ! empty( $failed[ $index ] ) ? ' class="is-failed"' : '';
		echo '<span' . $class . ' style="height:' . esc_attr( (string) round( (int) $count / $max * 100 ) ) . '%"></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute.
	}
	echo '</div><p class="mlab-muted mlab-small">' . esc_html( sprintf( /* translators: %d: number of days */ __( '%d Tage', 'mlab-smtp' ), count( $daily ) ) ) . '</p>';
}
