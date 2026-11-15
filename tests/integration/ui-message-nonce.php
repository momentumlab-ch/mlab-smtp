<?php
// Aktionen auf einer Nachricht: Der Nonce gilt nur für genau diese Aktion auf genau dieser Nachricht.
require dirname( __DIR__ ) . '/bootstrap-wp.php';
require dirname( __DIR__ ) . '/lib/ui-helpers.php';

class UN_Redirect extends RuntimeException {}
class UN_Die extends RuntimeException {}
add_filter( 'wp_redirect', static function ( $location ) {
	throw new UN_Redirect( (string) $location );
} );
add_filter( 'wp_die_handler', static function () {
	return static function ( $message, $title = '', $args = array() ) {
		throw new UN_Die( (string) ( is_array( $args ) ? ( $args['response'] ?? 0 ) : 0 ) );
	};
} );

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
ui_all_ok();
$a = mlab_t_msg( array( 'status' => 'unclear', 'subject' => 'Nonce A' ) );
$b = mlab_t_msg( array( 'status' => 'unclear', 'subject' => 'Nonce B' ) );

$run = static function ( string $do, int $id, ?string $nonce_action ) {
	$_REQUEST = $_POST = array( 'do' => $do, 'id' => (string) $id, 'back' => array( 'view' => 'queue' ) );
	if ( null !== $nonce_action ) {
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'] = wp_create_nonce( $nonce_action );
	}
	try {
		mlab_smtp_handle_message_action();
	} catch ( UN_Redirect $e ) {
		return 'redirect';
	} catch ( UN_Die $e ) {
		return 'die:' . $e->getMessage();
	}
	return 'none';
};

// 1. Das Formular trägt einen Nonce, der nur für diese Aktion und diese Nachricht gilt.
ob_start();
mlab_smtp_render_action_form( $a, 'delivered', 'Als zugestellt markieren', array( 'view' => 'queue' ), true );
$html = ob_get_clean();
assert_true( 1 === preg_match( '/name="_wpnonce" value="([^"]+)"/', $html, $m ), 'form carries a nonce' );
$nonce = $m[1] ?? '';
assert_true( (bool) wp_verify_nonce( $nonce, mlab_smtp_message_nonce_action( $a, 'delivered' ) ), 'nonce is valid for this action on this message' );
assert_true( ! wp_verify_nonce( $nonce, mlab_smtp_message_nonce_action( $a, 'resend' ) ), 'nonce is not valid for another action' );
assert_true( ! wp_verify_nonce( $nonce, mlab_smtp_message_nonce_action( $b, 'delivered' ) ), 'nonce is not valid for another message' );
assert_true( ! wp_verify_nonce( $nonce, 'mlab_smtp_message_action' ), 'nonce is not the old general one' );
assert_true( str_contains( $html, 'name="action" value="mlab_smtp_message_action"' ), 'form posts to the handler' );

// 2. Der Link für das Protokoll bindet ebenso.
parse_str( (string) wp_parse_url( html_entity_decode( mlab_smtp_engine_action_url( $a, 'retry' ) ), PHP_URL_QUERY ), $q );
assert_true( (bool) wp_verify_nonce( $q['_wpnonce'] ?? '', mlab_smtp_message_nonce_action( $a, 'retry' ) ), 'link nonce is valid for its own action' );
assert_true( ! wp_verify_nonce( $q['_wpnonce'] ?? '', mlab_smtp_message_nonce_action( $b, 'retry' ) ), 'link nonce is not valid for another message' );

// 3. Der Handler lehnt falsch gebundene Nonces ab, ohne etwas zu ändern.
assert_same( 'die:403', $run( 'resend', $a, mlab_smtp_message_nonce_action( $a, 'delivered' ) ), 'nonce of «delivered» does not allow «resend»' );
assert_same( 'die:403', $run( 'delivered', $b, mlab_smtp_message_nonce_action( $a, 'delivered' ) ), 'nonce of message A does not allow message B' );
assert_same( 'die:403', $run( 'delivered', $a, 'mlab_smtp_message_action' ), 'the old general nonce is refused' );
assert_same( 'die:403', $run( 'delivered', $a, null ), 'no nonce is refused' );
assert_same( 'unclear', mlab_smtp_store_get( $a )['status'], 'message A untouched after refused requests' );
assert_same( 'unclear', mlab_smtp_store_get( $b )['status'], 'message B untouched after refused requests' );

// 4. Ungültige Angaben werden vor allem anderen abgelehnt.
assert_same( 'die:400', $run( 'evil', $a, mlab_smtp_message_nonce_action( $a, 'evil' ) ), 'unknown operation is refused with 400' );
assert_same( 'die:400', $run( 'delivered', 0, mlab_smtp_message_nonce_action( 0, 'delivered' ) ), 'id 0 is refused with 400' );

// 5. Der richtige Nonce führt genau diese Aktion aus.
assert_same( 'redirect', $run( 'delivered', $a, mlab_smtp_message_nonce_action( $a, 'delivered' ) ), 'matching nonce runs the action and returns' );
assert_same( 'accepted', mlab_smtp_store_get( $a )['status'], 'message A is marked as accepted (manual mark, no provider proof)' );
assert_same( 'unclear', mlab_smtp_store_get( $b )['status'], 'message B is still untouched' );

// 6. Ohne Berechtigung hilft auch ein richtiger Nonce nicht.
$uid = wp_insert_user( array( 'user_login' => 'sub_nonce_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $uid );
assert_same( 'die:403', $run( 'delivered', $b, mlab_smtp_message_nonce_action( $b, 'delivered' ) ), 'a subscriber is refused even with a valid nonce' );
assert_same( 'unclear', mlab_smtp_store_get( $b )['status'], 'message B untouched by the subscriber' );
wp_delete_user( $uid );

t_finish();
