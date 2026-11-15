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
