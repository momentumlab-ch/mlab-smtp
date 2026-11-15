<?php
/**
 * Shared render helpers of the admin pages.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Display data of the providers: name, logo, card of the wizard, connect step, success text and sender behaviour.
 *
 * It is the counterpart of mlab_smtp_provider_defs() for everything that is translated or drawn. Cached per request,
 * the first call has to come after "init" (translations).
 *
 * The sender entry holds: source (setting that prefills the address), fixed (address is read-only), help (text below the address in
 * the wizard, empty for the default), help_settings (text on the settings page), suggest (function that suggests an
 * address) and panel (function that prints a status block above the sender fields).
 *
 * @return array<string, array<string, mixed>>
 */
function mlab_smtp_ui_providers(): array {
	static $providers = null;
	if ( null === $providers ) {
		$providers = array(
			'm365'   => array(
				'name'    => __( 'Microsoft 365', 'mlab-smtp' ),
				'color'   => '#0f6cbd',
				'letter'  => 'M',
				'card'    => array(
					'text'    => __( 'Für Firmen mit Microsoft 365 oder Outlook. Kein Passwort auf der Website.', 'mlab-smtp' ),
					'bullets' => array( __( 'Kein Passwort auf der Website', 'mlab-smtp' ), __( 'Einmalig App in Microsoft anlegen', 'mlab-smtp' ) ),
				),
				'connect' => 'mlab_smtp_render_connect_m365',
				'success' => __( 'Verbunden. Microsoft hat die Zugangsdaten akzeptiert.', 'mlab-smtp' ),
				'sender'  => mlab_smtp_ui_sender( array() ),
			),
			'google' => array(
				'name'    => __( 'Google Workspace', 'mlab-smtp' ),
				'color'   => '#1e8e3e',
				'letter'  => 'G',
				'card'    => array(
					'text'    => __( 'Für Firmen mit Google Workspace oder Gmail. Anmeldung mit dem Google-Konto.', 'mlab-smtp' ),
					'bullets' => array( __( 'Anmelden mit Google-Konto', 'mlab-smtp' ), __( 'Einmalig Zugang in Google Cloud anlegen', 'mlab-smtp' ) ),
				),
				'connect' => 'mlab_smtp_render_connect_google',
				'success' => __( 'Verbunden. Google hat den Zugriff bestätigt.', 'mlab-smtp' ),
				'sender'  => mlab_smtp_ui_sender(
					array(
						'source'        => 'google_account',
						'fixed'         => true,
						'help'          => __( 'Das verbundene Google-Konto. Google erlaubt nur dieses oder einen freigegebenen Alias.', 'mlab-smtp' ),
						'help_settings' => __( 'Vorgegeben durch das verbundene Google-Konto.', 'mlab-smtp' ),
					)
				),
			),
			'resend' => array(
				'name'       => __( 'Resend', 'mlab-smtp' ),
				'color'      => '#111111',
				'letter'     => 'R',
				'card'       => array(
					'text'    => __( 'Versanddienst für Website-Mails, braucht kein Postfach. Nur ein API-Key nötig.', 'mlab-smtp' ),
					'bullets' => array( __( 'Nur ein API-Key, keine Anmeldung', 'mlab-smtp' ), __( 'Prüft Ihre Domain und zeigt fehlende DNS-Einträge', 'mlab-smtp' ) ),
				),
				'connect'    => 'mlab_smtp_render_connect_resend',
				'success'    => static fn(): string => mlab_smtp_resend_success_text(),
				'fb_success' => __( 'Ersatzweg erreichbar. Resend hat den API-Key akzeptiert.', 'mlab-smtp' ),
				'sender'     => mlab_smtp_ui_sender(
					array(
						'help'    => __( 'Die Domain muss bei Resend verifiziert sein. Das Postfach muss nicht existieren, zum Beispiel noreply@ihre-domain.ch.', 'mlab-smtp' ),
						'suggest' => 'mlab_smtp_resend_suggest_sender',
						'panel'   => 'mlab_smtp_render_resend_domain_panel',
					)
				),
			),
			'smtp'   => array(
				'name'       => __( 'Eigener Mailserver', 'mlab-smtp' ),
				'color'      => '#50575e',
				'letter'     => '@',
				'card'       => array(
					'text'    => __( 'Für den Mailserver Ihres Hosters, z. B. Infomaniak, Hostpoint, cyon.', 'mlab-smtp' ),
					'bullets' => array( __( 'Server, Benutzer und Passwort', 'mlab-smtp' ), __( 'Funktioniert mit fast jedem Anbieter', 'mlab-smtp' ) ),
				),
				'connect'    => 'mlab_smtp_render_connect_smtp',
				'success'    => __( 'Die Anmeldung am Server war erfolgreich.', 'mlab-smtp' ),
				'fb_success' => __( 'Ersatzweg erreichbar. Die Anmeldung am Server war erfolgreich.', 'mlab-smtp' ),
				'sender'     => mlab_smtp_ui_sender( array( 'source' => 'smtp_user' ) ),
			),
		);
	}
	return $providers;
}

/**
 * Sender behaviour of a provider with the defaults filled in.
 *
 * @param array $overrides Values that differ from the defaults, see mlab_smtp_ui_providers().
 */
function mlab_smtp_ui_sender( array $overrides ): array {
	return array_merge(
		array(
			'source'        => '',
			'fixed'         => false,
			'help'          => '',
			'help_settings' => '',
			'suggest'       => null,
			'panel'         => null,
		),
		$overrides
	);
}

/**
 * Prints the round provider logo.
 *
 * @param string $provider m365, google, resend or smtp.
 */
function mlab_smtp_ui_logo( string $provider ): void {
	$meta = mlab_smtp_ui_providers()[ $provider ] ?? mlab_smtp_ui_providers()['smtp'];
	$svg  = mlab_smtp_ui_logo_svg( $provider );
	if ( '' !== $svg ) {
		echo '<span class="mlab-logo is-brand" aria-hidden="true">' . $svg . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup from mlab_smtp_ui_logo_svg().
		return;
	}
	printf(
		'<span class="mlab-logo" style="background:%1$s" aria-hidden="true">%2$s</span>',
		esc_attr( $meta['color'] ),
		esc_html( $meta['letter'] )
	);
}

/**
 * Inline SVG mark of a provider, empty when the provider has none (a letter badge is shown then).
 *
 * @param string $provider m365, google, resend or smtp.
 */
function mlab_smtp_ui_logo_svg( string $provider ): string {
	$marks = array(
		'm365'   => '<svg viewBox="0 0 24 24" width="24" height="24" focusable="false"><path fill="#f25022" d="M1 1h10.5v10.5H1z"/><path fill="#7fba00" d="M12.5 1H23v10.5H12.5z"/><path fill="#00a4ef" d="M1 12.5h10.5V23H1z"/><path fill="#ffb900" d="M12.5 12.5H23V23H12.5z"/></svg>',
		'google' => '<svg viewBox="0 0 48 48" width="24" height="24" focusable="false"><path fill="#ea4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285f4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#fbbc05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34a853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>',
		'smtp'   => '<svg viewBox="0 0 24 24" width="24" height="24" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="3" y="4" width="18" height="6.5" rx="2"/><rect x="3" y="13.5" width="18" height="6.5" rx="2"/><path d="M7 7.25h.01M7 16.75h.01"/></svg>',
	);
	return $marks[ $provider ] ?? '';
}

/**
 * Formats a timestamp: time only for today, otherwise day, month and time.
 *
 * @param int $timestamp Unix time. 0 gives a dash.
 */
function mlab_smtp_ui_time( int $timestamp ): string {
	if ( $timestamp <= 0 ) {
		return '–';
	}
	$is_today = wp_date( 'Y-m-d', $timestamp ) === wp_date( 'Y-m-d' );
	return wp_date( $is_today ? 'H:i' : 'd.m. H:i', $timestamp );
}

/**
 * Status icon character for a level.
 *
 * @param string $level ok, warn, bad or info.
 */
function mlab_smtp_ui_icon( string $level ): string {
	$icons = array(
		'ok'   => '✓',
		'warn' => '!',
		'bad'  => '✕',
		'info' => 'i',
	);
	return $icons[ $level ] ?? '!';
}

/**
 * Inline SVG icon (24 px grid, stroke follows the text colour). Static markup, safe to print.
 *
 * @param string $name send, refresh, arrow, external, gear, search, chevron or close.
 */
function mlab_smtp_ui_svg( string $name ): string {
	$paths = array(
		'send'     => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
		'refresh'  => '<path d="M21 12a9 9 0 0 1-15.5 6.2L3 16"/><path d="M3 21v-5h5"/><path d="M3 12a9 9 0 0 1 15.5-6.2L21 8"/><path d="M21 3v5h-5"/>',
		'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
		'external' => '<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
		'gear'     => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
		'search'   => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
		'chevron'  => '<path d="m6 9 6 6 6-6"/>',
		'close'    => '<path d="M18 6 6 18M6 6l12 12"/>',
	);
	return '<svg class="mlab-svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? '' ) . '</svg>';
}

/**
 * Prints a small coloured label.
 *
 * @param string $level ok, warn, bad, info or neu.
 * @param string $label Text.
 */
function mlab_smtp_ui_pill( string $level, string $label ): void {
	echo '<span class="mlab-pill is-' . esc_attr( $level ) . '">' . esc_html( $label ) . '</span>';
}

/**
 * Central wording for the status of a message. Every screen uses it, so «angenommen» is never called «zugestellt».
 *
 * @param string $status One of the store statuses.
 * @return array{label:string, level:string, icon:string, text:string}
 */
function mlab_smtp_ui_message_status( string $status ): array {
	$map = array(
		'queued'      => array( __( 'Wartet auf Versand', 'mlab-smtp' ), 'info', '…', __( 'Die Mail liegt in der Warteschlange und wird gleich gesendet.', 'mlab-smtp' ) ),
		'processing'  => array( __( 'Wird gesendet', 'mlab-smtp' ), 'info', '↻', __( 'Die Mail wird gerade an den Anbieter übergeben.', 'mlab-smtp' ) ),
		'accepted'    => array( __( 'Vom Anbieter angenommen', 'mlab-smtp' ), 'ok', '✓', __( 'Der Anbieter hat die Mail übernommen. Ob sie im Postfach ankam, bestätigt er erst mit einem Zustellbeleg.', 'mlab-smtp' ) ),
		'delivered'   => array( __( 'Zustellung bestätigt', 'mlab-smtp' ), 'ok', '✓', __( 'Der Anbieter hat die Zustellung ins Postfach des Empfängers bestätigt.', 'mlab-smtp' ) ),
		'temp_failed' => array( __( 'Wird erneut versucht', 'mlab-smtp' ), 'warn', '↻', __( 'Der letzte Versuch hat nicht geklappt. Das Plugin versucht es automatisch noch einmal.', 'mlab-smtp' ) ),
		'failed'      => array( __( 'Endgültig fehlgeschlagen', 'mlab-smtp' ), 'bad', '✕', __( 'Die Mail wurde nicht gesendet und wird nicht mehr automatisch versucht.', 'mlab-smtp' ) ),
		'unclear'     => array( __( 'Versandstatus unklar', 'mlab-smtp' ), 'warn', '?', __( 'Der Anbieter hat möglicherweise angenommen. Das Plugin sendet nicht von selbst erneut, damit nichts doppelt ankommt.', 'mlab-smtp' ) ),
		'partial'     => array( __( 'Teilweise angenommen', 'mlab-smtp' ), 'warn', '!', __( 'Einzelne Empfänger wurden abgelehnt, die übrigen wurden angenommen.', 'mlab-smtp' ) ),
		'blocked'     => array( __( 'Nicht gesendet (Staging-Schutz)', 'mlab-smtp' ), 'warn', '–', __( 'Der Staging-Schutz hat den Versand dieser Mail verhindert.', 'mlab-smtp' ) ),
		'bounced'     => array( __( 'Zurückgewiesen', 'mlab-smtp' ), 'bad', '✕', __( 'Der Mailserver des Empfängers hat die Mail zurückgewiesen.', 'mlab-smtp' ) ),
		'complained'  => array( __( 'Als Spam gemeldet', 'mlab-smtp' ), 'bad', '✕', __( 'Der Empfänger hat die Mail als unerwünscht gemeldet.', 'mlab-smtp' ) ),
	);
	$row = $map[ $status ] ?? array( __( 'Status unbekannt', 'mlab-smtp' ), 'info', 'i', '' );
	return array(
		'label' => $row[0],
		'level' => $row[1],
		'icon'  => $row[2],
		'text'  => $row[3],
	);
}

/**
 * Level of a log entry from its status; accepted by the fallback shows as a warning.
 *
 * @param array $entry Log entry.
 */
function mlab_smtp_ui_entry_level( array $entry ): string {
	$level = mlab_smtp_ui_message_status( (string) ( $entry['status'] ?? '' ) )['level'];
	if ( 'info' === $level && '' === (string) ( $entry['status'] ?? '' ) ) {
		$level = empty( $entry['ok'] ) ? 'bad' : 'ok';
	}
	return 'ok' === $level && ! empty( $entry['fallback'] ) ? 'warn' : $level;
}

/**
 * Prints the status pill: icon and text, never colour alone.
 *
 * @param string $status Store status.
 */
function mlab_smtp_ui_status_pill( string $status ): void {
	$info = mlab_smtp_ui_message_status( $status );
	mlab_smtp_ui_icon_pill( $info['level'], $info['label'], $info['icon'] );
}

/**
 * Prints a pill with an icon in front of the text.
 *
 * @param string $level ok, warn, bad, info or neu.
 * @param string $label Text.
 * @param string $icon  Icon character; empty for the icon of the level.
 */
function mlab_smtp_ui_icon_pill( string $level, string $label, string $icon = '' ): void {
	echo '<span class="mlab-pill has-icon is-' . esc_attr( $level ) . '"><span aria-hidden="true">' . esc_html( '' !== $icon ? $icon : mlab_smtp_ui_icon( $level ) ) . '</span>' . esc_html( $label ) . '</span>';
}

/**
 * Prints the result pill of a log entry: its status and, when the fallback took over, a second pill.
 *
 * @param array $entry Log entry.
 */
function mlab_smtp_ui_entry_pill( array $entry ): void {
	mlab_smtp_ui_status_pill( (string) ( $entry['status'] ?? ( empty( $entry['ok'] ) ? 'failed' : 'accepted' ) ) );
	if ( ! empty( $entry['fallback'] ) ) {
		mlab_smtp_ui_pill( 'warn', __( 'Ersatzweg', 'mlab-smtp' ) );
	}
}

/**
 * Page numbers to show: first, last and the neighbours of the current page; null marks a gap.
 *
 * @param int $page  Current page.
 * @param int $pages Number of pages.
 * @return array<int, int|null>
 */
function mlab_smtp_ui_page_window( int $page, int $pages ): array {
	$shown = array_unique( array_merge( array( 1, $pages ), range( max( 1, $page - 2 ), min( $pages, $page + 2 ) ) ) );
	sort( $shown );
	$out  = array();
	$prev = 0;
	foreach ( $shown as $number ) {
		if ( $prev && $number - $prev > 1 ) {
			$out[] = null;
		}
		$out[] = $number;
		$prev  = $number;
	}
	return $out;
}

/**
 * Pagination with total count, previous, next and page numbers. Plain links, no script.
 *
 * @param array $result Result of mlab_smtp_store_list().
 * @param array $args Query arguments of the page (view, filter, status, s).
 */
function mlab_smtp_ui_pagination( array $result, array $args ): void {
	$page  = (int) $result['page'];
	$pages = (int) $result['pages'];
	/* translators: %d: number of messages */
	$total = sprintf( _n( '%d Eintrag', '%d Einträge', (int) $result['total'], 'mlab-smtp' ), (int) $result['total'] );
	echo '<nav class="mlab-pager" aria-label="' . esc_attr__( 'Seiten', 'mlab-smtp' ) . '"><span class="mlab-muted">' . esc_html( $total ) . '</span>';
	if ( $pages > 1 ) {
		$link = static function ( int $to, string $label, string $aria = '' ) use ( $args, $page ): string {
			$url = mlab_smtp_page_url( array_merge( $args, array( 'paged' => $to ) ) );
			return '<a class="button" href="' . esc_url( $url ) . '"' . ( $to === $page ? ' aria-current="page"' : '' ) . ( '' !== $aria ? ' aria-label="' . esc_attr( $aria ) . '"' : '' ) . '>' . esc_html( $label ) . '</a>';
		};
		echo '<span class="mlab-pager-links">';
		echo $page > 1 ? $link( $page - 1, __( 'Zurück', 'mlab-smtp' ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the closure.
		foreach ( mlab_smtp_ui_page_window( $page, $pages ) as $number ) {
			/* translators: %d: page number */
			echo null === $number ? '<span aria-hidden="true">…</span>' : $link( $number, (string) $number, sprintf( __( 'Seite %d', 'mlab-smtp' ), $number ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the closure.
		}
		echo $page < $pages ? $link( $page + 1, __( 'Weiter', 'mlab-smtp' ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the closure.
		echo '</span>';
	}
	echo '</nav>';
}

/**
 * Prints the shared hint: a coloured box with an icon, used for results, explanations and warnings.
 *
 * @param string        $level ok, warn, bad or info.
 * @param string        $title Bold first line, may be empty.
 * @param string        $text  Optional second line.
 * @param callable|null $more  Optional function that prints more content below the text.
 */
function mlab_smtp_ui_hint( string $level, string $title, string $text = '', ?callable $more = null ): void {
	echo '<div class="mlab-hint is-' . esc_attr( $level ) . '"><span class="mlab-ic is-' . esc_attr( $level ) . '" aria-hidden="true">' . esc_html( mlab_smtp_ui_icon( $level ) ) . '</span><div class="mlab-hint-body">' . ( '' !== $title ? '<strong>' . esc_html( $title ) . '</strong>' : '' );
	if ( '' !== $text ) {
		echo '<p>' . esc_html( $text ) . '</p>';
	}
	if ( $more ) {
		$more();
	}
	echo '</div></div>';
}

/**
 * Prints a collapsible block with a technical message.
 *
 * @param string $text Raw message or SMTP conversation.
 */
function mlab_smtp_ui_technical( string $text ): void {
	echo '<details class="mlab-technical"><summary>' . esc_html__( 'Technische Meldung', 'mlab-smtp' ) . '</summary><pre class="mlab-pre">' . esc_html( $text ) . '</pre></details>';
}

/**
 * Prints an empty live region that admin.js fills with the result of a check or test.
 */
function mlab_smtp_ui_result_area(): void {
	echo '<div class="mlab-result" data-mlab-result role="status" aria-live="polite"></div>';
}

/**
 * Prints a code snippet with a copy button.
 *
 * @param string $text Text to show and copy.
 */
function mlab_smtp_ui_copy( string $text ): void {
	echo '<div class="mlab-copy"><code>' . esc_html( $text ) . '</code><button type="button" class="button button-link" data-mlab-copy="' . esc_attr( $text ) . '">' . esc_html__( 'Kopieren', 'mlab-smtp' ) . '</button></div>';
}

/**
 * Note shown next to settings that a constant in wp-config.php fixes.
 */
function mlab_smtp_ui_lock_note(): void {
	echo '<span class="mlab-lock"><span class="dashicons dashicons-lock" aria-hidden="true"></span> ' . esc_html__( 'Gesetzt in wp-config.php', 'mlab-smtp' ) . '</span>';
}

/**
 * Opens a form that posts to admin-post.php with action and nonce.
 *
 * @param string $action Action name, also the nonce action.
 * @param string $classes Extra CSS classes.
 * @param array  $attrs  Extra attributes (name => value), escaped here.
 */
function mlab_smtp_ui_form_open( string $action, string $classes = '', array $attrs = array() ): void {
	$html = '';
	foreach ( $attrs as $name => $value ) {
		$html .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $classes ) . '"' . $html . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped above.
	echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
	wp_nonce_field( $action );
}

/**
 * Closes a form opened with mlab_smtp_ui_form_open().
 */
function mlab_smtp_ui_form_close(): void {
	echo '</form>';
}

/**
 * Prints a form field with label, hint and lock note.
 *
 * Secret fields never contain their value; a placeholder tells that one is stored.
 *
 * @param string $key   Setting key, also the field name.
 * @param string $label Label.
 * @param array  $args  type, help, placeholder, options (value => label), readonly, value, attrs.
 */
function mlab_smtp_ui_field( string $key, string $label, array $args = array() ): void {
	$args   = wp_parse_args(
		$args,
		array(
			'type'        => 'text',
			'help'        => '',
			'placeholder' => '',
			'options'     => array(),
			'readonly'    => false,
			'value'       => null,
			'attrs'       => array(),
		)
	);
	$id     = 'mlab-smtp-' . $key;
	$locked = mlab_smtp_is_locked( $key );
	echo '<div class="mlab-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
	if ( $args['options'] ) {
		mlab_smtp_ui_select( $id, $key, $args, $locked );
	} else {
		mlab_smtp_ui_input( $id, $key, $args, $locked );
	}
	if ( '' !== $args['help'] ) {
		echo '<span class="description" id="' . esc_attr( $id ) . '-help">' . esc_html( $args['help'] ) . '</span>';
	}
	if ( $locked ) {
		mlab_smtp_ui_lock_note();
	}
	echo '</div>';
}

/**
 * Attributes shared by input and select: id, name, help reference, disabled, extras.
 *
 * @param string $id     Element id.
 * @param string $key    Field name.
 * @param array  $args   Field arguments.
 * @param bool   $locked Whether a constant fixes the value.
 */
function mlab_smtp_ui_common_attrs( string $id, string $key, array $args, bool $locked ): string {
	$html = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '"';
	if ( '' !== $args['help'] ) {
		$html .= ' aria-describedby="' . esc_attr( $id ) . '-help"';
	}
	$html .= $locked ? ' disabled' : '';
	foreach ( $args['attrs'] as $name => $value ) {
		$html .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
	}
	return $html;
}

/**
 * Prints the text-like input of a field.
 *
 * @param string $id     Element id.
 * @param string $key    Field name.
 * @param array  $args   Field arguments.
 * @param bool   $locked Whether a constant fixes the value.
 */
function mlab_smtp_ui_input( string $id, string $key, array $args, bool $locked ): void {
	$secret      = in_array( $key, mlab_smtp_secret_keys(), true );
	$value       = $secret ? '' : (string) ( $args['value'] ?? mlab_smtp_get( $key ) );
	$placeholder = $args['placeholder'];
	if ( $secret && '' !== (string) mlab_smtp_get( $key ) ) {
		$placeholder = __( 'Gespeichert – leer lassen, um zu behalten', 'mlab-smtp' );
	}
	printf(
		'<input type="%1$s" value="%2$s" placeholder="%3$s" autocomplete="off"%4$s%5$s>',
		esc_attr( $args['type'] ),
		esc_attr( $value ),
		esc_attr( $placeholder ),
		$args['readonly'] ? ' readonly' : '',
		mlab_smtp_ui_common_attrs( $id, $key, $args, $locked ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
	);
}

/**
 * Prints the select of a field.
 *
 * @param string $id     Element id.
 * @param string $key    Field name.
 * @param array  $args   Field arguments; options may be a value => label list.
 * @param bool   $locked Whether a constant fixes the value.
 */
function mlab_smtp_ui_select( string $id, string $key, array $args, bool $locked ): void {
	$current = (string) ( $args['value'] ?? mlab_smtp_get( $key ) );
	echo '<select' . mlab_smtp_ui_common_attrs( $id, $key, $args, $locked ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
	foreach ( $args['options'] as $value => $label ) {
		printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $value ), selected( $current, (string) $value, false ), esc_html( $label ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a safe attribute.
	}
	echo '</select>';
}

/**
 * Prints a toggle switch for a checkbox setting.
 *
 * @param string $key   Setting key, also the field name.
 * @param string $label Text next to the switch.
 * @param string $panel Optional id of a panel that JavaScript shows only while the switch is on.
 */
function mlab_smtp_ui_switch( string $key, string $label, string $panel = '' ): void {
	$locked = mlab_smtp_is_locked( $key );
	printf(
		'<label class="mlab-switch"><input type="checkbox" name="%1$s" value="1"%2$s%3$s%4$s> <span>%5$s</span></label>',
		esc_attr( $key ),
		checked( (bool) mlab_smtp_get( $key ), true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a safe attribute.
		$locked ? ' disabled' : '',
		'' !== $panel ? ' data-mlab-toggle="' . esc_attr( $panel ) . '"' : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped here.
		esc_html( $label )
	);
	if ( $locked ) {
		mlab_smtp_ui_lock_note();
	}
}

/**
 * Prints a segmented radio group for a setting.
 *
 * @param string $key     Setting key, also the field name.
 * @param string $legend  Group label.
 * @param array  $options value => label.
 */
function mlab_smtp_ui_segmented( string $key, string $legend, array $options ): void {
	$current = (string) mlab_smtp_get( $key );
	$locked  = mlab_smtp_is_locked( $key );
	echo '<fieldset class="mlab-field mlab-seg"><legend>' . esc_html( $legend ) . '</legend><div class="mlab-seg-group">';
	foreach ( $options as $value => $label ) {
		printf(
			'<label><input type="radio" name="%1$s" value="%2$s"%3$s%4$s><span>%5$s</span></label>',
			esc_attr( $key ),
			esc_attr( (string) $value ),
			checked( $current, (string) $value, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a safe attribute.
			$locked ? ' disabled' : '',
			esc_html( $label )
		);
	}
	echo '</div>';
	if ( $locked ) {
		mlab_smtp_ui_lock_note();
	}
	echo '</fieldset>';
}

/**
 * Opens a section card of the settings view: title and description left, fields right.
 *
 * @param string $title Section title.
 * @param string $desc  Short description.
 */
function mlab_smtp_ui_row_open( string $title, string $desc ): void {
	echo '<section class="mlab-card mlab-set"><div class="mlab-set-head"><h2>' . esc_html( $title ) . '</h2><p class="mlab-muted">' . esc_html( $desc ) . '</p></div><div class="mlab-set-body">';
}

/**
 * Closes a section opened with mlab_smtp_ui_row_open().
 */
function mlab_smtp_ui_row_close(): void {
	echo '</div></section>';
}

/**
 * Splits a raw error into a message for people and the technical original.
 *
 * @param string $raw Raw error text from the backend.
 * @return array{message:string, detail:string}
 */
function mlab_smtp_ui_error_texts( string $raw ): array {
	$raw  = mlab_smtp_redact( $raw );
	$hint = mlab_smtp_explain_error( $raw );
	return array(
		'message' => '' !== $hint ? $hint : $raw,
		'detail'  => $raw,
	);
}

/**
 * Warning that appears next to the encryption choice while «none» is selected (CSS only, no script needed).
 *
 * @param string $field Name of the field: smtp_secure or fb_smtp_secure.
 */
function mlab_smtp_render_insecure_note( string $field ): void {
	echo '<div class="mlab-insecure" data-for="' . esc_attr( $field ) . '" role="alert">';
	mlab_smtp_ui_hint( 'bad', __( 'Nicht empfohlen: keine Verschlüsselung', 'mlab-smtp' ), mlab_smtp_insecure_text() );
	echo '</div>';
}
