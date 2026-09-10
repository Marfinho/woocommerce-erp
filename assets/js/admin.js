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

				// Set-Zeile: Rabatt-Feld vorhanden, aber keine Modul-Felder.
				var $rabatt = $form.find( '[name="rabatt_prozent"]' );
				if ( $rabatt.length ) {
					var rabatt = parseFloat( $rabatt.val() ) || 0;
					var $cells = $row.find( 'td' );
					var einzelsumme = parseFloat( $cells.eq( 2 ).text().replace( /[^0-9,.-]/g, '' ).replace( ',', '.' ) ) || 0;
					var vorschlag = Math.round( einzelsumme * ( 1 - rabatt / 100 ) );
					var aktuell = parseFloat( $cells.eq( 4 ).text().replace( /[^0-9,.-]/g, '' ).replace( ',', '.' ) ) || 0;
					var marge = aktuell > 0 ? ( ( aktuell - einzelsumme * ( 1 - rabatt / 100 ) ) / aktuell * 100 ) : 0;
					$cells.eq( 3 ).html( '<strong>' + vorschlag + ' €</strong>' );
					$cells.eq( 5 ).text( marge.toFixed( 1 ).replace( '.', ',' ) + ' %' );
					return;
				}

				var fg = parseFloat( $form.find( '[name="filament_gramm"]' ).val() );
				if ( isNaN( fg ) ) {
					return;
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

				var $cells = $row.find( 'td' );
				var aktuell = parseFloat( $cells.eq( 4 ).text().replace( /[^0-9,.-]/g, '' ).replace( ',', '.' ) ) || 0;
				var marge = aktuell > 0 ? ( ( aktuell - kosten ) / aktuell * 100 ) : 0;

				$cells.eq( 2 ).text( kosten.toFixed( 2 ).replace( '.', ',' ) + ' €' );
				$cells.eq( 3 ).html( '<strong>' + vorschlag + ' €</strong>' );
				$cells.eq( 5 ).text( marge.toFixed( 1 ).replace( '.', ',' ) + ' %' );
			} );
		}

		// Bambu-Druckerkarten (Live-Status, Kamera-Standbild): alle paar
		// Sekunden per AJAX neu laden, statt dass jemand manuell F5 drückt.
		// Container trägt data-lw16-autorefresh (AJAX-Action) und
		// data-lw16-interval (Millisekunden) - siehe BambuPrintersPage::
		// render_printer_cards()/handle_ajax_cards().
		$( '[data-lw16-autorefresh]' ).each( function () {
			var $container = $( this );
			var action = $container.data( 'lw16-autorefresh' );
			var interval = parseInt( $container.data( 'lw16-interval' ), 10 ) || 5000;

			if ( typeof LW16Admin === 'undefined' || ! action ) {
				return;
			}

			function refresh() {
				$.post( LW16Admin.ajaxUrl, { action: action, nonce: LW16Admin.nonce } )
					.done( function ( html ) {
						$container.html( html );
					} )
					// Bei einem Netzwerk-Hakler einfach beim nächsten
					// Intervall erneut versuchen, statt den Nutzer mit
					// einer Fehlermeldung mitten im Adminbereich zu stören.
					.fail( function () {} );
			}

			setInterval( refresh, interval );
		} );

		// Barcode-Rendering (Code 128B) für Lagerorte.
		$( '.lw16-barcode' ).each( function () {
			var container = this;
			var text = $( container ).data( 'value' ) || '';
			if ( ! text ) return;
			var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
			svg.style.display = 'block';
			svg.style.maxWidth = '180px';
			svg.style.height = 'auto';
			container.appendChild( svg );
			renderCode128( svg, text );
		} );

	} );

	function renderCode128( svg, text ) {
		var CODE128B = [
			'11011001100','11001101100','11001100110','10010011000','10010001100',
			'10001001100','10011001000','10011000100','10001100100','11001001000',
			'11001000100','11000100100','10110011100','10011011100','10011001110',
			'10111001100','10011101100','10011100110','11001110010','11001011100',
			'11001001110','11011100100','11001110100','11100101100','11100100110',
			'11101100100','11100110100','11100110010','11011011000','11011000110',
			'11000110110','10100011000','10001011000','10001000110','10110001000',
			'10001101000','10001100010','11010001000','11000101000','11000100010',
			'10110111000','10110001110','10001101110','10111011000','10111000110',
			'10001110110','11101110110','11010001110','11000101110','11011101000',
			'11011100010','11011101110','11101011000','11101000110','11100010110',
			'11101101000','11101100010','11100011010','11101111010','11001000010',
			'11110001010','10100110000','10100001100','10010110000','10010000110',
			'10000101100','10000100110','10110010000','10110000100','10011010000',
			'10011000010','10000110100','10000110010','11000010010','11001010000',
			'11110111010','11000010100','10001111010','10100111100','10010111100',
			'10010011110','10111100100','10011110100','10011110010','11110100100',
			'11110010100','11110010010','11011011110','11011110110','11110110110',
			'10101111000','10100011110','10001011110','10111101000','10111100010',
			'11110101000','11110100010','10111011110','10111101110','11101011110',
			'11110101110','11010000100','11010010000','11010011100','1100011101011'
		];
		var startB = 104;
		var codes = [ CODE128B[ startB ] ];
		var checksum = startB;
		for ( var i = 0; i < text.length; i++ ) {
			var val = text.charCodeAt( i ) - 32;
			if ( val < 0 || val > 95 ) val = 0;
			codes.push( CODE128B[ val ] );
			checksum += val * ( i + 1 );
		}
		codes.push( CODE128B[ checksum % 103 ] );
		codes.push( CODE128B[ 106 ] );
		var binary = codes.join( '' );
		var barW = 1.5, barH = 40;
		var w = binary.length * barW + 20;
		svg.setAttribute( 'width', w );
		svg.setAttribute( 'height', barH + 16 );
		svg.setAttribute( 'viewBox', '0 0 ' + w + ' ' + ( barH + 16 ) );
		var ns = 'http://www.w3.org/2000/svg';
		for ( var j = 0; j < binary.length; j++ ) {
			if ( binary[ j ] === '1' ) {
				var rect = document.createElementNS( ns, 'rect' );
				rect.setAttribute( 'x', 10 + j * barW );
				rect.setAttribute( 'y', 0 );
				rect.setAttribute( 'width', barW );
				rect.setAttribute( 'height', barH );
				rect.setAttribute( 'fill', '#000' );
				svg.appendChild( rect );
			}
		}
		var label = document.createElementNS( ns, 'text' );
		label.setAttribute( 'x', w / 2 );
		label.setAttribute( 'y', barH + 12 );
		label.setAttribute( 'text-anchor', 'middle' );
		label.setAttribute( 'font-size', '10' );
		label.setAttribute( 'font-family', 'monospace' );
		label.textContent = text;
		svg.appendChild( label );
	}
} )( jQuery );
