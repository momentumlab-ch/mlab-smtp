<?php
// Sprachwahl: welche Übersetzungsdatei bedient welche WordPress-Sprache.
require dirname( __DIR__ ) . '/bootstrap-wp.php';

$map = array(
	'de_DE' => 'de_DE', 'de_CH' => 'de_CH', 'de_CH_informal' => 'de_CH_informal', // Quellsprache: keine Datei, unverändert
	'en_US' => 'en_US', 'en_GB' => 'en_US', 'en_AU' => 'en_US',
	'fr_FR' => 'fr_FR', 'fr_CH' => 'fr_FR', 'fr_BE' => 'fr_FR', 'fr_CA' => 'fr_FR',
	'it_IT' => 'it_IT', 'it_CH' => 'it_IT',
	'es_ES' => 'en_US', 'nl_NL' => 'en_US', 'ja' => 'en_US', // nicht unterstützt: Englisch statt Deutsch
);
foreach ( $map as $locale => $want ) {
	assert_same( $want, mlab_smtp_map_locale( $locale ), "locale {$locale} -> {$want}" );
}
assert_same( 'fr_FR', apply_filters( 'plugin_locale', 'fr_CH', 'mlab-smtp' ), 'plugin_locale filter maps for this plugin' );
assert_same( 'fr_CH', apply_filters( 'plugin_locale', 'fr_CH', 'andere-domain' ), 'plugin_locale filter leaves other text domains alone' );

// Sind die Dateien da, müssen sie wirklich laden und übersetzen.
// Die Sprache wird über den Filter «locale» gesetzt: switch_to_locale() wechselt nur zu installierten WordPress-Sprachen.
foreach ( array( 'en_US' => 'Overview', 'fr_FR' => 'Aperçu', 'it_IT' => 'Panoramica' ) as $locale => $expected_start ) {
	if ( ! is_file( "$dir/mlab-smtp-$locale.mo" ) ) {
		echo "SKIP: mlab-smtp-$locale.mo fehlt noch\n";
		continue;
	}
	$set = static fn() => $locale;
	add_filter( 'locale', $set, 100 );
	unload_textdomain( 'mlab-smtp' );
	mlab_smtp_load_textdomain();
	$text = __( 'Übersicht', 'mlab-smtp' ); // phpcs:ignore WordPress.WP.I18n -- Test.
	assert_true( '' !== $text && 'Übersicht' !== $text, "{$locale}: «Übersicht» wird übersetzt ({$text})" );
	remove_filter( 'locale', $set, 100 );
}
// Zurück zur Vorgabe der Tests.
unload_textdomain( 'mlab-smtp' );
mlab_smtp_load_textdomain();
assert_same( 'Übersicht', __( 'Übersicht', 'mlab-smtp' ), 'back to German source texts' ); // phpcs:ignore WordPress.WP.I18n -- Test.
t_finish();
