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

	/* ---------------------------------------------------------------- */
	/* Kommission: eine Liste statt einer Zeile                          */
	/* ---------------------------------------------------------------- */

	/**
	 * Die Kommissionsliste aufbauen.
	 *
	 * Eine Zeile je Artikel, darin ein Feld je Farbe. Der Bestand des
	 * gewaehlten Haendlers steht als kleine Zahl daneben: Beim Zuruecknehmen
	 * und Abrechnen geht es fast immer um genau das, was schon draussen
	 * liegt, und niemand soll mehr abrechnen als das.
	 */
	function kommissionAufbauen() {
		if ( ! context || ! context.sellable ) {
			return;
		}

		var liste = document.getElementById( 'lw16-komm-liste' );
		var farben = Object.keys( context.colors );

		liste.innerHTML = '';

		context.sellable.forEach( function ( article ) {
			var zeile = document.createElement( 'div' );
			zeile.className = 'lw16-komm__artikel';
			zeile.dataset.suche = ( article.sku + ' ' + article.name ).toLowerCase();

			var kopf = document.createElement( 'div' );
			kopf.className = 'lw16-komm__name';
			kopf.textContent = article.sku;

			var name = document.createElement( 'span' );
			name.textContent = article.name;
			kopf.appendChild( name );
			zeile.appendChild( kopf );

			var felder = document.createElement( 'div' );
			felder.className = 'lw16-komm__farben';

			farben.forEach( function ( slug ) {
				var label = document.createElement( 'label' );
				label.className = 'lw16-komm__feld';

				var text = document.createElement( 'span' );
				text.textContent = context.colors[ slug ];

				var vorhanden = document.createElement( 'b' );
				vorhanden.dataset.vorhanden = article.id + '|' + slug;
				text.appendChild( vorhanden );

				var feld = document.createElement( 'input' );
				feld.type = 'text';
				feld.inputMode = 'numeric';
				feld.placeholder = '0';
				feld.dataset.article = article.id;
				feld.dataset.color = slug;

				label.appendChild( text );
				label.appendChild( feld );
				felder.appendChild( label );
			} );

			zeile.appendChild( felder );
			liste.appendChild( zeile );
		} );

		kommissionBestandZeigen();
	}

	/**
	 * Den Bestand des gewaehlten Haendlers in die Liste schreiben.
	 */
	function kommissionBestandZeigen() {
		if ( ! context || ! context.contacts ) {
			return;
		}

		var id = document.getElementById( 'lw16-komm-contact' ).value;
		var contact = context.contacts.filter( function ( c ) {
			return String( c.id ) === String( id );
		} )[ 0 ];

		var bestand = contact ? contact.commission : {};

		document.querySelectorAll( '[data-vorhanden]' ).forEach( function ( el ) {
			var menge = bestand[ el.dataset.vorhanden ];

			el.textContent = menge ? ' ' + menge : '';
			el.parentNode.parentNode.classList.toggle( 'lw16-komm__feld--vorhanden', !! menge );
		} );
	}

	/**
	 * Die eingetippten Mengen einsammeln.
	 *
	 * @return {Array} Zeilen mit article_id, color, qty.
	 */
	function kommissionZeilen() {
		var zeilen = [];

		document.querySelectorAll( '#lw16-komm-liste input[data-article]' ).forEach( function ( feld ) {
			if ( '' === feld.value.trim() ) {
				return;
			}

			zeilen.push( {
				article_id: parseInt( feld.dataset.article, 10 ),
				color: feld.dataset.color,
				qty: feld.value.trim()
			} );
		} );

		return zeilen;
	}

	/**
	 * Die Summe unter der Liste mitfuehren.
	 *
	 * Wer zwanzig Felder ausfuellt, will vor dem Buchen sehen, was er da
	 * gleich bucht - nicht danach in der Bewegungsliste nachzaehlen.
	 */
	function kommissionSummeZeigen() {
		var zeilen = kommissionZeilen();
		var stueck = 0;

		zeilen.forEach( function ( z ) {
			stueck += parseFloat( String( z.qty ).replace( ',', '.' ) ) || 0;
		} );

		var el = document.getElementById( 'lw16-komm-summe' );

		el.hidden = 0 === zeilen.length;
		el.textContent = zeilen.length + ' ' + ( 1 === zeilen.length ? config.i18n.position : config.i18n.positionen )
			+ ' · ' + stueck + ' ' + config.i18n.stueck;
	}

	/**
	 * Die Liste auf einen Suchbegriff eindampfen.
	 *
	 * Bei sechzig Artikeln ist Scrollen am Handy die eigentliche Arbeit.
	 */
	function kommissionFiltern() {
		var suche = document.getElementById( 'lw16-komm-suche' ).value.trim().toLowerCase();

		document.querySelectorAll( '#lw16-komm-liste .lw16-komm__artikel' ).forEach( function ( zeile ) {
			// Eine ausgefuellte Zeile bleibt immer sichtbar, sonst bucht man
			// blind eine Menge mit, die man gerade nicht sieht.
			var gefuellt = Array.prototype.some.call(
				zeile.querySelectorAll( 'input[data-article]' ),
				function ( feld ) {
					return '' !== feld.value.trim();
				}
			);

			zeile.hidden = '' !== suche && ! gefuellt && -1 === zeile.dataset.suche.indexOf( suche );
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

			var contacts = data.contacts || [];
			fill( document.getElementById( 'lw16-komm-contact' ), contacts, 'id', function ( c ) {
				return c.name;
			}, contacts.length ? contacts[ 0 ].id : 0 );

			var kommTypen = Object.keys( data.commissionTypes || {} ).map( function ( key ) {
				return { key: key, label: data.commissionTypes[ key ] };
			} );
			fill( document.getElementById( 'lw16-komm-type' ), kommTypen, 'key', function ( t ) {
				return t.label;
			}, 'kommission_raus' );

			kommissionAufbauen();

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

	/*
	 * Warum das Scannen nicht geht - falls es nicht geht.
	 *
	 * Frueher hiess es pauschal "kein Scanner", was in die Irre fuehrte:
	 * Auf dem iPhone ist die Kamera da, es fehlt der Decoder. Die drei
	 * Ursachen brauchen drei verschiedene Antworten, denn nur eine davon
	 * kann der Benutzer selbst beheben.
	 *
	 * @return {string|null} Schluessel der Meldung, null wenn alles da ist.
	 */
	function scannerProblem() {
		// Kamerazugriff gibt es nur im sicheren Kontext. Ueber http bleibt
		// mediaDevices undefiniert - das sieht aus wie ein fehlendes
		// Geraet, ist aber die Adresse.
		if ( window.isSecureContext === false ) {
			return 'noSecure';
		}

		if ( ! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia ) {
			return 'noCameraApi';
		}

		// Ein fehlender BarcodeDetector ist kein Hindernis mehr: Fuer
		// WebKit - also iPhone und iPad - wird beim ersten Scan ein
		// Decoder nachgeladen. Nur QR-Codes, aber die eigenen Etiketten
		// sind QR.
		return null;
	}

	function scannerAvailable() {
		return null === scannerProblem();
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

	/*
	 * Den Decoder nachladen, den dieser Browser nicht mitbringt.
	 *
	 * Nur fuer WebKit - also iPhone und iPad. Auf Android und am Rechner
	 * bleibt die Datei ungeladen, dort erledigt es der eingebaute
	 * BarcodeDetector.
	 */
	var fallbackLaden = null;

	function ladeFallback() {
		if ( fallbackLaden ) {
			return fallbackLaden;
		}

		fallbackLaden = new Promise( function ( resolve, reject ) {
			if ( window.jsQR ) {
				resolve();
				return;
			}

			var script = document.createElement( 'script' );
			script.src = config.decoderUrl;
			script.onload = function () {
				return window.jsQR ? resolve() : reject();
			};
			script.onerror = reject;
			document.head.appendChild( script );
		} );

		return fallbackLaden;
	}

	/*
	 * Ein Leser mit immer derselben Schnittstelle: detect(video) liefert
	 * eine Liste gefundener Codes. Welcher Weg dahinter steckt, muss der
	 * Rest des Skripts nicht wissen.
	 */
	function ersatzLeser() {
		var canvas = document.createElement( 'canvas' );
		var ctx = canvas.getContext( '2d', { willReadFrequently: true } );

		return {
			detect: function ( video ) {
				var w = video.videoWidth;
				var h = video.videoHeight;

				if ( ! w || ! h ) {
					return Promise.resolve( [] );
				}

				// Auf Breite 480 herunterrechnen: genug fuer einen Code aus
				// Armlaenge, und schonend genug fuer ein Telefon, das den
				// Decoder in JavaScript ausfuehrt.
				var scale = Math.min( 1, 480 / w );
				canvas.width = Math.round( w * scale );
				canvas.height = Math.round( h * scale );
				ctx.drawImage( video, 0, 0, canvas.width, canvas.height );

				var bild = ctx.getImageData( 0, 0, canvas.width, canvas.height );
				var treffer = window.jsQR( bild.data, bild.width, bild.height, {
					inversionAttempts: 'dontInvert'
				} );

				return Promise.resolve( treffer ? [ { rawValue: treffer.data } ] : [] );
			}
		};
	}

	function leserHolen() {
		if ( 'BarcodeDetector' in window ) {
			return Promise.resolve( new window.BarcodeDetector( {
				formats: [ 'qr_code', 'ean_13', 'ean_8', 'code_128', 'code_39', 'itf' ]
			} ) );
		}

		return ladeFallback().then( ersatzLeser );
	}

	function openCamera( onCode ) {
		var problem = scannerProblem();

		if ( problem ) {
			status( config.i18n[ problem ] || config.i18n.noScanner, 'error' );
			focusManual();
			return;
		}

		var box = document.getElementById( 'lw16-camera' );
		var video = document.getElementById( 'lw16-video' );

		document.getElementById( 'lw16-camera-hint' ).textContent = config.i18n.scanHint;
		box.hidden = false;

		var leser = null;
		var laeuft = false;

		leserHolen().then( function ( geholt ) {
			leser = geholt;
			return navigator.mediaDevices.getUserMedia( { video: { facingMode: 'environment' } } );
		} ).then( function ( stream ) {
			scanStream = stream;
			video.srcObject = stream;
			return video.play();
		} ).then( function () {
			// Der nachgeladene Decoder rechnet in JavaScript und braucht
			// laenger als ein Bildabstand. Ohne diese Sperre stapeln sich
			// die Auswertungen und das Telefon wird warm und langsam.
			scanLoop = window.setInterval( function () {
				if ( laeuft || ! leser ) {
					return;
				}
				laeuft = true;

				leser.detect( video ).then( function ( codes ) {
					laeuft = false;
					if ( ! codes.length ) {
						return;
					}
					closeCamera();
					onCode( codes[ 0 ].rawValue );
				} ).catch( function () {
					/* Einzelne Bilder duerfen fehlschlagen. */
					laeuft = false;
				} );
			}, 300 );
		} ).catch( function ( fehler ) {
			closeCamera();

			// Der Decoder kam nicht - oder die Kamera. Zwei sehr
			// verschiedene Ursachen, und der Browser nennt bei der Kamera
			// sogar welche genau. Das gehoert weitergegeben, sonst sucht
			// man an der falschen Stelle.
			status( leser ? kameraFehlertext( fehler ) : config.i18n.noDecoderLoad, 'error' );
			focusManual();
		} );
	}

	/*
	 * Aus dem Fehler von getUserMedia eine Antwort machen, mit der sich
	 * etwas anfangen laesst.
	 */
	function kameraFehlertext( fehler ) {
		var name = fehler && fehler.name ? fehler.name : '';

		if ( 'NotAllowedError' === name || 'SecurityError' === name ) {
			return config.i18n.camDenied;
		}

		if ( 'NotFoundError' === name || 'OverconstrainedError' === name ) {
			return config.i18n.camNone;
		}

		if ( 'NotReadableError' === name ) {
			return config.i18n.camBusy;
		}

		return config.i18n.noCamera + ( name ? ' (' + name + ')' : '' );
	}

	/*
	 * Was der Scan-Knopf hier kann - noch bevor jemand darauf drueckt.
	 *
	 * Eine Fehlermeldung, die erst nach dem Tippen kommt und wieder
	 * verschwindet, hilft beim Suchen nicht. Diese Zeile steht fest da.
	 */
	function zeigeScanHinweis() {
		var problem = scannerProblem();
		var felder = document.querySelectorAll( '[data-scaninfo]' );
		var text = '';

		if ( 'noSecure' === problem ) {
			// Die Adresse gehoert dazu: http gegen https ist genau der
			// Unterschied, um den es hier geht.
			text = config.i18n.noSecure + ' (' + window.location.protocol + '//' + window.location.host + ')';
		} else if ( problem ) {
			text = config.i18n[ problem ] || config.i18n.noScanner;
		} else if ( ! ( 'BarcodeDetector' in window ) ) {
			text = config.i18n.qrOnly;
		}

		for ( var i = 0; i < felder.length; i++ ) {
			felder[ i ].textContent = text;
			felder[ i ].hidden = '' === text;
		}
	}

	/*
	 * Das Eingabefeld des gerade offenen Reiters ansteuern. Wer nicht
	 * scannen kann, soll wenigstens sofort tippen koennen - ohne erst das
	 * richtige Feld zu suchen.
	 */
	function focusManual() {
		var felder = document.querySelectorAll( '.lw16-manual' );

		for ( var i = 0; i < felder.length; i++ ) {
			if ( felder[ i ].offsetParent !== null ) {
				felder[ i ].focus();
				return;
			}
		}
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

	/*
	 * Welche Liste ist gewaehlt? Leer heisst: alle.
	 *
	 * Beim ersten Laden entscheidet der QR-Code darueber - wer mit einem
	 * Auftrag ins Regal geht, will nicht erst zwischen sechs offenen
	 * Listen suchen. Danach entscheidet die Auswahl, damit das Werkzeug
	 * offen bleiben und mehrere Listen nacheinander abarbeiten kann.
	 */
	var pickWahl = null;

	function gewaehlteListe() {
		if ( null !== pickWahl ) {
			return pickWahl;
		}

		return config.pickCode ? String( config.pickCode ).toUpperCase() : '';
	}

	function visiblePicklists() {
		var wahl = gewaehlteListe();

		if ( ! wahl ) {
			return picklists;
		}

		return picklists.filter( function ( list ) {
			return String( list.code ).toUpperCase() === wahl;
		} );
	}

	/*
	 * Beschriftung einer Liste: woher sie kommt und wie viel noch offen
	 * ist. Beides zusammen reicht, um ohne Oeffnen zu entscheiden.
	 */
	function listenLabel( list ) {
		var herkunft = 'fertigung' === list.source ? config.i18n.fromWork : config.i18n.fromOrders;
		var offen = list.open > 0
			? String( config.i18n.openCount ).replace( '%d', list.open )
			: config.i18n.listDone;

		return list.code + ' · ' + herkunft + ' · ' + offen;
	}

	function renderPicklistSelect() {
		var select = document.getElementById( 'lw16-picklist-select' );
		var wahl = gewaehlteListe();

		select.textContent = '';

		var alle = document.createElement( 'option' );
		alle.value = '';
		alle.textContent = config.i18n.allLists;
		select.appendChild( alle );

		picklists.forEach( function ( list ) {
			var option = document.createElement( 'option' );
			option.value = String( list.code ).toUpperCase();
			option.textContent = listenLabel( list );
			select.appendChild( option );
		} );

		// Eine Liste, die inzwischen abgearbeitet und verschwunden ist,
		// darf die Auswahl nicht auf einen toten Wert stellen.
		var vorhanden = wahl && picklists.some( function ( list ) {
			return String( list.code ).toUpperCase() === wahl;
		} );

		select.value = vorhanden ? wahl : '';

		if ( wahl && ! vorhanden ) {
			pickWahl = '';
		}
	}

	function renderPicklists() {
		var box = document.getElementById( 'lw16-picklists' );

		renderPicklistSelect();

		var shown = visiblePicklists();

		box.textContent = '';

		if ( ! shown.length ) {
			box.textContent = gewaehlteListe() ? config.i18n.pickGone : config.i18n.noPicklist;
			return;
		}

		shown.forEach( function ( list ) {
			var section = document.createElement( 'section' );
			var heading = document.createElement( 'h2' );
			heading.textContent = listenLabel( list );
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

		visiblePicklists().some( function ( list ) {
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
	/* Zeit-Timer                                                        */
	/* ---------------------------------------------------------------- */

	/*
	 * Das Geraet wandert den ganzen Tag mit. Es wird gesperrt, in die
	 * Tasche gesteckt, die Seite fliegt aus dem Speicher. Ein Zaehler, der
	 * im Sekundentakt hochzaehlt, ueberlebt das nicht.
	 *
	 * Gespeichert wird deshalb nur der Startzeitpunkt als Zeitstempel; die
	 * Dauer ergibt sich beim Hinsehen aus der Uhr. Der Sekundentakt unten
	 * bewegt nur die Anzeige, nie den Wert.
	 */

	var ZEIT_KEY = 'lw16_zeit_timer';

	// Zwoelf Stunden am Stueck, dieselbe Grenze wie beim Fertigungsauftrag:
	// mehr ist keine Schicht, sondern ein vergessener Timer.
	var ZEIT_MAX_SEGMENT = 43200;

	var zeitTakt = null;

	function zeitLesen() {
		try {
			return JSON.parse( window.localStorage.getItem( ZEIT_KEY ) || '{}' ) || {};
		} catch ( e ) {
			return {};
		}
	}

	function zeitSchreiben( stand ) {
		try {
			window.localStorage.setItem( ZEIT_KEY, JSON.stringify( stand ) );
		} catch ( e ) {
			/* Speicher gesperrt - der Timer laeuft dann nur bis zum Neuladen. */
		}
	}

	/*
	 * Reine Rechnung: erfasste Sekunden zu einem Zeitpunkt.
	 *
	 * @param {Object} stand {seconds, startedAt}
	 * @param {number} jetzt Zeitstempel in Millisekunden.
	 */
	function zeitSekunden( stand, jetzt ) {
		var summe = Math.max( 0, Math.floor( stand.seconds || 0 ) );

		if ( ! stand.startedAt ) {
			return summe;
		}

		var lauf = Math.floor( ( jetzt - stand.startedAt ) / 1000 );

		// Eine rueckwaerts gestellte Uhr darf keine negative Zeit ergeben.
		if ( lauf <= 0 ) {
			return summe;
		}

		return summe + Math.min( ZEIT_MAX_SEGMENT, lauf );
	}

	function zeitGekappt( stand, jetzt ) {
		if ( ! stand.startedAt ) {
			return false;
		}

		return Math.floor( ( jetzt - stand.startedAt ) / 1000 ) > ZEIT_MAX_SEGMENT;
	}

	function zeitFormat( sekunden ) {
		var h = Math.floor( sekunden / 3600 );
		var m = Math.floor( ( sekunden % 3600 ) / 60 );
		var s = Math.floor( sekunden % 60 );

		return h + ':' + ( m < 10 ? '0' : '' ) + m + ':' + ( s < 10 ? '0' : '' ) + s;
	}

	/*
	 * Sekunden als Stundenwert fuer das Formular.
	 *
	 * Nie auf 0 runden, solange die Uhr lief - der Server wiese einen
	 * Eintrag ueber null Stunden zurueck, und die Arbeit waere weg.
	 */
	function zeitStunden( sekunden ) {
		if ( sekunden <= 0 ) {
			return 0;
		}

		return Math.max( 0.01, Math.round( sekunden / 36 ) / 100 );
	}

	function zeitAnzeigen() {
		var stand = zeitLesen();
		var jetzt = Date.now();
		var sekunden = zeitSekunden( stand, jetzt );
		var laeuft = !! stand.startedAt;

		document.getElementById( 'lw16-zeit-anzeige' ).textContent = zeitFormat( sekunden );

		var toggle = document.getElementById( 'lw16-zeit-toggle' );
		toggle.textContent = laeuft ? config.i18n.timerPause : config.i18n.timerStart;
		toggle.classList.toggle( 'is-running', laeuft );

		document.getElementById( 'lw16-zeit-reset' ).hidden = sekunden <= 0;

		var hinweis = document.getElementById( 'lw16-zeit-hinweis' );
		var text = '';

		if ( zeitGekappt( stand, jetzt ) ) {
			text = config.i18n.timerCapped;
		} else if ( laeuft ) {
			text = config.i18n.timerRuns;
		} else if ( sekunden > 0 ) {
			text = config.i18n.timerHeld;
		}

		hinweis.textContent = text;
		hinweis.hidden = '' === text;

		// Solange die Uhr etwas erfasst hat, gehoert ihr das Stundenfeld.
		// Wer den Timer nie benutzt, tippt weiter wie bisher.
		if ( sekunden > 0 ) {
			document.getElementById( 'lw16-hours' ).value = String( zeitStunden( sekunden ) ).replace( '.', ',' );
		}
	}

	function zeitTaktSetzen() {
		if ( zeitTakt ) {
			window.clearInterval( zeitTakt );
			zeitTakt = null;
		}

		if ( zeitLesen().startedAt ) {
			zeitTakt = window.setInterval( zeitAnzeigen, 1000 );
		}
	}

	function zeitUmschalten() {
		var stand = zeitLesen();

		if ( stand.startedAt ) {
			zeitSchreiben( { seconds: zeitSekunden( stand, Date.now() ), startedAt: null } );
		} else {
			zeitSchreiben( { seconds: Math.max( 0, stand.seconds || 0 ), startedAt: Date.now() } );
		}

		zeitAnzeigen();
		zeitTaktSetzen();
	}

	function zeitVerwerfen() {
		zeitSchreiben( {} );
		document.getElementById( 'lw16-hours' ).value = '1';
		zeitAnzeigen();
		zeitTaktSetzen();
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

		document.getElementById( 'lw16-picklist-select' ).addEventListener( 'change', function ( event ) {
			pickWahl = event.target.value;
			renderPicklists();
		} );

		// Getippter Code im Reiter Picken: dieselbe Zuordnung wie ein Scan.
		document.getElementById( 'lw16-pick-manual' ).addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' !== event.key ) {
				return;
			}
			event.preventDefault();
			var value = event.target.value.trim();
			if ( value ) {
				pickByScan( value );
				event.target.value = '';
			}
		} );

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

		document.getElementById( 'lw16-komm-contact' ).addEventListener( 'change', kommissionBestandZeigen );
		document.getElementById( 'lw16-komm-suche' ).addEventListener( 'input', kommissionFiltern );

		document.getElementById( 'lw16-komm-liste' ).addEventListener( 'input', function ( event ) {
			if ( event.target.dataset && event.target.dataset.article ) {
				kommissionSummeZeigen();
			}
		} );

		document.getElementById( 'lw16-form-kommission' ).addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var zeilen = kommissionZeilen();

			if ( ! zeilen.length ) {
				status( config.i18n.keineMenge, 'error' );
				return;
			}

			submit( 'kommission', {
				contact_id: parseInt( document.getElementById( 'lw16-komm-contact' ).value, 10 ),
				movement_type: document.getElementById( 'lw16-komm-type' ).value,
				lines: zeilen
			} );

			// Gebuchte Mengen sind verbraucht. Blieben sie stehen, buchte man
			// dieselbe Lieferung beim naechsten Absenden ein zweites Mal.
			document.querySelectorAll( '#lw16-komm-liste input[data-article]' ).forEach( function ( feld ) {
				feld.value = '';
			} );

			kommissionSummeZeigen();
			kommissionFiltern();
			status( config.i18n.saved, 'ok' );
		} );

		document.getElementById( 'lw16-zeit-toggle' ).addEventListener( 'click', zeitUmschalten );
		document.getElementById( 'lw16-zeit-reset' ).addEventListener( 'click', zeitVerwerfen );

		document.getElementById( 'lw16-form-zeit' ).addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var data = Object.fromEntries( new FormData( event.target ).entries() );
			submit( 'zeiten', data );
			event.target.reset();

			// Gebuchte Zeit ist verbraucht. Bliebe die Uhr stehen, buchte
			// man dieselben Stunden beim naechsten Mal ein zweites Mal.
			zeitVerwerfen();

			status( config.i18n.saved, 'ok' );
		} );

		// Beim Wiedererlangen der Verbindung nachsenden.
		window.addEventListener( 'online', flush );

		// Das Werkzeug soll offen bleiben duerfen. Wer zurueckkommt, sieht
		// deshalb den aktuellen Stand - auch wenn inzwischen am Rechner
		// eine Liste dazugekommen ist.
		document.addEventListener( 'visibilitychange', function () {
			if ( document.hidden ) {
				return;
			}
			flush();
			loadPicklists();

			// Aus der Tasche zurueck: Die Anzeige rechnet aus der Uhr neu,
			// der Takt haengt sich wieder ein.
			zeitAnzeigen();
			zeitTaktSetzen();
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

	function openTab( name ) {
		document.querySelectorAll( '.lw16-mobile__tabs button' ).forEach( function ( button ) {
			button.classList.toggle( 'is-active', button.dataset.tab === name );
		} );
		document.querySelectorAll( '[data-panel]' ).forEach( function ( panel ) {
			panel.hidden = panel.dataset.panel !== name;
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		fillLagerSelects();
		bind();
		renderQueue( readQueue() );
		loadContext().then( flush );
		loadPicklists();

		zeigeScanHinweis();
		zeitAnzeigen();
		zeitTaktSetzen();

		if ( config.pickCode ) {
			openTab( 'picken' );
		}
	} );
}() );
