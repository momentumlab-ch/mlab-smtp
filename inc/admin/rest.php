<?php
/**
 * REST routes of the admin pages: save, check connection, test mail.
 *
 * Wizard step 2 and the fallback section call /save first (explicit key group, same lists as the
 * admin-post handlers), then /check. Both answer HTTP 200 with ok true or false so admin.js has one path.
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'mlab_smtp_register_routes' );

/**
 * Route definitions: path => callback and argument schema.
 *
 * @return array<string, array>
 */
function mlab_smtp_route_definitions(): array {
	return array(
		'/save'  => array(
			'callback' => 'mlab_smtp_rest_save',
			'args'     => array(
				'group'  => array(
					'type'     => 'string',
					'required' => true,
					'enum'     => array( 'smtp', 'm365', 'google', 'sender', 'fallback' ),
				),
				'fields' => array(
					'type'     => 'object',
					'required' => true,
				),
			),
		),
		'/check' => array(
			'callback' => 'mlab_smtp_rest_check',
			'args'     => array(
				'route' => array(
					'type'    => 'string',
					'default' => 'primary',
					'enum'    => array( 'primary', 'fallback' ),
				),
			),
		),
		'/test'  => array(
			'callback' => 'mlab_smtp_rest_test',
			'args'     => array(
				'to' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		),
	);
}

/**
 * Registers the routes below mlab-smtp/v1.
 */
function mlab_smtp_register_routes(): void {
	foreach ( mlab_smtp_route_definitions() as $path => $route ) {
		register_rest_route(
			'mlab-smtp/v1',
			$path,
			array_merge(
				$route,
				array(
					'methods'             => 'POST',
					'permission_callback' => 'mlab_smtp_rest_permission',
				)
			)
		);
	}
}

/**
 * Only administrators may call the routes; the wp_rest nonce is checked by core.
 */
function mlab_smtp_rest_permission(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * POST /save: saves one key group.
 *
 * @param WP_REST_Request $request Request with group and fields.
 */
function mlab_smtp_rest_save( WP_REST_Request $request ): array {
	$errors = mlab_smtp_save_group( (string) $request['group'], (array) $request['fields'] );
	return array(
		'ok'      => ! $errors,
		'message' => implode( ' ', $errors ),
	);
}

/**
 * POST /check: checks the connection of one route without sending a mail.
 *
 * @param WP_REST_Request $request Request with route.
 */
function mlab_smtp_rest_check( WP_REST_Request $request ): array {
	return mlab_smtp_run_check( (string) $request['route'] );
}

/**
 * POST /test: sends a test mail.
 *
 * @param WP_REST_Request $request Request with to.
 */
function mlab_smtp_rest_test( WP_REST_Request $request ): array {
	return mlab_smtp_run_test_request( (string) $request['to'] );
}

/**
 * Checks a connection and turns the backend result into a message for the UI.
 *
 * @param string $route primary or fallback.
 * @return array{ok:bool, message:string, detail:string}
 */
function mlab_smtp_run_check( string $route ): array {
	$route  = 'fallback' === $route ? 'fallback' : 'primary';
	$result = mlab_smtp_check_connection( $route );
	if ( 'primary' === $route ) {
		mlab_smtp_wizard_flag( 'verified', true === $result );
	}
	if ( true === $result ) {
		return array(
			'ok'      => true,
			'message' => mlab_smtp_check_success_text( $route ),
			'detail'  => '',
		);
	}
	$texts = mlab_smtp_ui_error_texts( $result->get_error_message() );
	return array(
		'ok'      => false,
		'message' => $texts['message'],
		'detail'  => $texts['detail'],
	);
}

/**
 * Success text of a connection check.
 *
 * @param string $route primary or fallback.
 */
function mlab_smtp_check_success_text( string $route ): string {
	if ( 'fallback' === $route ) {
		return __( 'Ersatzweg erreichbar. Die Anmeldung am Server war erfolgreich.', 'mlab-smtp' );
	}
	$texts = array(
		'm365'   => __( 'Verbunden. Microsoft hat die Zugangsdaten akzeptiert.', 'mlab-smtp' ),
		'google' => __( 'Verbunden. Google hat den Zugriff bestätigt.', 'mlab-smtp' ),
		'smtp'   => __( 'Die Anmeldung am Server war erfolgreich.', 'mlab-smtp' ),
	);
	return $texts[ mlab_smtp_get( 'provider' ) ] ?? $texts['smtp'];
}

/**
 * Sends a test mail and turns the backend result into a message for the UI.
 *
 * @param string $to Recipient as typed.
 * @return array{ok:bool, message:string, detail:string}
 */
function mlab_smtp_run_test_request( string $to ): array {
	$to = sanitize_email( $to );
	if ( '' === $to ) {
		return array(
			'ok'      => false,
			'message' => __( 'Bitte geben Sie eine gültige E-Mail-Adresse ein.', 'mlab-smtp' ),
			'detail'  => '',
		);
	}
	$result = mlab_smtp_run_test( $to );
	if ( $result['sent'] ) {
		mlab_smtp_wizard_flag( 'tested', true );
		return array(
			'ok'      => true,
			/* translators: %s: recipient address */
			'message' => sprintf( __( 'Testmail an %s versendet. Bitte prüfen Sie den Posteingang. Nicht da? Schauen Sie auch im Spam-Ordner.', 'mlab-smtp' ), $to ),
			'detail'  => '',
		);
	}
	mlab_smtp_wizard_flag( 'tested', false );
	return array(
		'ok'      => false,
		'message' => '' !== $result['hint'] ? $result['hint'] : $result['error'],
		'detail'  => trim( $result['error'] . "\n" . $result['debug'] ),
	);
}
