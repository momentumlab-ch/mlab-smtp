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
