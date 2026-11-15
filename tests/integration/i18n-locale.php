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
$dir = dirname( __DIR__, 2 ) . '/languages';
foreach ( array( 'en_US' => 'Übersicht', 'fr_FR' => 'Übersicht', 'it_IT' => 'Übersicht' ) as $locale => $source ) {
	if ( ! is_file( "$dir/mlab-smtp-$locale.mo" ) ) {
		echo "SKIP: mlab-smtp-$locale.mo fehlt noch\n";
		continue;
	}
	unload_textdomain( 'mlab-smtp' );
	switch_to_locale( $locale );
	mlab_smtp_load_textdomain();
	assert_true( __( $source, 'mlab-smtp' ) !== $source, "{$locale}: «{$source}» wird übersetzt" ); // phpcs:ignore WordPress.WP.I18n -- Test.
	restore_previous_locale();
}
t_finish();
