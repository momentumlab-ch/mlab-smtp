<?php
// Datenschutz: Exporter, Eraser, Textvorschlag.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/store-helpers.php';
global $wpdb;
mlab_t_reset_store();

$a = mlab_t_msg( array( 'status' => 'accepted', 'recipients' => array( 'User@Example.test', 'other@example.test' ), 'subject' => 'Hallo' ) );
$b = mlab_t_msg( array( 'status' => 'failed', 'recipients' => array( 'user@example.test' ), 'subject' => 'Zweite' ) );
$c = mlab_t_msg( array( 'status' => 'accepted', 'recipients' => array( 'other@example.test' ) ) );
mlab_smtp_store_add_attempt( $a, array( 'route' => 'primary' ) );
mlab_smtp_store_add_event( array( 'provider' => 'resend', 'event_id' => 'e1', 'message_id' => $a, 'detail' => 'user@example.test' ) );
mlab_smtp_store_set_payload( $b, array( 'to' => 'user@example.test' ) );

assert_true( isset( apply_filters( 'wp_privacy_personal_data_exporters', array() )['mlab-smtp'] ), 'exporter registered' );
assert_true( isset( apply_filters( 'wp_privacy_personal_data_erasers', array() )['mlab-smtp'] ), 'eraser registered' );
$x = mlab_smtp_privacy_export( 'USER@example.test', 1 );
assert_same( 2, count( $x['data'] ), 'export finds both messages case-insensitively' );
assert_true( $x['done'], 'export done' );
$flat = wp_json_encode( $x['data'] );
assert_true( str_contains( $flat, 'Hallo' ) && str_contains( $flat, 'accepted' ) && str_contains( $flat, 'primary' ), 'export has subject, status and route' );
assert_true( ! str_contains( $flat, 'other@example.test' ), 'export does not leak other recipients' );
assert_same( 0, count( mlab_smtp_privacy_export( 'nobody@example.test', 1 )['data'] ), 'unknown address exports nothing' );

$e = mlab_smtp_privacy_erase( 'user@example.test', 1 );
assert_same( 2, $e['items_removed'], 'eraser removes both messages' );
assert_true( $e['done'] && ! $e['items_retained'], 'eraser done, nothing retained' );
assert_same( null, mlab_smtp_store_get( $a ), 'message deleted' );
assert_same( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'attempts' ) ), 'attempts deleted' );
assert_same( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . mlab_smtp_table( 'events' ) ), 'events deleted' );
assert_same( array(), mlab_smtp_store_ids_by_email( 'user@example.test' ), 'recipient index deleted' );
assert_same( null, mlab_smtp_store_get_payload( $b ), 'payload deleted with the message' );
assert_true( null !== mlab_smtp_store_get( $c ), 'unrelated message stays' );

// Textvorschlag.
require_once ABSPATH . 'wp-admin/includes/admin.php';
set_current_screen( 'dashboard' );
$GLOBALS['wp_actions']['admin_init'] = 1;
mlab_smtp_privacy_policy_content();
$content = WP_Privacy_Policy_Content::get_suggested_policy_text();
assert_true( str_contains( wp_json_encode( $content ), 'MomentumLab SMTP' ), 'privacy policy suggestion registered' );
t_finish();
