/**
 * AR-Ansicht aus dem Konfigurator: „An meiner Wand ansehen“.
 *
 * Der Konfigurator schreibt die aktuelle Konfiguration (Stange, Halter, Module
 * in ihrer Farbe und an ihren Positionen) als .glb und gibt sie an Googles
 * `<model-viewer>`. Der stellt den Haken in Originalgröße an die Wand des
 * Besuchers: auf Android über WebXR, auf dem iPhone über AR Quick Look (die
 * USDZ-Datei erzeugt `<model-viewer>` selbst aus der Szene).
 *
 * Wo der Browser kein WebXR kann (Firefox, Samsung Internet, ältere Geräte),
 * springt Android auf Googles Scene Viewer. Der lädt die Datei selbst von
 * einer Internetadresse; deshalb wird die Konfiguration dann – und nur dann –
 * kurz auf dem Server abgelegt (ArModelStore, sieben Tage). Für alles andere
 * bleibt sie im Browser.
 *
 * Am Rechner geht AR nicht. Dort zeigt die Ansicht einen QR-Code, der die
 * Seite mit derselben Konfiguration am Telefon öffnet.
 *
 * Geladen wird diese Datei erst beim Antippen des Knopfs; model-viewer und der
 * Exporter erst danach.
 */


/**
 * Die aktuelle Konfiguration als .glb-Datei.
 *
 * Ohne Wand, Möbel und Licht – nur der Haken, mittig auf der Höhe, die
 * Rückseite in der Ebene, die an die Wand kommt.
 *
 * @param {object} ansicht Die räumliche Ansicht.
 * @param {object} THREE   Die Bibliothek.
 * @param {string} url     Adresse des Exporters.
 * @returns {Promise<Blob>}
 */
async function alsGlb( ansicht, THREE, url ) {
	const { GLTFExporter } = await import( url );
	const ausgewaehlt = ansicht.k.ausgewaehlt;

	// Die Hervorhebung des gewählten Moduls ist ein Leuchten im Material.
	// Sie gehört nicht in die Datei – und die Materialien werden beim Klonen
	// geteilt, also muss sie während des Exports aus sein.
	ansicht.hebeHervor( null );

	try {
		const kopie = ansicht.gruppe.clone( true );
		const kasten = new THREE.Box3().setFromObject( kopie );

		kopie.position.set( -( kasten.min.x + kasten.max.x ) / 2, -( kasten.min.y + kasten.max.y ) / 2, 0 );

		const huelle = new THREE.Group();

		huelle.name = 'Haster Haken';
		huelle.add( kopie );

		const daten = await new GLTFExporter().parseAsync( huelle, { binary: true, onlyVisible: true } );

		return new Blob( [ daten ], { type: 'model/gltf-binary' } );
	} finally {
		ansicht.hebeHervor( ausgewaehlt );
	}
}

/**
 * Ist das ein Telefon oder Tablet?
 *
 * @returns {boolean}
 */
function istMobil() {
	const ua = window.navigator.userAgent || '';

	// iPadOS meldet sich als Mac, hat aber einen Touchscreen.
	return /Android|iPhone|iPad|iPod/i.test( ua ) || ( /Macintosh/i.test( ua ) && window.navigator.maxTouchPoints > 1 );
}

/**
 * Läuft die Seite in einem App-Browser (Instagram, Facebook …)?
 *
 * Dort fehlt AR meist, auch wenn das Gerät es kann.
 *
 * @returns {boolean}
 */
function istAppBrowser() {
	return /FBAN|FBAV|Instagram|Line\/|MicroMessenger|Snapchat|TikTok|Pinterest|; wv\)/i.test( window.navigator.userAgent || '' );
}

/**
 * Die Datei für den Scene Viewer auf dem Server ablegen.
 *
 * @param {Blob}   blob Die .glb.
 * @param {object} ar   Einstellungen (Adressen der Schnittstelle).
 * @returns {Promise<string>} Adresse der abgelegten Datei, leer bei einem Fehler.
 */
async function legeAb( blob, ar ) {
	const abbruch = new window.AbortController();
	const frist = window.setTimeout( () => abbruch.abort(), 15000 );

	try {
		const nonce = await window.fetch( ar.nonce, { credentials: 'omit', cache: 'no-store', signal: abbruch.signal } ).then( ( r ) => ( r.ok ? r.json() : {} ) );
		const antwort = await window.fetch( ar.upload, {
			method: 'POST',
			credentials: 'omit',
			signal: abbruch.signal,
			headers: { 'Content-Type': 'model/gltf-binary', 'X-LW16-Nonce': ( nonce && nonce.nonce ) || '' },
			body: blob,
		} );
		const json = antwort.ok ? await antwort.json() : null;

		return json && json.url ? json.url : '';
	} catch ( fehler ) {
		return '';
	} finally {
		window.clearTimeout( frist );
	}
}

/**
 * Link, der auf Android den Scene Viewer von Google öffnet.
 *
 * @param {string} datei Adresse der .glb.
 * @param {string} titel Name, den der Scene Viewer zeigt.
 * @returns {string}
 */
function szenenLink( datei, titel ) {
	const parameter = 'file=' + encodeURIComponent( datei ) + '&mode=ar_preferred&enable_vertical_placement=true&resizable=false&title=' + encodeURIComponent( titel );
	const ausweich = 'https://arvr.google.com/scene-viewer?' + parameter;

	return 'intent://arvr.google.com/scene-viewer/1.0?' + parameter + '#Intent;scheme=https;package=com.google.android.googlequicksearchbox;action=android.intent.action.VIEW;S.browser_fallback_url=' + encodeURIComponent( ausweich ) + ';end;';
}

/**
 * Element mit Eigenschaften und Kindern.
 *
 * @param {string} name       Tag.
 * @param {object} eigenschaften Attribute.
 * @param {Array}  kinder     Kinder.
 * @returns {Element}
 */
function el( name, eigenschaften, kinder ) {
	const knoten = document.createElement( name );

	Object.keys( eigenschaften || {} ).forEach( ( schluessel ) => {
		if ( 'text' === schluessel ) {
			knoten.textContent = eigenschaften[ schluessel ];
		} else {
			knoten.setAttribute( schluessel, eigenschaften[ schluessel ] );
		}
	} );

	( kinder || [] ).forEach( ( kind ) => knoten.appendChild( kind ) );

	return knoten;
}

/**
 * Die AR-Ansicht öffnen.
 *
 * @param {object} konfigurator Der Konfigurator der Seite.
 * @param {object} ansicht      Die räumliche Ansicht.
 * @param {object} THREE        Die Bibliothek.
 * @returns {Promise<void>}
 */
export async function oeffne( konfigurator, ansicht, THREE ) {
	const texte = konfigurator.texte;
	const ar = konfigurator.ar;
	const davor = document.activeElement;
	let blobUrl = '';
	let glb = null;
	const blobHolen = () => glb;

	const status = el( 'p', { class: 'lw16-ar__status', role: 'status', text: texte.ar_laedt } );
	const startKnopf = el( 'button', { type: 'button', slot: 'ar-button', class: 'lw16-ar__start', text: texte.ar_start } );
	const szenenKnopf = el( 'a', { class: 'lw16-ar__start lw16-ar__start--link', href: '#', hidden: '', text: texte.ar_start } );
	const qr = el( 'div', { class: 'lw16-ar__qr' } );
	const desktop = el( 'div', { class: 'lw16-ar__desktop', hidden: '' }, [ el( 'p', { text: texte.ar_desktop } ), qr ] );
	const zu = el( 'button', { type: 'button', class: 'lw16-ar__zu', 'aria-label': texte.ar_schliessen, text: '×' } );
	const titel = el( 'h2', { class: 'lw16-ar__titel', id: 'lw16-ar-titel', text: texte.ar_titel } );
	const buehne = el( 'div', { class: 'lw16-ar__buehne' } );
	const ueberlagerung = el( 'div', { class: 'lw16-ar', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'lw16-ar-titel' }, [
		el( 'div', { class: 'lw16-ar__kopf' }, [ titel, zu ] ),
		buehne,
		szenenKnopf,
		status,
		desktop,
		el( 'p', { class: 'lw16-ar__anleitung', text: texte.ar_anleitung + ' ' + texte.ar_drehen } ),
	] );

	const schliesse = () => {
		document.removeEventListener( 'keydown', taste );
		document.documentElement.classList.remove( 'lw16-ar-offen' );
		ueberlagerung.remove();

		if ( blobUrl ) {
			URL.revokeObjectURL( blobUrl );
		}

		if ( davor && davor.focus ) {
			davor.focus();
		}
	};
	const taste = ( ereignis ) => {
		if ( 'Escape' === ereignis.key ) {
			schliesse();
		}
	};

	zu.addEventListener( 'click', schliesse );
	document.addEventListener( 'keydown', taste );
	document.documentElement.classList.add( 'lw16-ar-offen' );
	document.body.appendChild( ueberlagerung );
	zu.focus();

	try {
		const [ , blob ] = await Promise.all( [ import( ar.viewer ), alsGlb( ansicht, THREE, ar.exporter ) ] );

		glb = blob;
		blobUrl = URL.createObjectURL( blob );

		const viewer = document.createElement( 'model-viewer' );

		viewer.setAttribute( 'src', blobUrl );
		viewer.setAttribute( 'alt', texte.ar_titel );
		viewer.setAttribute( 'ar', '' );
		viewer.setAttribute( 'ar-modes', 'webxr quick-look' );
		viewer.setAttribute( 'ar-placement', 'wall' );
		viewer.setAttribute( 'ar-scale', 'fixed' );
		viewer.setAttribute( 'camera-controls', '' );
		viewer.setAttribute( 'touch-action', 'pan-y' );
		viewer.setAttribute( 'shadow-intensity', '1' );
		viewer.setAttribute( 'exposure', '1.05' );
		viewer.appendChild( startKnopf );
		buehne.appendChild( viewer );

		viewer.addEventListener( 'load', () => {
			status.hidden = true;

			if ( viewer.canActivateAR ) {
				return;
			}

			startKnopf.hidden = true;

			if ( ! istMobil() ) {
				desktop.hidden = false;
				zeigeQr( konfigurator, ar, qr, texte );
				return;
			}

			// Ein Telefon, aber kein WebXR und kein Quick Look. Auf Android
			// bleibt der Scene Viewer; dafür muss die Datei im Netz liegen.
			if ( /Android/i.test( window.navigator.userAgent ) && ! istAppBrowser() ) {
				status.hidden = false;
				status.textContent = texte.ar_laedt;

				legeAb( blobHolen(), ar ).then( ( datei ) => {
					if ( datei ) {
						status.hidden = true;
						szenenKnopf.href = szenenLink( datei, texte.ar_titel );
						szenenKnopf.hidden = false;
					} else {
						status.textContent = texte.ar_kein_ar;
					}
				} );

				return;
			}

			status.hidden = false;
			status.textContent = istAppBrowser() ? texte.ar_app : texte.ar_kein_ar;
		} );

		viewer.addEventListener( 'error', () => {
			status.hidden = false;
			status.textContent = texte.ar_fehler;
		} );
	} catch ( fehler ) {
		if ( window.console ) {
			window.console.error( 'Konfigurator: AR-Ansicht konnte nicht vorbereitet werden', fehler );
		}

		status.textContent = texte.ar_fehler;
	}
}

/**
 * QR-Code zur Konfiguration holen und anzeigen.
 *
 * @param {object} konfigurator Konfigurator.
 * @param {object} ar           Einstellungen der AR-Ansicht.
 * @param {Element} ziel        Behälter.
 * @param {object} texte        Texte.
 */
function zeigeQr( konfigurator, ar, ziel, texte ) {
	const adresse = new URL( ar.qr, window.location.href );

	adresse.searchParams.set( 'code', konfigurator.code() );
	adresse.searchParams.set( 'seite', ar.seite );

	window.fetch( adresse.toString(), { credentials: 'omit' } )
		.then( ( antwort ) => ( antwort.ok ? antwort.json() : null ) )
		.then( ( json ) => {
			if ( json && json.svg ) {
				// Das SVG baut der eigene Server aus der Adresse der eigenen Seite.
				ziel.innerHTML = json.svg; // phpcs:ignore -- eigene Antwort.
				ziel.setAttribute( 'role', 'img' );
				ziel.setAttribute( 'aria-label', texte.ar_qr_alt );
			}
		} )
		.catch( () => {} );
}

