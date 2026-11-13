/**
 * MomentumLab SMTP admin: connection check, test mail, toggles, presets, copy buttons.
 * Everything here is an enhancement: the forms also work through admin-post without it, except
 * the fallback check of the settings page, which is hidden until this script runs.
 */
( function () {
	'use strict';

	var cfg = window.mlabSmtp || { rest: '', nonce: '', i18n: {} };
	var t = cfg.i18n || {};
	var root = document.querySelector( '.mlab-smtp' );
	if ( ! root ) {
		return;
	}

	/**
	 * POSTs JSON to a REST route of the plugin and resolves with the parsed answer.
	 *
	 * @param {string} route Route below mlab-smtp/v1/.
	 * @param {Object} body  Request body.
	 * @return {Promise<Object>} Answer; ok is false with a message on any failure.
	 */
	function api( route, body ) {
		return fetch( cfg.rest + route, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( body )
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( data ) {
			if ( data && data.code && data.message ) {
				return { ok: false, sent: false, message: data.message, detail: '' };
			}
			return data;
		} ).catch( function () {
			return { ok: false, sent: false, message: t.network, detail: '' };
		} );
	}

	/**
	 * Collects the named fields inside a container the way a form would submit them.
	 *
	 * @param {Element} container Element with inputs.
	 * @return {Object} name to value; unchecked checkboxes are left out.
	 */
	function collect( container ) {
		var out = {};
		container.querySelectorAll( 'input[name], select[name]' ).forEach( function ( field ) {
			if ( field.disabled || field.name === 'action' || field.name === '_wpnonce' || field.name === '_wp_http_referer' ) {
				return;
			}
			if ( ( field.type === 'checkbox' || field.type === 'radio' ) && ! field.checked ) {
				return;
			}
			out[ field.name ] = field.value;
		} );
		return out;
	}

	/**
	 * Shows a result in the live region next to a button.
	 *
	 * @param {Element} area   The .mlab-result element.
	 * @param {boolean} ok     Success or failure.
	 * @param {string}  text   Message.
	 * @param {string}  detail Optional technical text.
	 */
	function showResult( area, ok, text, detail ) {
		area.textContent = '';
		var box = document.createElement( 'div' );
		box.className = 'mlab-hint is-' + ( ok ? 'ok' : 'bad' );
		var icon = document.createElement( 'span' );
		icon.className = 'mlab-ic is-' + ( ok ? 'ok' : 'bad' );
		icon.setAttribute( 'aria-hidden', 'true' );
		icon.textContent = ok ? '✓' : '✕';
		var body = document.createElement( 'div' );
		var strong = document.createElement( 'strong' );
		strong.textContent = text;
		body.appendChild( strong );
		if ( detail ) {
			var details = document.createElement( 'details' );
			details.className = 'mlab-technical';
			var summary = document.createElement( 'summary' );
			summary.textContent = t.technical;
			var pre = document.createElement( 'pre' );
			pre.className = 'mlab-pre';
			pre.textContent = detail;
			details.appendChild( summary );
			details.appendChild( pre );
			body.appendChild( details );
		}
		box.appendChild( icon );
		box.appendChild( body );
		area.appendChild( box );
	}

	/**
	 * Shows a busy state in a result area and on its button.
	 *
	 * @param {Element} button Button that started the work.
	 * @param {Element} area   Result area.
	 * @param {string}  label  Busy text.
	 * @return {Function} Call it to restore the button.
	 */
	function setBusy( button, area, label ) {
		var original = button.innerHTML;
		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );
		button.innerHTML = '';
		var spin = document.createElement( 'span' );
		spin.className = 'mlab-spin';
		spin.setAttribute( 'aria-hidden', 'true' );
		button.appendChild( spin );
		button.appendChild( document.createTextNode( ' ' + label ) );
		area.textContent = '';
		return function () {
			button.innerHTML = original;
			button.disabled = false;
			button.removeAttribute( 'aria-busy' );
		};
	}

	function resultAreaFor( element ) {
		var scope = element.closest( '[data-mlab-fields], form' ) || root;
		return scope.querySelector( '[data-mlab-result]' );
	}

	function setNextEnabled( form, enabled ) {
		form.querySelectorAll( '[data-mlab-next]' ).forEach( function ( button ) {
			button.disabled = ! enabled;
		} );
	}

	/**
	 * Saves the fields of the container (explicit key group on the server), then checks the connection.
	 *
	 * @param {Element} button The check button.
	 */
	function runCheck( button ) {
		var container = button.closest( '[data-mlab-fields]' );
		var area = resultAreaFor( button );
		var done = setBusy( button, area, t.checking );
		var form = button.closest( 'form' );
		api( 'save', { group: container.dataset.group, fields: collect( container ) } ).then( function ( saved ) {
			if ( ! saved.ok ) {
				return saved;
			}
			return api( 'check', { route: button.dataset.route } );
		} ).then( function ( result ) {
			done();
			showResult( area, !! result.ok, result.message, result.detail );
			if ( button.dataset.route === 'primary' && form ) {
				setNextEnabled( form, !! result.ok );
				button.classList.toggle( 'button-primary', ! result.ok );
			}
		} );
	}

	/**
	 * Saves the Google client data, then leaves for Google's consent screen.
	 *
	 * @param {Element} button The sign-in button.
	 */
	function runGoogle( button ) {
		var container = button.closest( '[data-mlab-fields]' );
		var area = resultAreaFor( button );
		var done = setBusy( button, area, t.saving );
		api( 'save', { group: 'google', fields: collect( container ) } ).then( function ( saved ) {
			if ( saved.ok ) {
				window.location.href = button.dataset.url;
				return;
			}
			done();
			showResult( area, false, saved.message );
		} );
	}

	/**
	 * Sends a test mail and, in the wizard, enables the finish button after a success.
	 *
	 * @param {Element} button The test button.
	 */
	function runTest( button ) {
		var form = button.closest( 'form' );
		var area = form.querySelector( '[data-mlab-result]' );
		var to = form.querySelector( 'input[name="to"]' );
		if ( ! to.reportValidity() ) {
			return;
		}
		var done = setBusy( button, area, t.sending );
		api( 'test', { to: to.value } ).then( function ( result ) {
			done();
			showResult( area, !! result.ok, result.message, result.detail );
			form.querySelectorAll( '[data-mlab-finish]' ).forEach( function ( finish ) {
				finish.disabled = ! result.ok;
			} );
		} );
	}

	function copyText( button ) {
		var text = button.dataset.mlabCopy;
		var original = button.textContent;
		var announce = function ( message ) {
			button.textContent = message;
			window.setTimeout( function () {
				button.textContent = original;
			}, 2000 );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( function () {
				announce( t.copied );
			}, function () {
				announce( original );
			} );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target.closest( 'button' );
		if ( ! target || ! root.contains( target ) ) {
			return;
		}
		var handlers = [
			[ 'mlabCheck', runCheck ],
			[ 'mlabGoogle', runGoogle ],
			[ 'mlabTest', runTest ],
			[ 'mlabCopy', copyText ]
		];
		handlers.some( function ( pair ) {
			if ( pair[ 0 ] in target.dataset ) {
				event.preventDefault();
				pair[ 1 ]( target );
				return true;
			}
			return false;
		} );
	} );

	// Pressing Enter in a test form must not reload the page when JavaScript can do the work.
	document.querySelectorAll( '[data-mlab-test-form]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			var submitter = event.submitter;
			if ( ! submitter || submitter.dataset.mlabFinish === undefined ) {
				event.preventDefault();
				runTest( form.querySelector( '[data-mlab-test]' ) );
			}
		} );
	} );

	// Switches show or hide the panel they control.
	document.querySelectorAll( '[data-mlab-toggle]' ).forEach( function ( box ) {
		var panel = document.getElementById( box.dataset.mlabToggle );
		var sync = function () {
			panel.hidden = ! box.checked;
		};
		box.setAttribute( 'aria-controls', panel.id );
		box.addEventListener( 'change', sync );
		sync();
	} );

	document.querySelectorAll( '[data-mlab-js-only]' ).forEach( function ( element ) {
		element.hidden = false;
	} );

	// Changing connection data invalidates an earlier successful check.
	document.querySelectorAll( 'form.mlab-connect' ).forEach( function ( form ) {
		form.addEventListener( 'input', function () {
			setNextEnabled( form, false );
			var area = form.querySelector( '[data-mlab-result]' );
			if ( area ) {
				area.textContent = '';
			}
		} );
	} );

	// Hoster preset fills server, port and encryption.
	var preset = document.querySelector( '[data-mlab-preset]' );
	function setField( name, value ) {
		var field = root.querySelector( '[name="' + name + '"]:not([type="radio"])' );
		if ( field ) {
			field.value = value;
			field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}
	}
	function setSecure( value ) {
		var radio = root.querySelector( 'input[name="smtp_secure"][value="' + value + '"]' );
		if ( radio ) {
			radio.checked = true;
			radio.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}
	}
	if ( preset ) {
		preset.addEventListener( 'change', function () {
			var option = preset.options[ preset.selectedIndex ];
			if ( ! option.dataset.host ) {
				return;
			}
			setField( 'smtp_host', option.dataset.host );
			setField( 'smtp_port', option.dataset.port );
			setSecure( option.dataset.secure );
		} );
	}

	// Choosing an encryption suggests its usual port.
	var ports = { tls: '587', ssl: '465', none: '25' };
	document.querySelectorAll( 'input[name="smtp_secure"]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			setField( 'smtp_port', ports[ radio.value ] || '587' );
		} );
	} );

	// Live sender preview in wizard step 3.
	document.querySelectorAll( '[data-mlab-preview]' ).forEach( function ( output ) {
		var input = root.querySelector( '[name="' + output.dataset.mlabPreview + '"]' );
		if ( input ) {
			input.addEventListener( 'input', function () {
				output.textContent = input.value;
			} );
		}
	} );

	// After returning from Google the connection is checked right away.
	var google = root.querySelector( 'form.mlab-connect[data-group="google"]' );
	var autoCheck = google && google.querySelector( '[data-mlab-check]' );
	if ( autoCheck && google.querySelector( '[data-mlab-next][disabled]' ) ) {
		runCheck( autoCheck );
	}
}() );
