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
	/* Scannen                                                           */
	/* ---------------------------------------------------------------- */

	/*
	 * Gescannt wird mit dem, was der Browser mitbringt: der BarcodeDetector.
	 * Bewusst keine Scanner-Bibliothek - sie waere ein Fremdpaket im Plugin
	 * und muesste mitgepflegt werden. Kann der Browser es nicht, bleibt das
	 * Eingabefeld: Ein Code ist kurz genug zum Abtippen, und ein Lager, das
	 * ohne Kamera stillsteht, waere schlechter als eines mit Tastatur.
	 */

	var scanState = { bin: null, article: null };
	var scanStream = null;
	var scanLoop = null;

	function scannerAvailable() {
		return 'BarcodeDetector' in window && navigator.mediaDevices && navigator.mediaDevices.getUserMedia;
	}

	function closeCamera() {
		if ( scanLoop ) {
			window.clearInterval( scanLoop );
			scanLoop = null;
		}
		if ( scanStream ) {
			scanStream.getTracks().forEach( function ( track ) {
				track.stop();
			} );
			scanStream = null;
		}
		document.getElementById( 'lw16-camera' ).hidden = true;
	}

	function openCamera( onCode ) {
		if ( ! scannerAvailable() ) {
			status( config.i18n.noScanner, 'error' );
			document.getElementById( 'lw16-manual' ).focus();
			return;
		}

		var box = document.getElementById( 'lw16-camera' );
		var video = document.getElementById( 'lw16-video' );
		var detector = new window.BarcodeDetector( {
			formats: [ 'qr_code', 'ean_13', 'ean_8', 'code_128', 'code_39', 'itf' ]
		} );

		document.getElementById( 'lw16-camera-hint' ).textContent = config.i18n.scanHint;
		box.hidden = false;

		navigator.mediaDevices.getUserMedia( { video: { facingMode: 'environment' } } ).then( function ( stream ) {
			scanStream = stream;
			video.srcObject = stream;
			return video.play();
		} ).then( function () {
			scanLoop = window.setInterval( function () {
				detector.detect( video ).then( function ( codes ) {
					if ( ! codes.length ) {
						return;
					}
					closeCamera();
					onCode( codes[ 0 ].rawValue );
				} ).catch( function () {
					/* Einzelne Bilder duerfen fehlschlagen. */
				} );
			}, 300 );
		} ).catch( function () {
			closeCamera();
			status( config.i18n.noCamera, 'error' );
			document.getElementById( 'lw16-manual' ).focus();
		} );
	}

	function lookup( code ) {
		return fetch( config.root + 'scan?code=' + encodeURIComponent( code ), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce }
		} ).then( function ( response ) {
			return response.json().then( function ( body ) {
				return { ok: response.ok, body: body };
			} );
		} );
	}

	function renderStock( result ) {
		var box = document.getElementById( 'lw16-stock' );
		var rows = [];

		if ( 'bin' === result.type && result.stock ) {
			result.stock.forEach( function ( row ) {
				rows.push( row.sku + ' ' + row.color + ' — ' + row.qty );
			} );
		}

		if ( 'article' === result.type && result.stock ) {
			result.stock.bins.forEach( function ( row ) {
				rows.push( row.bin + ' ' + row.color + ' — ' + row.qty );
			} );
			if ( Math.abs( result.stock.ohne_platz ) > 0.0005 ) {
				rows.push( config.i18n.unplaced + ' — ' + result.stock.ohne_platz );
			}
		}

		box.textContent = '';
		if ( ! rows.length ) {
			box.textContent = config.i18n.noStock;
		} else {
			var title = document.createElement( 'strong' );
			title.textContent = config.i18n.inStock;
			box.appendChild( title );
			var list = document.createElement( 'ul' );
			rows.forEach( function ( text ) {
				var li = document.createElement( 'li' );
				li.textContent = text;
				list.appendChild( li );
			} );
			box.appendChild( list );
		}
		box.hidden = false;
	}

	function applyScan( result ) {
		if ( 'bin' === result.type ) {
			scanState.bin = result;
			document.getElementById( 'lw16-slot-bin' ).textContent = result.code + ' ' + result.label;
		} else if ( 'article' === result.type ) {
			scanState.article = result;
			document.getElementById( 'lw16-slot-article' ).textContent = result.code + ' ' + result.label;
		} else if ( 'batch' === result.type ) {
			// Eine Charge zeigt auf ihren Artikel - der Werker scannt, was
			// aufs Teil gepraegt ist, nicht was in der Datenbank steht.
			scanState.article = {
				type: 'article',
				id: result.extra.article_id,
				code: result.code,
				label: result.label
			};
			document.getElementById( 'lw16-slot-article' ).textContent = result.code;
		} else {
			status( config.i18n.notFound + ' ' + result.code, 'error' );
			return;
		}

		renderStock( result );
		status( result.code, 'ok' );
	}

	function handleScan( code ) {
		lookup( code ).then( function ( response ) {
			if ( ! response.ok ) {
				status( config.i18n.notFound + ' ' + code, 'error' );
				return;
			}
			applyScan( response.body );
		} ).catch( function () {
			status( config.i18n.notFound + ' ' + code, 'error' );
		} );
	}

	/* ---------------------------------------------------------------- */
	/* Picken                                                            */
	/* ---------------------------------------------------------------- */

	var picklists = [];

	function loadPicklists() {
		return fetch( config.root + 'picklisten', {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce }
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( data ) {
			picklists = data || [];
			renderPicklists();
		} ).catch( function () {
			picklists = [];
			renderPicklists();
		} );
	}

	function renderPicklists() {
		var box = document.getElementById( 'lw16-picklists' );
		box.textContent = '';

		if ( ! picklists.length ) {
			box.textContent = config.i18n.noPicklist;
			return;
		}

		picklists.forEach( function ( list ) {
			var section = document.createElement( 'section' );
			var heading = document.createElement( 'h2' );
			heading.textContent = list.code;
			section.appendChild( heading );

			list.items.forEach( function ( item ) {
				var row = document.createElement( 'div' );
				row.className = 'lw16-pickrow' + ( item.done ? ' is-done' : '' );

				var where = document.createElement( 'span' );
				where.className = 'lw16-pickrow__bin';
				where.textContent = item.bin || config.i18n.noBin;

				var what = document.createElement( 'span' );
				what.className = 'lw16-pickrow__what';
				what.textContent = item.sku + ' · ' + item.color + ' · ' +
					( item.qty_required - item.qty_picked ) + '×';

				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'lw16-pickrow__do';
				button.textContent = '✓';
				button.disabled = !! item.done;
				button.addEventListener( 'click', function () {
					pickItem( item.id, 0, '' );
				} );

				row.appendChild( where );
				row.appendChild( what );
				row.appendChild( button );
				section.appendChild( row );
			} );

			box.appendChild( section );
		} );
	}

	function pickItem( itemId, qty, scanned ) {
		return fetch( config.root + 'picklisten/position', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( { item_id: itemId, qty: qty, scanned: scanned } )
		} ).then( function ( response ) {
			return response.json().then( function ( body ) {
				if ( ! response.ok ) {
					status( body.message || config.i18n.error, 'error' );
					return;
				}
				status( config.i18n.picked, 'ok' );
				loadPicklists();
			} );
		} ).catch( function () {
			status( config.i18n.error, 'error' );
		} );
	}

	function pickByScan( code ) {
		// Die erste offene Position, deren Artikel zum Scan passt.
		var target = null;

		picklists.some( function ( list ) {
			return list.items.some( function ( item ) {
				if ( ! item.done && code.toUpperCase().indexOf( item.sku.toUpperCase() ) !== -1 ) {
					target = item;
					return true;
				}
				return false;
			} );
		} );

		if ( ! target ) {
			status( config.i18n.notFound + ' ' + code, 'error' );
			return;
		}

		pickItem( target.id, 0, code );
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

		// Scannen
		document.querySelectorAll( '.lw16-scan[data-scan]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				openCamera( 'pick' === button.dataset.scan ? pickByScan : handleScan );
			} );
		} );

		document.getElementById( 'lw16-camera-close' ).addEventListener( 'click', closeCamera );

		document.getElementById( 'lw16-pick-reload' ).addEventListener( 'click', loadPicklists );

		// Eingetippter Code: Enter loest dieselbe Aufloesung aus wie ein Scan.
		document.getElementById( 'lw16-manual' ).addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' !== event.key ) {
				return;
			}
			event.preventDefault();
			var value = event.target.value.trim();
			if ( value ) {
				handleScan( value );
				event.target.value = '';
			}
		} );

		// Umlagern braucht ein zweites Ziel, alles andere nicht.
		document.getElementById( 'lw16-movetype' ).addEventListener( 'change', function ( event ) {
			document.getElementById( 'lw16-tobin-field' ).hidden = 'umlagerung' !== event.target.value;
		} );

		document.getElementById( 'lw16-form-lager' ).addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			if ( ! scanState.article ) {
				status( config.i18n.needBoth, 'error' );
				return;
			}

			var type = document.getElementById( 'lw16-movetype' ).value;
			var qty = parseFloat( String( document.getElementById( 'lw16-lager-qty' ).value ).replace( ',', '.' ) ) || 0;

			// Abgangsarten werden negativ gebucht - der Werker soll nicht
			// ueberlegen muessen, wann ein Minus vor die Zahl gehoert.
			if ( [ 'verkauf', 'ausschuss', 'muster', 'kommission_raus' ].indexOf( type ) !== -1 ) {
				qty = -Math.abs( qty );
			}

			submit( 'lager/bewegung', {
				article_id: scanState.article.id,
				bin_id: scanState.bin ? scanState.bin.id : 0,
				to_bin_id: 'umlagerung' === type ? document.getElementById( 'lw16-tobin' ).value : 0,
				color: document.getElementById( 'lw16-lager-color' ).value,
				movement_type: type,
				qty: qty
			} );

			status( config.i18n.saved, 'ok' );
		} );

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

	function fillLagerSelects() {
		var types = document.getElementById( 'lw16-movetype' );
		Object.keys( config.movementTypes || {} ).forEach( function ( key ) {
			var option = document.createElement( 'option' );
			option.value = key;
			option.textContent = config.movementTypes[ key ];
			types.appendChild( option );
		} );
		types.value = 'korrektur';

		var colors = document.getElementById( 'lw16-lager-color' );
		Object.keys( config.colors || {} ).forEach( function ( key ) {
			var option = document.createElement( 'option' );
			option.value = key;
			option.textContent = config.colors[ key ];
			colors.appendChild( option );
		} );

		var target = document.getElementById( 'lw16-tobin' );
		( config.bins || [] ).forEach( function ( bin ) {
			var option = document.createElement( 'option' );
			option.value = bin.id;
			option.textContent = bin.label;
			target.appendChild( option );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		fillLagerSelects();
		bind();
		renderQueue( readQueue() );
		loadContext().then( flush );
		loadPicklists();
	} );
}() );
