/**
 * Nachbestellen: Shop im neuen Fenster, Formular hier.
 *
 * Der Ablauf beim Nachbestellen ist immer derselbe – man legt im Shop des
 * Lieferanten in den Warenkorb und weiss dabei Menge, Preis und Liefertermin.
 * Genau dann sollen die Angaben erfasst werden, nicht eine Stunde später aus
 * dem Gedächtnis. Deshalb geht beides gleichzeitig auf: der Shop in einem
 * neuen Tab, das Formular hier.
 *
 * Ohne JavaScript bleibt der Knopf ein gewöhnlicher Link zum Shop und das
 * Formular steht offen darunter. Nichts geht verloren.
 *
 * @package Lindenweg16
 */
( function () {
	'use strict';

	var dialog = document.getElementById( 'lw16-bestellung' );

	if ( ! dialog || 'function' !== typeof dialog.showModal ) {
		return;
	}

	// Erst jetzt verstecken: Ohne showModal bleibt das Formular sichtbar.
	dialog.hidden = false;

	/**
	 * Ein Feld des Formulars füllen.
	 *
	 * @param {string} name Feldname.
	 * @param {string} wert Wert.
	 */
	function setze( name, wert ) {
		var feld = dialog.querySelector( '[name="' + name + '"]' );

		if ( feld ) {
			feld.value = wert || '';
		}
	}

	/**
	 * Datum in Tagen ab heute, als JJJJ-MM-TT.
	 *
	 * @param {number} tage Tage.
	 * @return {string} Datum.
	 */
	function inTagen( tage ) {
		var d = new Date();
		d.setDate( d.getDate() + ( parseInt( tage, 10 ) || 0 ) );
		return d.toISOString().slice( 0, 10 );
	}

	document.addEventListener( 'click', function ( event ) {
		var knopf = event.target.closest( '.lw16-bestellen' );

		if ( ! knopf ) {
			return;
		}

		event.preventDefault();

		var shop = knopf.getAttribute( 'href' );

		// Der Shop zuerst: Wird der Tab vom Browser blockiert, sieht man es,
		// solange man noch hinschaut.
		if ( shop && '#' !== shop ) {
			window.open( shop, '_blank', 'noopener' );
		}

		setze( 'item_type', knopf.getAttribute( 'data-typ' ) );
		setze( 'item_id', knopf.getAttribute( 'data-id' ) );
		setze( 'qty', knopf.getAttribute( 'data-menge' ) );
		setze( 'unit_price_net', knopf.getAttribute( 'data-preis' ) );
		setze( 'supplier', knopf.getAttribute( 'data-lieferant' ) );
		setze( 'ordered_on', inTagen( 0 ) );
		setze( 'expected_on', inTagen( knopf.getAttribute( 'data-lieferzeit' ) ) );
		setze( 'order_ref', '' );
		setze( 'note', '' );

		var titel = dialog.querySelector( '.lw16-bestellung__titel' );
		if ( titel ) {
			titel.textContent = knopf.getAttribute( 'data-name' ) || '';
		}

		dialog.showModal();

		var erstes = dialog.querySelector( '[name="qty"]' );
		if ( erstes ) {
			erstes.focus();
			erstes.select();
		}
	} );

	dialog.addEventListener( 'click', function ( event ) {
		if ( event.target.hasAttribute( 'data-schliessen' ) ) {
			event.preventDefault();
			dialog.close();
		}
	} );
}() );
