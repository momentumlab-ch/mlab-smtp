<?php
/**
 * Shared render helpers of the admin pages.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Display data of the three providers.
 *
 * @return array<string, array{name:string, color:string, letter:string}>
 */
function mlab_smtp_ui_providers(): array {
	return array(
		'm365'   => array(
			'name'   => __( 'Microsoft 365', 'mlab-smtp' ),
			'color'  => '#0f6cbd',
			'letter' => 'M',
		),
		'google' => array(
			'name'   => __( 'Google Workspace', 'mlab-smtp' ),
			'color'  => '#1e8e3e',
			'letter' => 'G',
		),
		'smtp'   => array(
			'name'   => __( 'Eigener Mailserver', 'mlab-smtp' ),
			'color'  => '#50575e',
			'letter' => '@',
		),
	);
}

/**
 * Prints the round provider logo.
 *
 * @param string $provider m365, google or smtp.
 */
function mlab_smtp_ui_logo( string $provider ): void {
	$meta = mlab_smtp_ui_providers()[ $provider ] ?? mlab_smtp_ui_providers()['smtp'];
	printf(
		'<span class="mlab-logo" style="background:%1$s" aria-hidden="true">%2$s</span>',
		esc_attr( $meta['color'] ),
		esc_html( $meta['letter'] )
	);
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
 * @param string $level ok, warn or bad.
 */
function mlab_smtp_ui_icon( string $level ): string {
	$icons = array(
		'ok'   => '✓',
		'warn' => '!',
		'bad'  => '✕',
	);
	return $icons[ $level ] ?? '!';
}

/**
 * Prints a small coloured label.
 *
 * @param string $level ok, warn, bad or neu.
 * @param string $label Text.
 */
function mlab_smtp_ui_pill( string $level, string $label ): void {
	echo '<span class="mlab-pill is-' . esc_attr( $level ) . '">' . esc_html( $label ) . '</span>';
}

/**
 * Prints the result pill of a log entry.
 *
 * @param array $entry Log entry.
 */
function mlab_smtp_ui_entry_pill( array $entry ): void {
	if ( ! empty( $entry['ok'] ) && empty( $entry['fallback'] ) ) {
		mlab_smtp_ui_pill( 'ok', __( 'Zugestellt', 'mlab-smtp' ) );
	} elseif ( ! empty( $entry['ok'] ) ) {
		mlab_smtp_ui_pill( 'warn', __( 'Ersatzweg', 'mlab-smtp' ) );
	} else {
		mlab_smtp_ui_pill( 'bad', __( 'Fehlgeschlagen', 'mlab-smtp' ) );
	}
}

/**
 * Prints a coloured hint box with an icon.
 *
 * @param string $level ok, warn, bad or info.
 * @param string $title Bold first line.
 * @param string $text  Optional second line.
 */
function mlab_smtp_ui_hint( string $level, string $title, string $text = '' ): void {
	echo '<div class="mlab-hint is-' . esc_attr( $level ) . '"><span class="mlab-ic is-' . esc_attr( $level ) . '" aria-hidden="true">' . esc_html( mlab_smtp_ui_icon( $level ) ) . '</span><div><strong>' . esc_html( $title ) . '</strong>';
	if ( '' !== $text ) {
		echo '<p>' . esc_html( $text ) . '</p>';
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
	echo '<div class="mlab-copy"><code>' . esc_html( $text ) . '</code><button type="button" class="button-link" data-mlab-copy="' . esc_attr( $text ) . '">' . esc_html__( 'Kopieren', 'mlab-smtp' ) . '</button></div>';
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
 * Opens a row of the settings view: title and description left, content right.
 *
 * @param string $title Row title.
 * @param string $desc  Short description.
 */
function mlab_smtp_ui_row_open( string $title, string $desc ): void {
	echo '<div class="mlab-set"><div><h3>' . esc_html( $title ) . '</h3><p class="mlab-muted">' . esc_html( $desc ) . '</p></div><div>';
}

/**
 * Closes a row opened with mlab_smtp_ui_row_open().
 */
function mlab_smtp_ui_row_close(): void {
	echo '</div></div>';
}

/**
 * Splits a raw error into a message for people and the technical original.
 *
 * @param string $raw Raw error text from the backend.
 * @return array{message:string, detail:string}
 */
function mlab_smtp_ui_error_texts( string $raw ): array {
	$hint = mlab_smtp_explain_error( $raw );
	return array(
		'message' => '' !== $hint ? $hint : $raw,
		'detail'  => $raw,
	);
}
