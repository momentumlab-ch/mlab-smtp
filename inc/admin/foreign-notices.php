<?php
/**
 * Mitteilungen anderer Plugins: bleiben ausserhalb der Oberfläche dieses Plugins.
 *
 * WordPress schiebt Hinweise (admin_notices) per JavaScript direkt unter den Seitentitel, also mitten in unsere
 * Seite. Auf der Plugin-Seite fangen wir sie deshalb ab und zeigen sie eingeklappt unterhalb der Seite, mit Anzahl.
 * Nichts geht verloren: Wer sie sehen will, klappt die Leiste auf.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

add_action( 'load-' . MLAB_SMTP_SCREEN, 'mlab_smtp_capture_foreign_notices' );

/**
 * Starts catching the notices of other plugins. Runs before the admin header prints them.
 */
function mlab_smtp_capture_foreign_notices(): void {
	add_action( 'admin_notices', 'mlab_smtp_foreign_start', PHP_INT_MIN );
	add_action( 'all_admin_notices', 'mlab_smtp_foreign_end', PHP_INT_MAX );
}

/**
 * Opens the output buffer in front of all other notice callbacks.
 */
function mlab_smtp_foreign_start(): void {
	mlab_smtp_foreign_buffer( 'start' );
}

/**
 * Closes the output buffer after the last notice callback.
 */
function mlab_smtp_foreign_end(): void {
	mlab_smtp_foreign_buffer( 'end' );
}

/**
 * Output buffer around the notice hooks.
 *
 * @param string $action start, end or get.
 * @return string Caught HTML (after end), otherwise empty.
 */
function mlab_smtp_foreign_buffer( string $action ): string {
	static $level = null;
	static $html  = '';
	if ( 'start' === $action ) {
		$level = ob_get_level();
		ob_start();
	} elseif ( 'end' === $action && null !== $level ) {
		// A plugin that forgot to close its own buffer must not swallow the rest of the page.
		while ( ob_get_level() > $level + 1 ) {
			ob_end_flush();
		}
		$html  = (string) ob_get_clean();
		$level = null;
	}
	return $html;
}

/**
 * Marks the notices as "inline" so that WordPress leaves them where they are, and counts them.
 *
 * @param string $html Caught HTML.
 * @return array{html:string, count:int, errors:int}
 */
function mlab_smtp_prepare_foreign_notices( string $html ): array {
	$count     = 0;
	$errors    = 0;
	$processor = new WP_HTML_Tag_Processor( $html );
	while ( $processor->next_tag( 'div' ) ) {
		if ( ! $processor->has_class( 'notice' ) && ! $processor->has_class( 'updated' ) && ! $processor->has_class( 'error' ) ) {
			continue;
		}
		$processor->add_class( 'inline' );
		++$count;
		$errors += ( $processor->has_class( 'notice-error' ) || $processor->has_class( 'error' ) ) ? 1 : 0;
	}
	return array(
		'html'   => $processor->get_updated_html(),
		'count'  => $count,
		'errors' => $errors,
	);
}

/**
 * Prints the caught notices in a collapsed bar below the plugin page. Prints nothing when there are none.
 */
function mlab_smtp_render_foreign_notices(): void {
	$html = trim( mlab_smtp_foreign_buffer( 'get' ) );
	if ( '' === $html ) {
		return;
	}
	$notices = mlab_smtp_prepare_foreign_notices( $html );
	$label   = __( 'Mitteilungen anderer Plugins', 'mlab-smtp' );
	echo '<aside class="mlab-foreign" aria-label="' . esc_attr( $label ) . '"><details><summary>' . esc_html( $label );
	if ( $notices['count'] > 0 ) {
		echo ' <span class="mlab-foreign-count">' . esc_html( (string) $notices['count'] ) . '</span>';
	}
	if ( $notices['errors'] > 0 ) {
		/* translators: %d: number of error messages of other plugins */
		echo ' <span class="mlab-foreign-bad">' . esc_html( sprintf( _n( '%d Fehler', '%d Fehler', $notices['errors'], 'mlab-smtp' ), $notices['errors'] ) ) . '</span>';
	}
	echo '</summary><div class="mlab-foreign-list">';
	echo $notices['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup of other plugins, WordPress prints it unescaped as well; escaping would break it.
	echo '</div></details></aside>';
}
