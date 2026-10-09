/**
 * AR-Seite: model-viewer einbinden, Fallbacks steuern, anonym zählen.
 *
 * Wird als ES-Modul geladen, ausschließlich auf Seiten mit dem Shortcode
 * [l16_ar_ansicht]. Die Bibliothek liegt lokal im Plugin; es gibt keinen
 * CDN-Aufruf.
 */
import { ModelViewerElement } from '../vendor/model-viewer/model-viewer.min.js';

( function () {
	'use strict';

	var root = document.querySelector( '[data-lw16-arv]' );
	if ( ! root ) {
		return;
	}

	/*
	 * Die Bibliothek lädt Hilfsdateien für komprimierte Modelle von Dritten
	 * nach: Draco und KTX2 von gstatic.com, den Lottie-Lader von jsDelivr.
	 * Das würde die IP-Adresse des Besuchers übertragen. Deshalb zeigen
	 * diese Orte auf das eigene Plugin-Verzeichnis, wo es solche Dateien
	 * nicht gibt: Ein komprimiertes Modell scheitert sichtbar, statt Daten
	 * an Dritte zu schicken. Modelle werden unkomprimiert exportiert.
	 *
	 * Meshopt bleibt bewusst ungesetzt: Die Bibliothek lädt dafür von sich
	 * aus nichts, und ein gesetzter Ort würde auch ohne Meshopt-Modell
	 * sofort abgerufen – und, wenn er fehlt, jedes Modell scheitern lassen.
	 */
	var vendor = root.getAttribute( 'data-vendor' ) || '';
	if ( vendor ) {
		ModelViewerElement.dracoDecoderLocation = vendor + 'draco/';
		ModelViewerElement.ktx2TranscoderLocation = vendor + 'ktx2/';
		ModelViewerElement.lottieLoaderLocation = vendor + 'lottie/LottieLoader.js';
	}

	var viewer = root.querySelector( 'model-viewer' );
	var desktop = root.querySelector( '[data-lw16-arv-desktop]' );
	var failure = root.querySelector( '[data-lw16-arv-fehler]' );
	var start = root.querySelector( '[data-lw16-arv-start]' );
	var productId = parseInt( root.getAttribute( 'data-product' ), 10 ) || 0;
	var restBase = root.getAttribute( 'data-rest' ) || '';

	/* ---- Fallback ohne AR (Desktop) ----------------------------------- */

	function updateDesktopNotice() {
		if ( desktop && viewer ) {
			desktop.hidden = !! viewer.canActivateAR;
		}
	}

	if ( viewer ) {
		viewer.addEventListener( 'load', updateDesktopNotice );
		viewer.addEventListener( 'error', function () {
			if ( failure ) {
				failure.hidden = false;
			}
			updateDesktopNotice();
		} );
		// Falls das Modell schon geladen ist, bevor das Skript läuft.
		if ( viewer.loaded ) {
			updateDesktopNotice();
		}
	}

	/* ---- Anonymer Zähler: „AR gestartet“ ------------------------------ */

	var nonce = '';
	var counted = false;

	// Die Nonce wird vorab geholt, damit sie beim Antippen bereitliegt –
	// danach wechselt die Seite in die AR-App, und es bliebe keine Zeit.
	if ( productId && restBase && window.fetch ) {
		fetch( restBase + 'ar-nonce', { credentials: 'omit', cache: 'no-store' } )
			.then( function ( r ) {
				return r.ok ? r.json() : {};
			} )
			.then( function ( j ) {
				nonce = ( j && j.nonce ) || '';
			} )
			.catch( function () {} );
	}

	if ( start ) {
		start.addEventListener( 'click', function () {
			if ( counted || ! nonce ) {
				return;
			}
			counted = true;
			try {
				fetch( restBase + 'ar-event', {
					method: 'POST',
					credentials: 'omit',
					keepalive: true,
					headers: { 'Content-Type': 'application/json', 'X-LW16-Nonce': nonce },
					body: JSON.stringify( { product: productId, event: 'started' } )
				} ).catch( function () {} );
			} catch ( e ) {
				/* Zählen darf nie etwas kaputt machen. */
			}
		} );
	}
}() );
