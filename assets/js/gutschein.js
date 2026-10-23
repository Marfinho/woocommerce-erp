/* Gutschein: gewählte Vorlage zeigen und die Karte beim Tippen mitschreiben. */
( function () {
	'use strict';

	document.querySelectorAll( '.lw16-gv' ).forEach( function ( root ) {
		var radios = root.querySelectorAll( 'input[name="lw16_gv_vorlage"]' );
		var bilder = root.querySelectorAll( '[data-vorlage]' );

		function wahl() {
			var aktiv = root.querySelector( 'input[name="lw16_gv_vorlage"]:checked' );
			var wert = aktiv ? aktiv.value : '';
			bilder.forEach( function ( b ) {
				b.hidden = b.getAttribute( 'data-vorlage' ) !== wert;
			} );
		}

		function feld( name, wert ) {
			root.querySelectorAll( '[data-lw16-feld="' + name + '"]' ).forEach( function ( span ) {
				if ( wert ) {
					span.textContent = wert;
					span.style.whiteSpace = 'pre-line';
				} else {
					span.textContent = span.getAttribute( 'data-leer' ) || '';
				}
			} );
		}

		radios.forEach( function ( r ) {
			r.addEventListener( 'change', wahl );
		} );

		[ [ 'von', 'lw16_gv_von' ], [ 'an', 'lw16_gv_an' ], [ 'text', 'lw16_gv_text' ] ].forEach( function ( p ) {
			var el = root.querySelector( '[name="' + p[ 1 ] + '"]' );
			if ( ! el ) {
				return;
			}
			el.addEventListener( 'input', function () {
				feld( p[ 0 ], el.value.trim() );
			} );
			feld( p[ 0 ], el.value.trim() );
		} );

		var betrag = root.getAttribute( 'data-betrag' );
		if ( betrag ) {
			feld( 'betrag', betrag );
		}

		wahl();
	} );
}() );
