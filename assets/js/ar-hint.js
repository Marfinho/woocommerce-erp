/**
 * AR-Hinweis-Banner auf Produktseiten.
 *
 * Der Server gibt das Markup versteckt aus; alles Weitere entscheidet dieses
 * Skript im Browser, damit Seiten-Caches nichts verfälschen:
 *
 *  1. Kann dieses Gerät AR? (iOS: AR Quick Look in Safari, Android: ARCore)
 *  2. Ist der Banner gesperrt? (localStorage, Zeitstempel nach „Nein“/X)
 *  3. Wurde er in dieser Sitzung schon mit „Ja“ beantwortet? (sessionStorage)
 *  4. Erscheinen nach der Verzögerung oder beim ersten Scrollen.
 *
 * Gespeichert wird ausschließlich ein Zeitstempel. Jeder Zugriff auf
 * Storage steht in try/catch: Schlägt er fehl, erscheint der Banner
 * höchstens einmal pro Seitenaufruf – ohne Fehlermeldung.
 *
 * Kein jQuery, keine Cookies, kein Tracking.
 */
( function () {
	'use strict';

	var KEY_DISMISSED = 'l16_ar_hint_dismissed';
	var KEY_SESSION = 'l16_ar_hint_session';
	var DAY_MS = 86400000;

	var root = document.querySelector( '[data-lw16-ar-hint]' );
	if ( ! root ) {
		return;
	}

	var url = root.getAttribute( 'data-ar-url' ) || '';
	var productId = parseInt( root.getAttribute( 'data-product' ), 10 ) || 0;
	var delay = Math.max( 0, parseInt( root.getAttribute( 'data-delay' ), 10 ) );
	var days = Math.max( 1, parseInt( root.getAttribute( 'data-days' ), 10 ) || 7 );
	var restBase = root.getAttribute( 'data-rest' ) || '';

	if ( isNaN( delay ) ) {
		delay = 3;
	}
	if ( ! url ) {
		return;
	}

	/* ---- Storage, immer abgesichert ---------------------------------- */

	function read( store, key ) {
		try {
			return window[ store ].getItem( key );
		} catch ( e ) {
			return null;
		}
	}

	function write( store, key, value ) {
		try {
			window[ store ].setItem( key, value );
			return true;
		} catch ( e ) {
			return false;
		}
	}

	function remove( store, key ) {
		try {
			window[ store ].removeItem( key );
		} catch ( e ) {
			/* nichts zu tun */
		}
	}

	/** Gesperrt, solange der Zeitstempel jünger ist als die Sperrdauer. */
	function isLocked() {
		var raw = read( 'localStorage', KEY_DISMISSED );
		if ( null === raw ) {
			return false;
		}
		var stamp = parseInt( raw, 10 );
		var age = Date.now() - stamp;
		if ( isNaN( stamp ) || age < 0 ) {
			// Unlesbar oder aus der Zukunft: kein Grund zu sperren, aufräumen.
			remove( 'localStorage', KEY_DISMISSED );
			return false;
		}
		return age < days * DAY_MS;
	}

	function answeredThisSession() {
		return '1' === read( 'sessionStorage', KEY_SESSION );
	}

	/* ---- Gerätefähigkeit --------------------------------------------- */

	/**
	 * Liefert ein Promise<boolean>: Kann dieses Gerät AR?
	 * Desktop (auch macOS-Safari ohne Touch) ergibt immer false.
	 */
	function canDoAr() {
		var ua = navigator.userAgent || '';
		var touch = ( navigator.maxTouchPoints || 0 ) > 1;

		// iPhone/iPad: Safari mit AR Quick Look. iPadOS meldet sich als Mac,
		// unterscheidbar nur am Touch-Bildschirm.
		var isIos = /iPhone|iPad|iPod/.test( ua ) || ( 'MacIntel' === navigator.platform && touch );
		if ( isIos ) {
			try {
				var a = document.createElement( 'a' );
				return Promise.resolve( !! ( a.relList && a.relList.supports && a.relList.supports( 'ar' ) ) );
			} catch ( e ) {
				return Promise.resolve( false );
			}
		}

		// Android: Scene Viewer braucht ARCore. Das lässt sich nur über WebXR
		// zuverlässig erfragen – ohne diese Schnittstelle zeigen wir nichts,
		// statt Besucher in eine Sackgasse zu schicken.
		if ( /Android/i.test( ua ) && navigator.xr && 'function' === typeof navigator.xr.isSessionSupported ) {
			try {
				return navigator.xr.isSessionSupported( 'immersive-ar' ).then(
					function ( ok ) {
						return !! ok;
					},
					function () {
						return false;
					}
				);
			} catch ( e ) {
				return Promise.resolve( false );
			}
		}

		return Promise.resolve( false );
	}

	/* ---- Anonyme Zähler ---------------------------------------------- */

	var noncePromise = null;

	function getNonce() {
		if ( ! restBase || ! window.fetch ) {
			return Promise.resolve( '' );
		}
		if ( ! noncePromise ) {
			noncePromise = fetch( restBase + 'ar-nonce', { credentials: 'omit', cache: 'no-store' } )
				.then( function ( r ) {
					return r.ok ? r.json() : {};
				} )
				.then( function ( j ) {
					return ( j && j.nonce ) || '';
				} )
				.catch( function () {
					return '';
				} );
		}
		return noncePromise;
	}

	function count( event ) {
		if ( ! productId || ! restBase || ! window.fetch ) {
			return;
		}
		getNonce().then( function ( nonce ) {
			if ( ! nonce ) {
				return;
			}
			try {
				fetch( restBase + 'ar-event', {
					method: 'POST',
					credentials: 'omit',
					keepalive: true,
					headers: { 'Content-Type': 'application/json', 'X-LW16-Nonce': nonce },
					body: JSON.stringify( { product: productId, event: event } )
				} ).catch( function () {} );
			} catch ( e ) {
				/* Zählen darf nie etwas kaputt machen. */
			}
		} );
	}

	/* ---- Anzeige ------------------------------------------------------ */

	var shown = false;
	var closed = false;
	var timer = null;

	function show() {
		if ( shown || closed ) {
			return;
		}
		shown = true;
		window.clearTimeout( timer );
		window.removeEventListener( 'scroll', onScroll );

		root.hidden = false;
		// Ein Frame Pause, damit die Übergangsanimation vom Ausgangszustand startet.
		window.requestAnimationFrame( function () {
			window.requestAnimationFrame( function () {
				root.classList.add( 'is-visible' );
			} );
		} );
		count( 'shown' );
	}

	function hide() {
		closed = true;
		root.classList.remove( 'is-visible' );
		window.setTimeout( function () {
			root.hidden = true;
		}, 300 );
		document.removeEventListener( 'keydown', onKey );
	}

	/** „Nein“, X und Escape: sperren und zählen. */
	function dismiss() {
		if ( closed ) {
			return;
		}
		write( 'localStorage', KEY_DISMISSED, String( Date.now() ) );
		count( 'no' );
		hide();
	}

	function onScroll() {
		show();
	}

	function onKey( ev ) {
		if ( 'Escape' === ev.key ) {
			dismiss();
		}
	}

	var yes = root.querySelector( '[data-lw16-ar-yes]' );
	var no = root.querySelector( '[data-lw16-ar-no]' );
	var close = root.querySelector( '[data-lw16-ar-close]' );

	if ( yes ) {
		yes.addEventListener( 'click', function () {
			// Der Link führt von selbst auf die AR-Seite; wir merken nur noch
			// vor, dass in dieser Sitzung nicht noch einmal gefragt wird.
			write( 'sessionStorage', KEY_SESSION, '1' );
			count( 'yes' );
			closed = true;
		} );
	}
	if ( no ) {
		no.addEventListener( 'click', dismiss );
	}
	if ( close ) {
		close.addEventListener( 'click', dismiss );
	}

	/* ---- Start -------------------------------------------------------- */

	if ( isLocked() || answeredThisSession() ) {
		return;
	}

	canDoAr().then( function ( ok ) {
		if ( ! ok || shown || closed ) {
			return;
		}
		document.addEventListener( 'keydown', onKey );
		window.addEventListener( 'scroll', onScroll, { passive: true, once: true } );
		timer = window.setTimeout( show, delay * 1000 );
	} );
}() );
