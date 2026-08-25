/**
 * Wartungscheckliste: zeigt die Punkte des gewählten Intervalls und gibt
 * den Knopf erst frei, wenn alle abgehakt sind.
 *
 * Das ist eine Bequemlichkeit, keine Sicherung – geprüft wird auf dem
 * Server. Ohne JavaScript sind schlicht alle Listen sichtbar und der Knopf
 * bleibt bedienbar; die Prüfung greift dann beim Speichern.
 *
 * @package Lindenweg16
 */
( function () {
	'use strict';

	var form = document.getElementById( 'lw16-wartung' );

	if ( ! form ) {
		return;
	}

	var wahl   = document.getElementById( 'lw16-wartung-intervall' );
	var knopf  = document.getElementById( 'lw16-wartung-senden' );
	var stand  = document.getElementById( 'lw16-wartung-stand' );
	var bloecke = form.querySelectorAll( '[data-intervall]' );

	/**
	 * Der gerade sichtbare Block.
	 *
	 * @return {Element|null} Block.
	 */
	function aktiverBlock() {
		for ( var i = 0; i < bloecke.length; i++ ) {
			if ( bloecke[ i ].getAttribute( 'data-intervall' ) === wahl.value ) {
				return bloecke[ i ];
			}
		}
		return null;
	}

	/**
	 * Kästchen eines Blocks.
	 *
	 * @param {Element} block Block.
	 * @return {Array} Kästchen.
	 */
	function kaestchen( block ) {
		if ( ! block ) {
			return [];
		}
		return Array.prototype.slice.call( block.querySelectorAll( 'input[type="checkbox"]' ) );
	}

	/**
	 * Sichtbarkeit, Zählerstand und Knopf nachziehen.
	 */
	function zeichnen() {
		var block = aktiverBlock();

		for ( var i = 0; i < bloecke.length; i++ ) {
			var passt = bloecke[ i ] === block;
			bloecke[ i ].hidden = ! passt;

			// Nur die Kästchen des sichtbaren Blocks werden mitgeschickt.
			var felder = kaestchen( bloecke[ i ] );
			for ( var j = 0; j < felder.length; j++ ) {
				felder[ j ].disabled = ! passt;
			}
		}

		var felder  = kaestchen( block );
		var offen   = 0;
		var gesamt  = felder.length;

		for ( var k = 0; k < felder.length; k++ ) {
			if ( ! felder[ k ].checked ) {
				offen++;
			}
		}

		var pflicht = block && '1' === block.getAttribute( 'data-pflicht' );

		if ( knopf ) {
			knopf.disabled = pflicht && offen > 0;
		}

		if ( stand ) {
			if ( ! gesamt ) {
				stand.textContent = stand.getAttribute( 'data-frei' );
			} else if ( offen > 0 ) {
				stand.textContent = stand.getAttribute( 'data-offen' )
					.replace( '%1', String( gesamt - offen ) )
					.replace( '%2', String( gesamt ) );
			} else {
				stand.textContent = stand.getAttribute( 'data-fertig' );
			}
		}
	}

	wahl.addEventListener( 'change', zeichnen );
	form.addEventListener( 'change', function ( event ) {
		if ( event.target && 'checkbox' === event.target.type ) {
			zeichnen();
		}
	} );

	zeichnen();
}() );
