( function () {
	'use strict';

	var radios = document.querySelectorAll( '.mlab-smtp-providers input[type="radio"]' );
	var panels = document.querySelectorAll( '.mlab-smtp-panel' );

	function update() {
		var selected = document.querySelector( '.mlab-smtp-providers input[type="radio"]:checked' );
		var provider = selected ? selected.value : 'smtp';
		panels.forEach( function ( panel ) {
			panel.hidden = panel.dataset.provider !== provider;
		} );
	}

	radios.forEach( function ( radio ) {
		radio.addEventListener( 'change', update );
	} );
	update();
}() );

( function () {
	'use strict';

	var form = document.querySelector( 'form.mlab-smtp-test' );
	if ( ! form ) {
		return;
	}
	form.addEventListener( 'submit', function () {
		var button = form.querySelector( '[type="submit"]' );
		button.disabled = true;
		button.value = form.dataset.busy;
	} );
}() );
