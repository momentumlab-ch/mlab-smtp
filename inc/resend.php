<?php
/**
 * Resend: Versand über die REST-API, Prüfung des API-Keys und der Absender-Domain.
 *
 * Der Key wird im Assistenten eingegeben und verschlüsselt gespeichert. Ein Key mit «Sending access» darf nur
 * Mails senden: Die Domain-Abfrage antwortet dann mit restricted_api_key. Das gilt als gültiger Key, nur die
 * Domain lässt sich nicht vorab prüfen (die Testmail zeigt es).
 *
 * @package mlab-smtp
 */

defined( 'ABSPATH' ) || exit;

const MLAB_SMTP_RESEND_API        = 'https://api.resend.com';
const MLAB_SMTP_RESEND_DOMAINS    = 'https://resend.com/domains';
const MLAB_SMTP_RESEND_LIMIT      = 40 * MB_IN_BYTES; // Attachments after Base64 encoding.
const MLAB_SMTP_RESEND_RECIPIENTS = 50;               // Largest "to" list of one request.

add_action( 'admin_post_mlab_smtp_resend_recheck', 'mlab_smtp_resend_recheck' );

/**
 * Setting that holds the API key of the current request: the main key, or the one of the fallback while the
 * fallback is checked or sends.
 *
 * @param string|null $set Setting key, null to only read.
 */
function mlab_smtp_resend_key_name( ?string $set = null ): string {
	static $name = 'resend_api_key';
	if ( null !== $set ) {
		$name = $set;
	}
	return $name;
}

/**
 * Runs a callback with another API key setting and restores the previous one afterwards.
 *
 * @param string   $name     Setting key, resend_api_key or fb_resend_api_key.
 * @param callable $callback Work to do.
 * @return mixed Result of the callback.
 */
function mlab_smtp_with_resend_key( string $name, callable $callback ) {
	$previous = mlab_smtp_resend_key_name();
	mlab_smtp_resend_key_name( $name );
	try {
		return $callback();
	} finally {
		mlab_smtp_resend_key_name( $previous );
	}
}

/**
 * Registry entry «fb_send»: sends through Resend as the fallback. Resend only sends from a verified domain, so the
 * stored sender address replaces whatever the message came with.
 *
 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Prepared message.
 * @return true|WP_Error
 */
function mlab_smtp_send_resend_fallback( $mailer ) {
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property names are the PHPMailer API.
	if ( is_email( (string) mlab_smtp_get( 'from_email' ) ) ) {
		$mailer->From = (string) mlab_smtp_get( 'from_email' );
		if ( '' !== (string) mlab_smtp_get( 'from_name' ) ) {
			$mailer->FromName = (string) mlab_smtp_get( 'from_name' );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	return mlab_smtp_with_resend_key(
		'fb_resend_api_key',
		static function () use ( $mailer ) {
			return mlab_smtp_send_resend( $mailer );
		}
	);
}

/**
 * Arguments of a request to the Resend API: authentication, content type and a generic user agent (Resend
 * rejects requests without one).
 *
 * @param string $method  GET or POST.
 * @param string $key     API key.
 * @param array  $headers Extra headers.
 */
function mlab_smtp_resend_request_args( string $method, string $key, array $headers ): array {
	return array(
		'method'  => $method,
		'timeout' => MLAB_SMTP_TIMEOUT,
		'headers' => array_merge(
			array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'User-Agent'    => 'mlab-smtp/' . MLAB_SMTP_VERSION,
			),
			$headers
		),
	);
}

/**
 * Sends one request to the Resend API with the stored key.
 *
 * Errors carry the HTTP status and the Resend error name as error data: array{status:int, name:string}.
 *
 * @param string     $method  GET or POST.
 * @param string     $path    Path below the API host, starting with a slash.
 * @param array|null $body    JSON body, null for none.
 * @param array      $headers Extra headers.
 * @return array|WP_Error Decoded response (may be empty) or error.
 */
function mlab_smtp_resend_request( string $method, string $path, ?array $body = null, array $headers = array() ) {
	$key = (string) mlab_smtp_get( mlab_smtp_resend_key_name() );
	if ( '' === $key ) {
		return new WP_Error( 'mlab_smtp_resend_unlinked', __( 'Es ist noch kein Resend-API-Key eingetragen. Bitte im Assistenten unter «Verbinden» den Key eingeben.', 'mlab-smtp' ) );
	}
	$args = mlab_smtp_resend_request_args( $method, $key, $headers );
	if ( null !== $body ) {
		$json = wp_json_encode( $body );
		if ( false === $json ) {
			return new WP_Error( 'mlab_smtp_resend_json', __( 'Die Nachricht enthält Zeichen, die nicht übertragen werden können. Bitte Betreff und Text prüfen.', 'mlab-smtp' ) );
		}
		$args['body'] = $json;
	}
	return mlab_smtp_decode_response( wp_remote_request( MLAB_SMTP_RESEND_API . $path, $args ) );
}

/**
 * Address in the form "Name <mail@domain>". Characters that would break the format are dropped from the name.
 *
 * @param string $email Address.
 * @param string $name  Display name, may be empty.
 */
function mlab_smtp_resend_address( string $email, string $name = '' ): string {
	$name = trim( (string) preg_replace( '/[\s\x00-\x1f"<>\\\\]+/u', ' ', $name ) );
	if ( '' === $name ) {
		return $email;
	}
	return preg_match( '/^[\p{L}\p{N} .\'_-]+$/u', $name ) ? $name . ' <' . $email . '>' : '"' . $name . '" <' . $email . '>';
}

/**
 * Turns a PHPMailer address list into a list of address strings.
 *
 * @param array $pairs List of [address, name] pairs, as PHPMailer returns them.
 * @return string[]
 */
function mlab_smtp_resend_addresses( array $pairs ): array {
	return array_map(
		static fn( $item ): string => mlab_smtp_resend_address( (string) ( $item[0] ?? '' ), (string) ( $item[1] ?? '' ) ),
		array_values( $pairs )
	);
}

/**
 * Headers that Resend builds itself and that must not be passed on.
 *
 * @return string[] Lower case names.
 */
function mlab_smtp_resend_reserved_headers(): array {
	return array( 'from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'date', 'message-id', 'mime-version', 'content-type', 'content-transfer-encoding' );
}

/**
 * Custom headers of the message as a name => value list.
 *
 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Prepared message.
 * @return array<string, string>
 */
function mlab_smtp_resend_headers( $mailer ): array {
	$headers = array();
	foreach ( $mailer->getCustomHeaders() as $header ) {
		$name = trim( (string) ( $header[0] ?? '' ) );
		if ( '' === $name || in_array( strtolower( $name ), mlab_smtp_resend_reserved_headers(), true ) ) {
			continue;
		}
		$headers[ $name ] = trim( (string) ( $header[1] ?? '' ) );
	}
	return $headers;
}

/**
 * Attachments of the message in the format of the Resend API.
 *
 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Prepared message.
 * @return array|WP_Error List of attachments, or the error when one cannot be read or the total is too large.
 */
function mlab_smtp_resend_attachments( $mailer ) {
	$out  = array();
	$size = 0;
	foreach ( $mailer->getAttachments() as $attachment ) {
		// Index: 0 path or content, 1 file name, 2 display name, 4 type, 5 content given as string, 6 disposition, 7 content id.
		$size += (int) ceil( ( $attachment[5] ? strlen( (string) $attachment[0] ) : (int) filesize( (string) $attachment[0] ) ) / 3 ) * 4;
		if ( $size > MLAB_SMTP_RESEND_LIMIT ) {
			return mlab_smtp_too_large_error( MLAB_SMTP_RESEND_LIMIT );
		}
		$content = $attachment[5] ? (string) $attachment[0] : file_get_contents( (string) $attachment[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local attachment file.
		if ( false === $content ) {
			/* translators: %s: file name */
			return new WP_Error( 'mlab_smtp_attachment', sprintf( __( 'Der Anhang «%s» konnte nicht gelesen werden.', 'mlab-smtp' ), (string) $attachment[1] ) );
		}
		$item = array(
			'filename' => '' !== (string) $attachment[2] ? (string) $attachment[2] : (string) $attachment[1],
			'content'  => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The API takes attachments Base64 encoded.
		);
		if ( '' !== (string) $attachment[4] ) {
			$item['content_type'] = (string) $attachment[4];
		}
		if ( 'inline' === $attachment[6] && '' !== (string) $attachment[7] ) {
			$item['content_id'] = (string) $attachment[7];
		}
		$out[] = $item;
	}
	return $out;
}

/**
 * Text and HTML part of the message. With an alternative text PHPMailer switches the content type to
 * multipart/alternative; the body is HTML then. Without an alternative text Resend derives the text from the HTML.
 *
 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Prepared message.
 * @return array{html?:string, text?:string}
 */
function mlab_smtp_resend_content( $mailer ): array {
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property names are the PHPMailer API.
	if ( ! in_array( $mailer->ContentType, array( 'text/html', 'multipart/alternative' ), true ) ) {
		return array( 'text' => (string) $mailer->Body );
	}
	$content = array( 'html' => (string) $mailer->Body );
	if ( '' !== (string) $mailer->AltBody ) {
		$content['text'] = (string) $mailer->AltBody;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	return $content;
}

/**
 * Request body for the Resend API, built from the prepared PHPMailer message.
 *
 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Prepared message (wp_mail() has run preSend()).
 * @return array|WP_Error Body, or an error that stops the sending before the request is made.
 */
function mlab_smtp_resend_payload( $mailer ) {
	$to = mlab_smtp_resend_addresses( $mailer->getToAddresses() );
	if ( count( $to ) > MLAB_SMTP_RESEND_RECIPIENTS ) {
		/* translators: %d: largest number of recipients */
		return new WP_Error( 'mlab_smtp_recipients', sprintf( __( 'Resend erlaubt höchstens %d Empfänger pro Mail. Bitte die Mail auf mehrere Nachrichten aufteilen.', 'mlab-smtp' ), MLAB_SMTP_RESEND_RECIPIENTS ) );
	}
	$attachments = mlab_smtp_resend_attachments( $mailer );
	if ( is_wp_error( $attachments ) ) {
		return $attachments;
	}
	$payload  = array_merge(
		array(
			'from'    => mlab_smtp_resend_address( (string) $mailer->From, (string) $mailer->FromName ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
			'to'      => $to,
			'subject' => (string) $mailer->Subject, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
		),
		mlab_smtp_resend_content( $mailer )
	);
	$optional = array(
		'cc'          => mlab_smtp_resend_addresses( $mailer->getCcAddresses() ),
		'bcc'         => mlab_smtp_resend_addresses( $mailer->getBccAddresses() ),
		'reply_to'    => mlab_smtp_resend_addresses( $mailer->getReplyToAddresses() ),
		'headers'     => mlab_smtp_resend_headers( $mailer ),
		'attachments' => $attachments,
	);
	return array_merge( $payload, array_filter( $optional ) );
}

/**
 * Sends the prepared message through the Resend API (the sender address is checked by the caller). An idempotency key (24 hours valid at Resend) keeps a repeated
 * request for the same message from producing a second mail.
 *
 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Prepared message.
 * @return true|WP_Error
 */
function mlab_smtp_send_resend( $mailer ) {
	$payload = mlab_smtp_resend_payload( $mailer );
	if ( is_wp_error( $payload ) ) {
		return $payload;
	}
	$id     = (string) $mailer->getLastMessageID();
	$result = mlab_smtp_resend_request(
		'POST',
		'/emails',
		$payload,
		array( 'Idempotency-Key' => 'mlab-' . substr( hash( 'sha256', '' !== $id ? $id : wp_generate_uuid4() ), 0, 48 ) )
	);
	return is_wp_error( $result ) ? $result : true;
}

/**
 * Cache name for the domain list. It depends on the key, so a new key never meets the data of the old one.
 * It is remembered when written, so that clearing the tokens or uninstalling removes it.
 */
function mlab_smtp_resend_cache_key(): string {
	static $memo              = array();
	$secret                   = (string) mlab_smtp_get( mlab_smtp_resend_key_name() );
	return $memo[ $secret ] ??= mlab_smtp_token_key( 'resend', array( $secret ) );
}

/**
 * Domains of the Resend account, cached for a few minutes. Also tells what the key may do:
 * full (lists domains) or sending (restricted to sending mails, which is the recommended key).
 *
 * @param bool $fresh True to bypass the cache (connection check, "check again").
 * @return array{scope:string, domains:array}|WP_Error Domains as list of id, name and status.
 */
function mlab_smtp_resend_domains( bool $fresh = false ) {
	$cache = mlab_smtp_resend_cache_key();
	if ( $fresh ) {
		delete_transient( $cache );
	}
	$cached = get_transient( $cache );
	if ( is_array( $cached ) ) {
		return 'error' === $cached['scope'] ? new WP_Error( 'mlab_smtp_resend', (string) $cached['message'] ) : $cached;
	}
	$result = mlab_smtp_resend_fetch_domains();
	mlab_smtp_remember_token_key( $cache );
	if ( is_wp_error( $result ) ) {
		// A short cache keeps a dead connection from blocking every page view of the overview.
		set_transient(
			$cache,
			array(
				'scope'   => 'error',
				'message' => $result->get_error_message(),
			),
			2 * MINUTE_IN_SECONDS
		);
		return $result;
	}
	set_transient( $cache, $result, 10 * MINUTE_IN_SECONDS );
	return $result;
}

/**
 * Asks Resend for the domain list. A key restricted to sending answers restricted_api_key (401): the key is valid,
 * only the list is off limits.
 *
 * @return array{scope:string, domains:array}|WP_Error
 */
function mlab_smtp_resend_fetch_domains() {
	$data = mlab_smtp_resend_request( 'GET', '/domains' );
	if ( is_wp_error( $data ) ) {
		$info = (array) $data->get_error_data();
		if ( 401 === (int) ( $info['status'] ?? 0 ) && 'restricted_api_key' === ( $info['name'] ?? '' ) ) {
			return array(
				'scope'   => 'sending',
				'domains' => array(),
			);
		}
		return $data;
	}
	$domains = array();
	foreach ( (array) ( $data['data'] ?? array() ) as $row ) {
		if ( is_array( $row ) && ! empty( $row['name'] ) ) {
			$domains[] = array(
				'id'     => (string) ( $row['id'] ?? '' ),
				'name'   => strtolower( (string) $row['name'] ),
				'status' => (string) ( $row['status'] ?? '' ),
			);
		}
	}
	return array(
		'scope'   => 'full',
		'domains' => $domains,
	);
}

/**
 * Connection check without sending a mail: asks for the domain list. A key that may only send counts as valid.
 *
 * @return true|WP_Error
 */
function mlab_smtp_resend_check() {
	$info = mlab_smtp_resend_domains( true );
	return is_wp_error( $info ) ? $info : true;
}

/**
 * Text for a successful key check, depending on what the key may do.
 */
function mlab_smtp_resend_success_text(): string {
	$info = mlab_smtp_resend_domains();
	if ( ! is_wp_error( $info ) && 'sending' === $info['scope'] ) {
		return __( 'Der Key ist gültig. Er darf nur Mails senden, das ist die sicherste Einstellung. Ob die Absender-Domain verifiziert ist, zeigt die Testmail.', 'mlab-smtp' );
	}
	return __( 'Verbunden. Resend hat den API-Key akzeptiert.', 'mlab-smtp' );
}

/**
 * DNS records of a domain that are not verified yet.
 *
 * @param string $id Domain id at Resend.
 * @return array|WP_Error List of record, type, name, value, status and priority.
 */
function mlab_smtp_resend_open_records( string $id ) {
	$data = mlab_smtp_resend_request( 'GET', '/domains/' . rawurlencode( $id ) );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$out = array();
	foreach ( (array) ( $data['records'] ?? array() ) as $row ) {
		if ( ! is_array( $row ) || 'verified' === ( $row['status'] ?? '' ) ) {
			continue;
		}
		$out[] = array(
			'record'   => (string) ( $row['record'] ?? '' ),
			'type'     => (string) ( $row['type'] ?? '' ),
			'name'     => (string) ( $row['name'] ?? '' ),
			'value'    => (string) ( $row['value'] ?? '' ),
			'status'   => (string) ( $row['status'] ?? '' ),
			'priority' => isset( $row['priority'] ) ? (string) $row['priority'] : '',
		);
	}
	return $out;
}

/**
 * Starts the DNS verification of a domain at Resend. The result arrives asynchronously.
 *
 * @param string $id Domain id at Resend.
 * @return true|WP_Error
 */
function mlab_smtp_resend_verify( string $id ) {
	$result = mlab_smtp_resend_request( 'POST', '/domains/' . rawurlencode( $id ) . '/verify' );
	return is_wp_error( $result ) ? $result : true;
}

/**
 * True for a domain status that can send (fully or partly verified).
 *
 * @param string $status Status of a domain at Resend.
 */
function mlab_smtp_resend_is_verified( string $status ): bool {
	return in_array( $status, array( 'verified', 'partially_verified' ), true );
}

/**
 * First verified domain of the account, empty when there is none or the list is not available.
 */
function mlab_smtp_resend_first_verified_domain(): string {
	$info = mlab_smtp_resend_domains();
	if ( ! is_wp_error( $info ) ) {
		foreach ( $info['domains'] as $domain ) {
			if ( mlab_smtp_resend_is_verified( $domain['status'] ) ) {
				return $domain['name'];
			}
		}
	}
	return '';
}

/**
 * Domain of the sender address, otherwise the first verified domain (the suggested sender).
 */
function mlab_smtp_resend_sender_domain(): string {
	$domain = mlab_smtp_domain_of( (string) mlab_smtp_get( 'from_email' ) );
	return '' !== $domain ? $domain : mlab_smtp_resend_first_verified_domain();
}

/**
 * Suggested sender: noreply@ plus the first verified domain of the account. Empty when unknown.
 */
function mlab_smtp_resend_suggest_sender(): string {
	$domain = mlab_smtp_resend_first_verified_domain();
	return '' !== $domain ? 'noreply@' . $domain : '';
}

/**
 * State of the sender domain at Resend.
 *
 * States: none (no domain), testing (resend.dev), sending (key may only send, not checkable), error (lookup failed),
 * missing (not added at Resend), verified, partial (partly verified), pending (DNS not checked yet), failed.
 *
 * @param string $domain Domain of the sender address.
 * @param bool   $fresh        True to bypass the cache.
 * @param bool   $with_records True to also fetch the open DNS records (one more request, only needed where they are shown).
 * @return array{state:string, domain:string, id:string, records:array, error:string, verified:string[]}
 */
function mlab_smtp_resend_domain_state( string $domain, bool $fresh = false, bool $with_records = false ): array {
	$domain = strtolower( $domain );
	$state  = mlab_smtp_resend_empty_state( $domain );
	if ( '' === $domain ) {
		return $state;
	}
	if ( 'resend.dev' === $domain ) {
		return array_merge( $state, array( 'state' => 'testing' ) );
	}
	$info = mlab_smtp_resend_domains( $fresh );
	if ( is_wp_error( $info ) ) {
		return array_merge(
			$state,
			array(
				'state' => 'error',
				'error' => $info->get_error_message(),
			)
		);
	}
	if ( 'sending' === $info['scope'] ) {
		return array_merge( $state, array( 'state' => 'sending' ) );
	}
	$state = mlab_smtp_resend_match_domain( $state, $info['domains'] );
	return $with_records ? mlab_smtp_resend_attach_records( $state ) : $state;
}

/**
 * Empty state of a domain.
 *
 * @param string $domain Domain, lower case.
 * @return array{state:string, domain:string, id:string, records:array, error:string, verified:string[]}
 */
function mlab_smtp_resend_empty_state( string $domain ): array {
	return array(
		'state'    => 'none',
		'domain'   => $domain,
		'id'       => '',
		'records'  => array(),
		'error'    => '',
		'verified' => array(),
	);
}

/**
 * Adds the open DNS records to a domain that is not verified yet. A failing lookup keeps the state and shows
 * its error next to it.
 *
 * @param array $state Result of the domain match.
 * @return array State with records or error.
 */
function mlab_smtp_resend_attach_records( array $state ): array {
	if ( ! in_array( $state['state'], array( 'pending', 'failed', 'partial' ), true ) ) {
		return $state;
	}
	$records = mlab_smtp_resend_open_records( $state['id'] );
	if ( is_wp_error( $records ) ) {
		$state['error'] = $records->get_error_message();
	} else {
		$state['records'] = $records;
	}
	return $state;
}

/**
 * Finds the domain in the account and collects the verified ones for the hint when it is missing.
 *
 * @param array $state   State so far.
 * @param array $domains Domains of the account (id, name, status).
 * @return array State with the result of the match.
 */
function mlab_smtp_resend_match_domain( array $state, array $domains ): array {
	$state['state'] = 'missing';
	foreach ( $domains as $row ) {
		if ( mlab_smtp_resend_is_verified( $row['status'] ) ) {
			$state['verified'][] = $row['name'];
		}
		if ( $row['name'] === $state['domain'] ) {
			$state = array_merge( $state, mlab_smtp_resend_classify( $row ) );
		}
	}
	return $state;
}

/**
 * Maps a domain row of the account to a state.
 *
 * @param array $row Domain with id, name and status.
 * @return array{state:string, id:string}
 */
function mlab_smtp_resend_classify( array $row ): array {
	$map   = array(
		'verified'           => 'verified',
		'partially_verified' => 'verified',
		'partially_failed'   => 'partial',
		'failed'             => 'failed',
		'temporary_failure'  => 'failed',
	);
	$state = $map[ $row['status'] ] ?? 'pending';
	return array(
		'state' => $state,
		'id'    => $row['id'],
	);
}

/**
 * True when the sender domain is known not to work, so a test mail would fail. Lookup errors and keys that only
 * send never block.
 *
 * @param array $state Result of mlab_smtp_resend_domain_state().
 */
function mlab_smtp_resend_domain_blocks( array $state ): bool {
	return in_array( $state['state'], array( 'missing', 'pending', 'failed' ), true );
}

/**
 * Plain-language text for a domain state.
 *
 * @param array $state Result of mlab_smtp_resend_domain_state().
 * @return array{level:string, title:string, message:string} Level is ok, warn, bad or info; message is empty when nothing is to be said.
 */
function mlab_smtp_resend_state_text( array $state ): array {
	/* translators: %s: domain */
	$title = sprintf( __( 'Domain %s bei Resend', 'mlab-smtp' ), $state['domain'] );
	$texts = mlab_smtp_resend_state_texts();
	if ( ! isset( $texts[ $state['state'] ] ) ) {
		return array(
			'level'   => 'info',
			'title'   => $title,
			'message' => '',
		);
	}
	list( $level, $message ) = $texts[ $state['state'] ];
	if ( 'missing' === $state['state'] && $state['verified'] ) {
		/* translators: %s: list of domains */
		$message .= ' ' . sprintf( __( 'Verifiziert sind: %s.', 'mlab-smtp' ), implode( ', ', $state['verified'] ) );
	}
	return array(
		'level'   => $level,
		'title'   => $title,
		'message' => $message,
	);
}

/**
 * Level and text for each domain state.
 *
 * @return array<string, array{0:string, 1:string}>
 */
function mlab_smtp_resend_state_texts(): array {
	return array(
		'verified' => array( 'ok', __( 'Die Domain ist verifiziert. Mails können gesendet werden.', 'mlab-smtp' ) ),
		'testing'  => array( 'warn', __( 'Die Testadresse von Resend sendet nur an die eigene Adresse Ihres Resend-Kontos. Für Mails an andere Empfänger eine eigene Domain verifizieren.', 'mlab-smtp' ) ),
		'sending'  => array( 'info', __( 'Der Key darf nur senden. Darum lässt sich nicht vorab prüfen, ob die Domain verifiziert ist. Die Testmail zeigt es.', 'mlab-smtp' ) ),
		'partial'  => array( 'warn', __( 'Die Domain ist nur teilweise verifiziert. Der Versand klappt meist, prüfen Sie aber die DNS-Einträge unten.', 'mlab-smtp' ) ),
		'pending'  => array( 'warn', __( 'Die Domain ist noch nicht verifiziert. Tragen Sie die DNS-Einträge unten bei Ihrem Domain-Anbieter ein und wählen Sie «Nochmals prüfen». Die Prüfung dauert meist wenige Minuten, in Ausnahmen bis zu 72 Stunden.', 'mlab-smtp' ) ),
		'failed'   => array( 'bad', __( 'Resend findet die DNS-Einträge der Domain nicht. Prüfen Sie die Einträge unten bei Ihrem Domain-Anbieter und wählen Sie danach «Nochmals prüfen».', 'mlab-smtp' ) ),
		'missing'  => array( 'warn', __( 'Die Domain ist bei Resend nicht angelegt. Legen Sie sie dort unter «Domains» an, tragen Sie die DNS-Einträge ein und wählen Sie «Nochmals prüfen». Oder verwenden Sie eine Absender-Adresse einer verifizierten Domain.', 'mlab-smtp' ) ),
		'error'    => array( 'warn', __( 'Der Domain-Status konnte bei Resend nicht abgerufen werden. Der Versand ist davon nicht betroffen, die Testmail zeigt, ob alles stimmt.', 'mlab-smtp' ) ),
	);
}

/**
 * Block message for the wizard when the sender domain cannot send yet, null when the way is clear.
 *
 * @param bool $fresh True to ask Resend again instead of using the cache.
 * @return string|null
 */
function mlab_smtp_resend_sender_block( bool $fresh = true ): ?string {
	$state = mlab_smtp_resend_domain_state( mlab_smtp_resend_sender_domain(), $fresh );
	if ( ! mlab_smtp_resend_domain_blocks( $state ) ) {
		return null;
	}
	$text = mlab_smtp_resend_state_text( $state );
	return $text['title'] . ': ' . $text['message'];
}

/**
 * Admin-post handler of the "check again" link in wizard step 3: starts the verification at Resend, then returns.
 */
function mlab_smtp_resend_recheck(): void {
	mlab_smtp_guard( 'mlab_smtp_resend_recheck' );
	mlab_smtp_provider_override( 'resend' );
	$state = mlab_smtp_resend_domain_state( mlab_smtp_resend_sender_domain(), true );
	if ( '' !== $state['id'] && 'verified' !== $state['state'] ) {
		$started = mlab_smtp_resend_verify( $state['id'] );
		if ( is_wp_error( $started ) ) {
			$texts = mlab_smtp_ui_error_texts( $started->get_error_message() );
			mlab_smtp_flash( 'error', $texts['message'], $texts['detail'] );
		} else {
			mlab_smtp_flash( 'success', __( 'Resend prüft die DNS-Einträge jetzt. Das dauert meist wenige Minuten. Laden Sie die Seite danach neu oder wählen Sie «Nochmals prüfen» erneut.', 'mlab-smtp' ) );
		}
	}
	mlab_smtp_back(
		array(
			'view'     => 'wizard',
			'step'     => 3,
			'provider' => 'resend',
		)
	);
}

/**
 * URL of the "check again" link (admin-post, protected by a nonce).
 */
function mlab_smtp_resend_recheck_url(): string {
	return wp_nonce_url( admin_url( 'admin-post.php?action=mlab_smtp_resend_recheck' ), 'mlab_smtp_resend_recheck' );
}
