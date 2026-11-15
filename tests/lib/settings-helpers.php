<?php
// Hilfen für die settings-*-Tests: Rendern, Handler-Aufruf mit Nonce.

class ST_Die extends RuntimeException {}
class ST_Redirect extends RuntimeException {}
add_filter( 'wp_die_handler', static function () {
	return static function ( $message, $title = '', $args = array() ) {
		throw new ST_Die( (string) ( is_array( $args ) ? ( $args['response'] ?? 0 ) : 0 ) );
	};
} );
add_filter( 'wp_redirect', static function ( $location ) {
	throw new ST_Redirect( (string) $location );
} );
add_filter( 'mlab_smtp_resolve_host', static fn( $pre, $host ) => 'hook.example.test' === $host ? array( '93.184.216.34' ) : $pre, 10, 2 );

function st_admin(): int {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	wp_set_current_user( $admins[0]->ID );
	return (int) $admins[0]->ID;
}

function st_render(): string {
	ob_start();
	mlab_smtp_render_settings();
	return (string) ob_get_clean();
}

/** Runs an admin-post handler; returns die:<code>, redirect:<url> or none. */
function st_call( string $fn, ?string $nonce_action, array $post = array() ): string {
	$_REQUEST = $_POST = $post;
	if ( $nonce_action ) {
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'] = wp_create_nonce( $nonce_action );
	}
	try {
		$fn();
	} catch ( ST_Die $e ) {
		return 'die:' . $e->getMessage();
	} catch ( ST_Redirect $e ) {
		return 'redirect:' . $e->getMessage();
	}
	return 'none';
}
