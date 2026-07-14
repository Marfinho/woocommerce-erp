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

		// Preiskalkulation: Live-Neuberechnung bei Eingabeänderung.
		if ( typeof LW16Kalk !== 'undefined' ) {
			$( document ).on( 'input change', '.lw16-inline-form input[type="number"]', function () {
				var $form = $( this ).closest( '.lw16-inline-form' );
				var $row  = $form.closest( 'tr' );

				var fg = parseFloat( $form.find( '[name="filament_gramm"]' ).val() );
				if ( isNaN( fg ) ) {
					return; // Set-Zeile, keine Modul-Felder.
				}

				var dz  = parseFloat( $form.find( '[name="druckzeit_stunden"]' ).val() ) || 0;
				var az  = parseFloat( $form.find( '[name="arbeitszeit_minuten"]' ).val() ) || 0;
				var vp  = parseFloat( $form.find( '[name="verpackung_euro"]' ).val() ) || 0;
				var zm  = parseFloat( $form.find( '[name="zusatzmaterial_euro"]' ).val() ) || 0;

				var filamentKosten = ( fg / 1000 ) * LW16Kalk.filamentPreisKg;
				var druckerKosten  = LW16Kalk.druckerKostenH * dz;
				var arbeitKosten   = ( az / 60 ) * LW16Kalk.stundenlohn;
				var zwischen       = filamentKosten + druckerKosten + arbeitKosten + vp + zm;
				var kosten         = zwischen * ( 1 + LW16Kalk.ausschuss / 100 );
				kosten = Math.round( kosten * 100 ) / 100;

				var zielmarge = LW16Kalk.zielmarge / 100;
				var vorschlag = zielmarge < 1 ? kosten / ( 1 - zielmarge ) : kosten;
				vorschlag = Math.round( vorschlag );

				// Aktuellen Preis aus der Zeile auslesen.
				var $cells = $row.find( 'td' );
				var aktuell = parseFloat( $cells.eq( 4 ).text().replace( /[^0-9,.-]/g, '' ).replace( ',', '.' ) ) || 0;
				var marge = aktuell > 0 ? ( ( aktuell - kosten ) / aktuell * 100 ) : 0;

				$cells.eq( 2 ).text( kosten.toFixed( 2 ).replace( '.', ',' ) + ' €' );
				$cells.eq( 3 ).html( '<strong>' + vorschlag + ' €</strong>' );
				$cells.eq( 5 ).text( marge.toFixed( 1 ).replace( '.', ',' ) + ' %' );
			} );
		}

	} );
} )( jQuery );
