<?php
// Plugin details show the real bundled screenshot without contacting an image host.
require dirname( __DIR__ ) . '/bootstrap-wp.php';

assert_true( false !== has_filter( 'puc_request_info_result-mlab-smtp', 'mlab_smtp_plugin_details' ), 'the screenshot filter is registered for this plugin only' );
assert_same( null, mlab_smtp_plugin_details( null ), 'failed metadata requests remain failures' );
$info = (object) array( 'version' => MLAB_SMTP_VERSION, 'sections' => array( 'description' => '<p>Provider overview</p>', 'changelog' => 'Existing changes' ) );
$info = apply_filters( 'puc_request_info_result-mlab-smtp', $info, null );
$image = plugins_url( 'assets/screenshots/screenshot-1.png', MLAB_SMTP_FILE );
assert_true( str_contains( $info->sections['description'], '<p>Provider overview</p>' ), 'the parsed readme description is preserved' );
assert_same( 'Existing changes', $info->sections['changelog'], 'the changelog is preserved' );
assert_true( str_contains( $info->sections['description'], esc_url( $image ) ), 'the description includes the local screenshot' );
assert_true( str_contains( $info->sections['screenshots'], esc_url( $image ) ), 'the screenshots tab uses the same local screenshot' );
assert_true( ! str_contains( $info->sections['screenshots'], 'githubusercontent.com' ), 'opening the screenshot does not require a GitHub image request' );
$size = getimagesize( MLAB_SMTP_DIR . 'assets/screenshots/screenshot-1.png' );
assert_true( is_array( $size ) && $size[0] > 0 && $size[1] > 0, 'the bundled screenshot is a valid image' );
assert_same( MLAB_SMTP_VERSION, $info->version, 'the metadata version is unchanged by the screenshot filter' );

// Registering the updater repeatedly must not register additional checker instances.
global $wp_filter;
$before = count( $wp_filter['admin_init']->callbacks[10] ?? array() );
mlab_smtp_register_updater();
mlab_smtp_register_updater();
assert_same( $before, count( $wp_filter['admin_init']->callbacks[10] ?? array() ), 'repeated updater registration does not add another admin checker' );
$url = admin_url( 'plugins.php?puc_check_for_updates=1&puc_slug=mlab-smtp' );
$one = '<a href="' . esc_url( $url . '&_wpnonce=one' ) . '">Check for updates</a>';
$two = '<a href="' . esc_url( $url . '&_wpnonce=two' ) . '">Check for updates</a>';
$other = '<a href="' . esc_url( admin_url( 'plugins.php?puc_check_for_updates=1&puc_slug=other-plugin' ) ) . '">Other update</a>';
$links = array( 'Version', $one, 'Details', $two, $other );
$result = mlab_smtp_unique_update_link( $links, plugin_basename( MLAB_SMTP_FILE ) );
assert_same( array( 'Version', $one, 'Details', $other ), array_values( $result ), 'only this plugin duplicate manual update action is removed' );
assert_same( $links, mlab_smtp_unique_update_link( $links, 'other/plugin.php' ), 'other plugin rows are preserved' );
assert_same( $result, mlab_smtp_unique_update_link( $result, plugin_basename( MLAB_SMTP_FILE ) ), 'deduplication is idempotent' );

foreach ( array( 'assets/icon-128x128.png' => array( 128, 128 ), 'assets/icon-256x256.png' => array( 256, 256 ), 'assets/banner-772x250.png' => array( 772, 250 ), 'assets/banner-1544x500.png' => array( 1544, 500 ) ) as $file => $expected ) {
	$size = getimagesize( MLAB_SMTP_DIR . $file );
	assert_same( $expected, array( $size[0], $size[1] ), "$file has its declared dimensions" );
}
assert_contains( 'banner-772x250.png', $info->banners['low'], 'the low-density banner uses the small asset' );
assert_contains( 'banner-1544x500.png', $info->banners['high'], 'the high-density banner uses the large asset' );
