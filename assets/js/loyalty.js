/* Lindenweg16 ERP – Loyalty Checkout & Kopieren */
( function ( $ ) {
	'use strict';

	$( function () {

		// Konto: Die Leiste wechselt zwischen ihren Zielen. Ohne Skript stehen alle untereinander.
		document.querySelectorAll( '[data-lw16-rotor]' ).forEach( function ( box ) {
			var slides = box.querySelectorAll( '.lw16-konto__slide' );
			if ( slides.length < 2 ) { return; }
			box.classList.add( 'is-rotiert' );
			var i = 0;
			var timer = null;
			function show( n ) {
				i = n % slides.length;
				slides.forEach( function ( s, k ) { s.classList.toggle( 'is-aktiv', k === i ); } );
			}
			function start() {
				if ( ! timer && ! window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
					timer = setInterval( function () { show( i + 1 ); }, 5000 );
				}
			}
			function stop() { clearInterval( timer ); timer = null; }
			box.addEventListener( 'mouseenter', stop );
			box.addEventListener( 'mouseleave', start );
			box.addEventListener( 'focusin', stop );
			box.addEventListener( 'focusout', start );
			start();
		} );

		// Empfehlungslink kopieren.
		$( document ).on( 'click', '#lw16-copy-btn', function () {
			var input = document.getElementById( $( this ).data( 'target' ) );
			if ( ! input ) { return; }
			input.select();
			input.setSelectionRange( 0, 99999 );
			try { document.execCommand( 'copy' ); } catch ( e ) {}
			var btn = $( this );
			var orig = btn.text();
			btn.text( '✓' );
			setTimeout( function () { btn.text( orig ); }, 1500 );
		} );

		// Punkte -> Euro Live-Vorschau.
		function updatePointsEuro() {
			var pts = parseInt( $( '#lw16-use-points' ).val(), 10 ) || 0;
			var per = ( window.LW16Loyalty && LW16Loyalty.pointsPerEuro ) || 100;
			var euro = ( pts / per ).toFixed( 2 ).replace( '.', ',' );
			$( '#lw16-points-euro' ).text( '= ' + euro + ' €' );
		}
		$( document ).on( 'input', '#lw16-use-points', updatePointsEuro );
		updatePointsEuro();

		// Einlösung anwenden.
		$( document ).on( 'click', '#lw16-apply-redeem', function () {
			var creditBalance = parseFloat( LW16Loyalty.creditBalance ) || 0;
			var data = {
				action: 'lw16_set_redeem',
				nonce: LW16Loyalty.nonce,
				use_credit: $( '#lw16-use-credit' ).is( ':checked' ) ? creditBalance : 0,
				use_points: parseInt( $( '#lw16-use-points' ).val(), 10 ) || 0
			};
			$.post( LW16Loyalty.ajaxUrl, data, function () {
				$( document.body ).trigger( 'update_checkout' );
			} );
		} );

	} );
} )( jQuery );
