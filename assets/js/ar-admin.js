/**
 * Mediathek-Auswahl für die Metabox „3D-Modell (AR)" im Produkteditor.
 *
 * Reines JavaScript; genutzt wird nur die Schnittstelle wp.media.
 */
( function () {
	'use strict';

	var cfg = window.LW16_AR_ADMIN || { texts: {} };
	var t = cfg.texts || {};

	function init( box ) {
		var kind = box.getAttribute( 'data-lw16-ar-feld' );
		var accept = ( box.getAttribute( 'data-accept' ) || '' ).split( ',' );
		var idField = box.querySelector( '[data-lw16-ar-id]' );
		var nameEl = box.querySelector( '[data-lw16-ar-name]' );
		var pick = box.querySelector( '[data-lw16-ar-waehlen]' );
		var drop = box.querySelector( '[data-lw16-ar-entfernen]' );
		var frame = null;

		function extensionOf( name ) {
			var m = /\.([a-z0-9]+)$/i.exec( name || '' );
			return m ? m[ 1 ].toLowerCase() : '';
		}

		function setValue( id, name ) {
			idField.value = id ? String( id ) : '0';
			nameEl.textContent = name || t.none || '';
			drop.hidden = ! id;
		}

		pick.addEventListener( 'click', function () {
			if ( ! window.wp || ! wp.media ) {
				return;
			}
			if ( ! frame ) {
				frame = wp.media( {
					title: t[ kind ] || '',
					button: { text: t.use || '' },
					multiple: false,
					library: 'poster' === kind ? { type: 'image' } : {}
				} );
				frame.on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					var ext = extensionOf( a.filename || a.url );
					if ( -1 === accept.indexOf( ext ) ) {
						window.alert( ( t.wrong_type || '%s' ).replace( '%s', accept.join( ', ' ) ) );
						return;
					}
					setValue( a.id, a.filename || a.title );
				} );
			}
			frame.open();
		} );

		drop.addEventListener( 'click', function () {
			setValue( 0, '' );
		} );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '[data-lw16-ar-feld]' ), init );
}() );
