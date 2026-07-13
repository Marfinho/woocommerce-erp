/* Lindenweg16 ERP – Admin-JS */
( function ( $ ) {
	'use strict';

	$( function () {

		// Produktformular: Rohmaterial-spezifische Felder ein-/ausblenden.
		var $typ = $( '#lw16-typ' );
		function toggleRohFelder() {
			var isRoh = $typ.val() === 'rohmaterial';
			$( '.lw16-roh-only' ).toggle( isRoh );
		}
		if ( $typ.length ) {
			toggleRohFelder();
			$typ.on( 'change', toggleRohFelder );
		}

		// Wareneingang: Beleg-Felder nur bei Kaufteil, Einheiten-Hinweis.
		var $wep = $( '#lw16-we-produkt' );
		function updateWareneingang() {
			var opt = $wep.find( 'option:selected' );
			var kategorie = opt.data( 'kategorie' );
			var ekname = opt.data( 'ekname' );
			var einheit = opt.data( 'einheit' );

			$( '#lw16-beleg-felder' ).toggle( kategorie === 'kaufteil' );

			var hint = '';
			if ( ekname ) {
				hint = 'Eingabe in ' + ekname + ' (wird automatisch in ' + einheit + ' umgerechnet)';
			} else {
				hint = 'Eingabe in ' + einheit;
			}
			$( '#lw16-we-einheit-hint' ).text( hint );
		}
		if ( $wep.length ) {
			updateWareneingang();
			$wep.on( 'change', updateWareneingang );
		}

	} );
} )( jQuery );
