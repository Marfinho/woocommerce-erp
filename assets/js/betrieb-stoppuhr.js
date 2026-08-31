/**
 * Die laufende Uhr in der Adminleiste weiterzählen.
 *
 * Der Server liefert die Startzeit als Sekunde, nicht die Laufzeit: Sonst
 * stünde die Uhr nach einer Stunde offener Seite eine Stunde falsch. Gezählt
 * wird gegen die Uhr des Browsers, angezeigt wird nur — gebucht wird auf
 * dem Server aus der gespeicherten Startzeit.
 *
 * Ohne JavaScript steht die Zeit still, ist aber richtig: Sie stimmt für den
 * Moment, in dem die Seite geladen wurde.
 *
 * @package Lindenweg16
 */
( function () {
	'use strict';

	var feld = document.querySelector( '.lw16-stoppuhr__zeit' );

	if ( ! feld ) {
		return;
	}

	var start = parseInt( feld.getAttribute( 'data-start' ), 10 );

	if ( ! start ) {
		return;
	}

	/**
	 * Laufzeit als h:mm — dieselbe Kurzform wie auf dem Server.
	 *
	 * @param {number} sekunden Sekunden.
	 * @return {string} Anzeige.
	 */
	function kurz( sekunden ) {
		var stunden = Math.floor( sekunden / 3600 );
		var minuten = Math.floor( ( sekunden % 3600 ) / 60 );

		return stunden + ':' + ( minuten < 10 ? '0' : '' ) + minuten;
	}

	/**
	 * Anzeige nachführen.
	 */
	function tick() {
		var sekunden = Math.max( 0, Math.floor( Date.now() / 1000 ) - start );

		feld.textContent = kurz( sekunden );
	}

	tick();

	// Jede halbe Minute reicht: Die Anzeige kennt nur Minuten, und ein
	// Sekundentakt hielte den Bildschirm ohne Gewinn wach.
	window.setInterval( tick, 30000 );
}() );
