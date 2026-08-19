/**
 * Mobile Schnellerfassung.
 *
 * Offline-Toleranz ist der Kern: Wenn im Keller der Empfang wegbricht, darf
 * keine Eingabe verloren gehen. Jede Buchung wandert zuerst in eine Warte-
 * schlange im Browser und wird von dort gesendet; erst nach Bestätigung des
 * Servers verschwindet sie daraus.
 */
( function () {
	'use strict';

	var config = window.LW16Erfassung || {};
	var STORAGE_KEY = 'lw16_erfassung_queue';
	var context = null;

	/* ---------------------------------------------------------------- */
	/* Warteschlange                                                     */
	/* ---------------------------------------------------------------- */

	function readQueue() {
		try {
			return JSON.parse( window.localStorage.getItem( STORAGE_KEY ) || '[]' );
		} catch ( e ) {
			return [];
		}
	}

	function writeQueue( queue ) {
		try {
			window.localStorage.setItem( STORAGE_KEY, JSON.stringify( queue ) );
		} catch ( e ) {
			/* Speicher voll oder gesperrt – die Anzeige meldet es. */
		}
		renderQueue( queue );
	}

	function enqueue( endpoint, payload ) {
		var queue = readQueue();
		queue.push( {
			client_id: String( Date.now() ) + '-' + Math.random().toString( 36 ).slice( 2, 8 ),
			endpoint: endpoint,
			payload: payload,
			created: new Date().toISOString()
		} );
		writeQueue( queue );
	}

	function renderQueue( queue ) {
		var section = document.getElementById( 'lw16-queue' );
		var list = document.getElementById( 'lw16-queue-list' );

		if ( ! section || ! list ) {
			return;
		}

		list.innerHTML = '';

		if ( ! queue.length ) {
			section.hidden = true;
			return;
		}

		section.hidden = false;
		queue.forEach( function ( item ) {
			var li = document.createElement( 'li' );
			li.textContent = ( 'druckjobs' === item.endpoint ? 'Druckjob' : 'Zeit' ) +
				' · ' + item.created.substring( 0, 16 ).replace( 'T', ' ' );
			list.appendChild( li );
		} );
	}

	/* ---------------------------------------------------------------- */
	/* Senden                                                            */
	/* ---------------------------------------------------------------- */

	function post( endpoint, payload ) {
		return window.fetch( config.root + endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce
			},
			body: JSON.stringify( payload )
		} ).then( function ( response ) {
			return response.json().then( function ( body ) {
				if ( ! response.ok ) {
					var error = new Error( body && body.message ? body.message : 'HTTP ' + response.status );
					// Ein fachlicher Fehler (422) darf nicht endlos wiederholt werden.
					error.permanent = response.status >= 400 && response.status < 500;
					throw error;
				}
				return body;
			} );
		} );
	}

	function flush() {
		var queue = readQueue();

		if ( ! queue.length ) {
			return Promise.resolve();
		}

		status( config.i18n.sending );

		var item = queue[ 0 ];

		return post( item.endpoint, item.payload ).then( function () {
			var rest = readQueue();
			rest.shift();
			writeQueue( rest );
			status( config.i18n.sent, 'ok' );
			return flush();
		} ).catch( function ( error ) {
			if ( error.permanent ) {
				// Der Server lehnt die Buchung fachlich ab – sie aus der
				// Schlange nehmen und den Grund zeigen, sonst blockiert sie
				// alle folgenden.
				var rest = readQueue();
				rest.shift();
				writeQueue( rest );
				status( config.i18n.error + ' ' + error.message, 'error' );
				return flush();
			}
			status( config.i18n.queued, 'wait' );
		} );
	}

	function submit( endpoint, payload ) {
		enqueue( endpoint, payload );
		return flush();
	}

	function status( text, kind ) {
		var el = document.getElementById( 'lw16-status' );

		if ( ! el ) {
			return;
		}

		el.textContent = text;
		el.hidden = false;
		el.className = 'lw16-mobile__status' + ( kind ? ' is-' + kind : '' );

		if ( 'ok' === kind ) {
			window.setTimeout( function () {
				el.hidden = true;
			}, 2500 );
		}
	}

	/* ---------------------------------------------------------------- */
	/* Kontext laden und Maske vorbelegen                                */
	/* ---------------------------------------------------------------- */

	function fill( select, items, valueKey, labelFn, selected ) {
		select.innerHTML = '';
		items.forEach( function ( item ) {
			var option = document.createElement( 'option' );
			option.value = item[ valueKey ];
			option.textContent = labelFn( item );
			if ( String( selected ) === String( item[ valueKey ] ) ) {
				option.selected = true;
			}
			select.appendChild( option );
		} );
	}

	function applyArticleDefaults() {
		if ( ! context ) {
			return;
		}

		var articleId = document.getElementById( 'lw16-article' ).value;
		var article = context.articles.filter( function ( a ) {
			return String( a.id ) === String( articleId );
		} )[ 0 ];

		if ( ! article ) {
			return;
		}

		document.getElementById( 'lw16-parts_planned' ).value = article.parts_per_plate;
		document.getElementById( 'lw16-print_time_h' ).value = article.print_time_plate_h;
		document.getElementById( 'lw16-parts_ok' ).value = article.parts_per_plate;

		if ( ! article.has_active_revision ) {
			status( config.i18n.noRevision, 'error' );
		}
	}

	function loadContext() {
		var url = config.root + 'erfassung/kontext';

		if ( config.printerCode ) {
			url += '?printer=' + encodeURIComponent( config.printerCode );
		}

		return window.fetch( url, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce }
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( data ) {
			context = data;

			fill( document.getElementById( 'lw16-printer' ), data.printers, 'id', function ( p ) {
				return p.code + ( p.label ? ' · ' + p.label : '' );
			}, data.defaults.printer_id );

			fill( document.getElementById( 'lw16-article' ), data.articles, 'id', function ( a ) {
				return a.sku + ' · ' + a.name;
			}, data.defaults.article_id );

			var colors = Object.keys( data.colors ).map( function ( slug ) {
				return { slug: slug, label: data.colors[ slug ] };
			} );
			fill( document.getElementById( 'lw16-color' ), colors, 'slug', function ( c ) {
				return c.label;
			}, data.defaults.color );

			var lots = [ { id: 0, label: '–' } ].concat( data.lots.map( function ( l ) {
				return { id: l.id, label: l.lot_code + ' · ' + l.material + ' ' + l.color };
			} ) );
			fill( document.getElementById( 'lw16-lot' ), lots, 'id', function ( l ) {
				return l.label;
			}, data.defaults.filament_lot_id );

			var categories = Object.keys( data.categories ).map( function ( key ) {
				return { key: key, label: data.categories[ key ] };
			} );
			fill( document.getElementById( 'lw16-category' ), categories, 'key', function ( c ) {
				return c.label;
			}, 'produktion' );

			document.getElementById( 'lw16-plate_changes' ).value = data.defaults.plate_changes;
			applyArticleDefaults();
		} ).catch( function () {
			// Ohne Kontext bleibt die Maske leer, die Warteschlange aber nutzbar.
			status( config.i18n.queued, 'wait' );
		} );
	}

	/* ---------------------------------------------------------------- */
	/* Bedienung                                                         */
	/* ---------------------------------------------------------------- */

	function bind() {
		document.querySelectorAll( '.lw16-step' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var input = document.getElementById( 'lw16-' + button.dataset.target );
				var step = parseFloat( button.dataset.step );
				var value = parseFloat( String( input.value ).replace( ',', '.' ) ) || 0;

				value = Math.max( 0, Math.round( ( value + step ) * 100 ) / 100 );
				input.value = value;
			} );
		} );

		document.querySelectorAll( '.lw16-mobile__tabs button' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				document.querySelectorAll( '.lw16-mobile__tabs button' ).forEach( function ( other ) {
					other.classList.toggle( 'is-active', other === button );
				} );
				document.querySelectorAll( '[data-panel]' ).forEach( function ( panel ) {
					panel.hidden = panel.dataset.panel !== button.dataset.tab;
				} );
			} );
		} );

		document.getElementById( 'lw16-article' ).addEventListener( 'change', applyArticleDefaults );

		document.getElementById( 'lw16-form-druck' ).addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var data = Object.fromEntries( new FormData( event.target ).entries() );
			submit( 'druckjobs', data );
			status( config.i18n.saved, 'ok' );
		} );

		document.getElementById( 'lw16-form-zeit' ).addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var data = Object.fromEntries( new FormData( event.target ).entries() );
			submit( 'zeiten', data );
			event.target.reset();
			status( config.i18n.saved, 'ok' );
		} );

		// Beim Wiedererlangen der Verbindung nachsenden.
		window.addEventListener( 'online', flush );
		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden ) {
				flush();
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bind();
		renderQueue( readQueue() );
		loadContext().then( flush );
	} );
}() );
