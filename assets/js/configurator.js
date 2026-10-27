/**
 * Konfigurator für den Haster Haken.
 *
 * Aufbau in drei Schichten, die sich in dieser Reihenfolge abschalten
 * lassen, ohne dass das Geschäft ausfällt:
 *
 *   1. Das Formular. Es steht fertig im HTML, kennt jedes Feld, und sein
 *      Absenden legt den Haken in den Warenkorb – auch wenn diese Datei
 *      nie geladen wird.
 *   2. Die Bedienung. Sie ersetzt die leeren Zeilen des Formulars durch
 *      eine Liste zum Hinzufügen und Entfernen und rechnet den Preis mit.
 *   3. Die Ansicht. Sie zeigt denselben Zustand räumlich und lässt die
 *      Module mit der Maus verschieben. Fällt WebGL aus, fehlt genau das
 *      und sonst nichts.
 *
 * Die Regeln des Aufbaus stehen hier ein zweites Mal – ein Modul soll sich
 * beim Ziehen sofort sperrig anfühlen und nicht erst nach dem Absenden.
 * Verbindlich ist trotzdem allein die Prüfung auf dem Server (Layout.php);
 * was hier steht, ist Bequemlichkeit, keine Sicherung.
 *
 * Alle Längen sind Millimeter. Die Szene rechnet in Metern, deshalb der
 * Faktor 1000 an den Übergängen.
 */

const DATEN = window.LW16_HAKEN || null;
const MM = 1000;
const EPSILON = 0.01;

/* ------------------------------------------------------------------ */
/* Regeln                                                              */
/* ------------------------------------------------------------------ */

/**
 * Die Bereiche, in denen die Unterkante eines Moduls nicht liegen darf.
 *
 * Aus jedem Hindernis (anderes Modul, Sperrbereich) wird ein Bereich für
 * die Unterkante: Ein Modul der Höhe h kollidiert mit [von, bis), sobald
 * seine Unterkante zwischen von-h und bis liegt.
 *
 * @param {number} hoehe      Bauhöhe des Moduls.
 * @param {Array}  hindernisse Liste aus {von, bis}.
 * @returns {Array} Zusammengefasste, aufsteigend sortierte Bereiche.
 */
function verboteneBereiche( hoehe, hindernisse ) {
	const roh = hindernisse
		.map( ( h ) => ( { von: h.von - hoehe, bis: h.bis } ) )
		.filter( ( h ) => h.bis > h.von )
		.sort( ( a, b ) => a.von - b.von );

	const zusammengefasst = [];

	roh.forEach( ( bereich ) => {
		const letzter = zusammengefasst[ zusammengefasst.length - 1 ];

		if ( letzter && bereich.von <= letzter.bis + EPSILON ) {
			letzter.bis = Math.max( letzter.bis, bereich.bis );
			return;
		}

		zusammengefasst.push( { von: bereich.von, bis: bereich.bis } );
	} );

	return zusammengefasst;
}

/**
 * Ist eine Position frei?
 *
 * @param {number} position Unterkante.
 * @param {Array}  bereiche Verbotene Bereiche.
 * @returns {boolean}
 */
function istFrei( position, bereiche ) {
	return ! bereiche.some( ( b ) => position > b.von - EPSILON && position < b.bis - EPSILON );
}

/**
 * Die nächstgelegene erlaubte Position zu einem Wunsch.
 *
 * Beim Ziehen soll ein Modul an seinem Nachbarn anschlagen statt durch
 * ihn hindurchzufahren – deshalb wird nicht abgelehnt, sondern auf die
 * nächste freie Kante gesetzt.
 *
 * @param {number} wunsch   Gewünschte Unterkante.
 * @param {number} hoehe    Bauhöhe.
 * @param {number} schiene  Länge des Basismoduls.
 * @param {Array}  hindernisse Liste aus {von, bis}.
 * @returns {number|null} Position oder null, wenn nichts frei ist.
 */
function naechsteFreie( wunsch, hoehe, schiene, hindernisse ) {
	const grenze = schiene - hoehe;

	if ( grenze < 0 ) {
		return null;
	}

	const bereiche = verboteneBereiche( hoehe, hindernisse );
	let position = Math.min( Math.max( wunsch, 0 ), grenze );

	if ( istFrei( position, bereiche ) ) {
		return runde( position );
	}

	// Kandidaten sind die Kanten der verbotenen Bereiche: Dort beginnt und
	// endet jede Sperre, dazwischen ändert sich nichts.
	const kandidaten = [ 0, grenze ];

	bereiche.forEach( ( b ) => {
		kandidaten.push( b.von, b.bis );
	} );

	const erlaubte = kandidaten
		.map( ( k ) => Math.min( Math.max( k, 0 ), grenze ) )
		.filter( ( k ) => istFrei( k, bereiche ) );

	if ( ! erlaubte.length ) {
		return null;
	}

	erlaubte.sort( ( a, b ) => Math.abs( a - position ) - Math.abs( b - position ) );

	return runde( erlaubte[ 0 ] );
}

/**
 * Die erste freie Position von unten.
 *
 * @param {number} hoehe       Bauhöhe.
 * @param {number} schiene     Länge des Basismoduls.
 * @param {Array}  hindernisse Liste aus {von, bis}.
 * @returns {number|null}
 */
function ersteFreie( hoehe, schiene, hindernisse ) {
	const grenze = schiene - hoehe;

	if ( grenze < 0 ) {
		return null;
	}

	const bereiche = verboteneBereiche( hoehe, hindernisse );
	const kandidaten = [ 0 ].concat( bereiche.map( ( b ) => b.bis ) ).sort( ( a, b ) => a - b );

	for ( const kandidat of kandidaten ) {
		if ( kandidat >= -EPSILON && kandidat <= grenze + EPSILON && istFrei( kandidat, bereiche ) ) {
			return runde( Math.max( 0, kandidat ) );
		}
	}

	return null;
}

/**
 * Auf Hundertstelmillimeter runden.
 *
 * @param {number} wert Wert.
 * @returns {number}
 */
function runde( wert ) {
	return Math.round( wert * 100 ) / 100;
}

/**
 * Millimeter ohne überflüssige Nullen.
 *
 * @param {number} wert Wert.
 * @returns {string}
 */
function mm( wert ) {
	return String( Math.round( wert * 10 ) / 10 ).replace( '.', ',' );
}

/* ------------------------------------------------------------------ */
/* Zustand                                                             */
/* ------------------------------------------------------------------ */

/**
 * Der Konfigurator einer Seite.
 */
class Konfigurator {
	/**
	 * @param {HTMLElement} wurzel Container mit data-lw16-haken.
	 */
	constructor( wurzel ) {
		this.wurzel = wurzel;
		this.katalog = DATEN.katalog;
		this.texte = DATEN.texte;
		this.grenzen = DATEN.grenzen;

		this.basen = new Map( this.katalog.bases.map( ( b ) => [ b.article, b ] ) );
		this.module = new Map( this.katalog.modules.map( ( m ) => [ m.article, m ] ) );

		this.form = wurzel.querySelector( '[data-lw16-form]' );
		// Länge und Farbe sind Optionsfelder, wenn es eine Wahl gibt, sonst
		// ein verstecktes Feld. Beides wird über wertVon() gelesen.
		this.felderBasis = wurzel.querySelectorAll( '[data-lw16-basis]' );
		this.felderFarbe = wurzel.querySelectorAll( '[data-lw16-farbe]' );
		this.chip = wurzel.querySelector( '[data-lw16-chip]' );
		this.feldMenge = wurzel.querySelector( '[data-lw16-menge]' );
		this.feldCode = wurzel.querySelector( '[data-lw16-code]' );
		this.liste = wurzel.querySelector( '[data-lw16-modulliste]' );
		this.angebot = wurzel.querySelector( '[data-lw16-angebot]' );
		this.preis = wurzel.querySelector( '[data-lw16-preis]' );
		this.lieferHinweis = wurzel.querySelector( '[data-lw16-liefer]' );
		this.knopfKaufen = wurzel.querySelector( '.lw16-haken__kaufen' );
		this.meldung = wurzel.querySelector( '[data-lw16-meldung]' );
		this.buehne = wurzel.querySelector( '[data-lw16-ansicht]' );
		this.hinweis = wurzel.querySelector( '[data-lw16-ansicht-hinweis]' );
		this.knopfZuruecksetzen = wurzel.querySelector( '[data-lw16-ansicht-zuruecksetzen]' );
		this.knopfTeilen = wurzel.querySelector( '[data-lw16-teilen]' );

		this.zustand = this.zustandAusFormular();
		this.ausgewaehlt = null;
		this.ansicht = null;

		this.verdrahten();
		this.zeichneListe();
		this.aktualisiere();
	}

	/**
	 * Startzustand aus den vorbelegten Feldern lesen.
	 *
	 * @returns {object}
	 */
	zustandAusFormular() {
		const module = [];

		this.liste.querySelectorAll( '[data-lw16-zeile]' ).forEach( ( zeile ) => {
			const artikel = parseInt( zeile.querySelector( '[data-lw16-modul]' ).value, 10 );
			const position = parseFloat( String( zeile.querySelector( '[data-lw16-position]' ).value ).replace( ',', '.' ) );

			if ( artikel > 0 && this.module.has( artikel ) ) {
				module.push( { artikel, position: runde( position || 0 ), uid: this.neueUid() } );
			}
		} );

		return {
			basis: parseInt( this.wertVon( this.felderBasis ), 10 ),
			farbe: this.wertVon( this.felderFarbe ),
			menge: parseInt( this.feldMenge.value, 10 ) || 1,
			module,
		};
	}

	/**
	 * Der Wert eines Feldes oder einer Gruppe von Optionsfeldern.
	 *
	 * @param {NodeList} felder Felder mit gleichem Namen.
	 * @returns {string}
	 */
	wertVon( felder ) {
		const gewaehlt = [ ...felder ].find( ( f ) => 'radio' !== f.type || f.checked );

		return gewaehlt ? gewaehlt.value : '';
	}

	/**
	 * Kennung für eine Modulinstanz – zwei gleiche Module müssen sich
	 * beim Verschieben auseinanderhalten lassen.
	 *
	 * @returns {string}
	 */
	neueUid() {
		return Math.random().toString( 36 ).slice( 2, 10 );
	}

	/**
	 * Das aktuelle Basismodul.
	 *
	 * @returns {object}
	 */
	basis() {
		return this.basen.get( this.zustand.basis ) || this.katalog.bases[ 0 ];
	}

	/**
	 * Hindernisse für ein Modul: alle anderen Module und die Sperrbereiche.
	 *
	 * @param {string|null} ausser Uid, die nicht zählt (das gezogene Modul).
	 * @returns {Array}
	 */
	hindernisse( ausser = null ) {
		const liste = this.basis().blocks.map( ( b ) => ( { von: b.from, bis: b.to } ) );

		this.zustand.module.forEach( ( eintrag ) => {
			if ( eintrag.uid === ausser ) {
				return;
			}

			const teil = this.module.get( eintrag.artikel );

			if ( teil ) {
				liste.push( { von: eintrag.position, bis: eintrag.position + teil.height } );
			}
		} );

		return liste;
	}

	/**
	 * Ereignisse anmelden.
	 */
	verdrahten() {
		this.felderBasis.forEach( ( feld ) => feld.addEventListener( 'change', () => {
			this.zustand.basis = parseInt( this.wertVon( this.felderBasis ), 10 );
			this.raeumeAuf();
			this.aktualisiere();

			if ( this.ansicht ) {
				this.ansicht.neuAufbauen();
			}
		} ) );

		this.felderFarbe.forEach( ( feld ) => feld.addEventListener( 'change', () => {
			this.zustand.farbe = this.wertVon( this.felderFarbe );
			this.zeichneListe();
			this.aktualisiere();

			if ( this.ansicht ) {
				this.ansicht.faerbe();
			}
		} ) );

		this.feldMenge.addEventListener( 'input', () => {
			const menge = parseInt( this.feldMenge.value, 10 );
			this.zustand.menge = Math.min( Math.max( menge || 1, 1 ), this.grenzen.max_menge );
			this.aktualisiere();
		} );

		this.angebot.hidden = false;
		this.angebot.querySelectorAll( '[data-lw16-hinzufuegen]' ).forEach( ( knopf ) => {
			knopf.addEventListener( 'click', () => {
				this.hinzufuegen( parseInt( knopf.dataset.lw16Hinzufuegen, 10 ) );
			} );
		} );

		if ( this.knopfTeilen && navigator.clipboard ) {
			this.knopfTeilen.hidden = false;
			this.knopfTeilen.addEventListener( 'click', () => this.teile() );
		}
	}

	/**
	 * Ein Modul aufnehmen.
	 *
	 * @param {number} artikel Artikel-ID.
	 */
	hinzufuegen( artikel ) {
		const teil = this.module.get( artikel );

		if ( ! teil ) {
			return;
		}

		if ( this.nichtLieferbar( teil ) ) {
			this.sageAn( this.texte.modul_nicht_lieferbar );
			return;
		}

		const basis = this.basis();

		if ( basis.max_modules > 0 && this.zustand.module.length >= basis.max_modules ) {
			this.sageAn( this.texte.kein_platz );
			return;
		}

		const position = ersteFreie( teil.height, basis.height, this.hindernisse() );

		if ( null === position ) {
			this.sageAn( this.texte.kein_platz );
			return;
		}

		const uid = this.neueUid();
		this.zustand.module.push( { artikel, position, uid } );
		this.ausgewaehlt = uid;

		this.zeichneListe();
		this.aktualisiere();

		if ( this.ansicht ) {
			this.ansicht.neuAufbauen();
		}
	}

	/**
	 * Ein Modul entfernen.
	 *
	 * @param {string} uid Kennung.
	 */
	entferne( uid ) {
		this.zustand.module = this.zustand.module.filter( ( m ) => m.uid !== uid );

		if ( this.ausgewaehlt === uid ) {
			this.ausgewaehlt = null;
		}

		this.zeichneListe();
		this.aktualisiere();

		if ( this.ansicht ) {
			this.ansicht.neuAufbauen();
		}
	}

	/**
	 * Ein Modul auf eine Position setzen, so nah am Wunsch wie erlaubt.
	 *
	 * @param {string}  uid    Kennung.
	 * @param {number}  wunsch Gewünschte Unterkante in Millimetern.
	 * @param {boolean} sagen  Rückmeldung geben, wenn korrigiert wurde.
	 * @returns {number|null} Übernommene Position.
	 */
	verschiebe( uid, wunsch, sagen = false ) {
		const eintrag = this.zustand.module.find( ( m ) => m.uid === uid );

		if ( ! eintrag ) {
			return null;
		}

		const teil = this.module.get( eintrag.artikel );
		const position = naechsteFreie( wunsch, teil.height, this.basis().height, this.hindernisse( uid ) );

		if ( null === position ) {
			return null;
		}

		if ( sagen && Math.abs( position - wunsch ) > 0.5 ) {
			this.sageAn( this.texte.belegt );
		}

		eintrag.position = position;
		this.aktualisiereZeilen();
		this.aktualisiere();

		return position;
	}

	/**
	 * Module entfernen, die am neuen Basismodul keinen Platz mehr haben.
	 */
	raeumeAuf() {
		const basis = this.basis();
		const behalten = [];

		this.zustand.module.forEach( ( eintrag ) => {
			const teil = this.module.get( eintrag.artikel );

			if ( ! teil ) {
				return;
			}

			const hindernisse = basis.blocks
				.map( ( b ) => ( { von: b.from, bis: b.to } ) )
				.concat(
					behalten.map( ( b ) => {
						const anderes = this.module.get( b.artikel );
						return { von: b.position, bis: b.position + anderes.height };
					} )
				);

			const position = naechsteFreie( eintrag.position, teil.height, basis.height, hindernisse );

			if ( null !== position ) {
				behalten.push( { ...eintrag, position } );
			}
		} );

		this.zustand.module = behalten;
		this.zeichneListe();
	}

	/**
	 * Die Modulliste neu aufbauen.
	 *
	 * Es bleiben dieselben Feldnamen wie im Ersatzformular – der Server
	 * bekommt von der Bedienoberfläche nichts mit.
	 */
	zeichneListe() {
		this.liste.innerHTML = '';

		if ( ! this.zustand.module.length ) {
			const leer = document.createElement( 'p' );
			leer.className = 'lw16-haken__leer';
			leer.textContent = this.texte.leer;
			this.liste.appendChild( leer );
			return;
		}

		const sortiert = [ ...this.zustand.module ].sort( ( a, b ) => a.position - b.position );

		sortiert.forEach( ( eintrag ) => {
			const teil = this.module.get( eintrag.artikel );
			const zeile = document.createElement( 'div' );
			zeile.className = 'lw16-haken__zeile';
			zeile.dataset.lw16Uid = eintrag.uid;

			if ( this.ausgewaehlt === eintrag.uid ) {
				zeile.classList.add( 'is-aktiv' );
			}

			if ( this.nichtLieferbar( teil ) ) {
				zeile.classList.add( 'is-ausverkauft' );
			}

			const auswahl = document.createElement( 'select' );
			auswahl.name = 'modul[]';
			auswahl.setAttribute( 'aria-label', teil.name );
			this.katalog.modules.forEach( ( m ) => {
				const option = document.createElement( 'option' );
				option.value = String( m.article );
				option.textContent = m.name;
				option.selected = m.article === eintrag.artikel;
				auswahl.appendChild( option );
			} );
			auswahl.addEventListener( 'change', () => {
				eintrag.artikel = parseInt( auswahl.value, 10 );
				this.verschiebe( eintrag.uid, eintrag.position );
				this.zeichneListe();
				this.aktualisiere();

				if ( this.ansicht ) {
					this.ansicht.neuAufbauen();
				}
			} );

			const feld = document.createElement( 'input' );
			feld.type = 'number';
			feld.name = 'position[]';
			feld.min = '0';
			feld.step = '1';
			feld.inputMode = 'numeric';
			feld.value = String( Math.round( eintrag.position ) );
			feld.setAttribute( 'aria-label', teil.name + ' – Position in Millimetern' );
			feld.addEventListener( 'change', () => {
				const wunsch = parseFloat( String( feld.value ).replace( ',', '.' ) ) || 0;
				const position = this.verschiebe( eintrag.uid, wunsch, true );
				feld.value = String( Math.round( null === position ? eintrag.position : position ) );

				if ( this.ansicht ) {
					this.ansicht.setzePositionen();
				}
			} );

			const einheit = document.createElement( 'span' );
			einheit.className = 'lw16-haken__einheit';
			einheit.textContent = 'mm';

			const weg = document.createElement( 'button' );
			weg.type = 'button';
			weg.className = 'lw16-haken__weg';
			weg.textContent = this.texte.entfernen;
			weg.setAttribute( 'aria-label', this.texte.entfernen + ': ' + teil.name );
			weg.addEventListener( 'click', () => this.entferne( eintrag.uid ) );

			const symbol = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
			symbol.setAttribute( 'viewBox', '0 0 32 32' );
			symbol.setAttribute( 'class', 'lw16-haken__zeilensymbol' );
			symbol.setAttribute( 'aria-hidden', 'true' );
			const verweis = document.createElementNS( 'http://www.w3.org/2000/svg', 'use' );
			verweis.setAttribute( 'href', '#lw16-sym-' + ( ( DATEN.symbole && DATEN.symbole[ eintrag.artikel ] ) || 'modul' ) );
			symbol.appendChild( verweis );

			zeile.append( symbol, auswahl, feld, einheit, weg );

			if ( this.nichtLieferbar( teil ) ) {
				const marke = document.createElement( 'span' );
				marke.className = 'lw16-haken__status';
				marke.textContent = this.texte.nicht_lieferbar;
				zeile.appendChild( marke );
			}
			zeile.addEventListener( 'pointerdown', () => {
				this.waehle( eintrag.uid );
			} );

			this.liste.appendChild( zeile );
		} );
	}

	/**
	 * Nur die Zahlenfelder nachziehen, ohne die Liste neu zu bauen.
	 */
	aktualisiereZeilen() {
		this.zustand.module.forEach( ( eintrag ) => {
			const zeile = this.liste.querySelector( '[data-lw16-uid="' + eintrag.uid + '"]' );

			if ( zeile ) {
				zeile.querySelector( 'input[name="position[]"]' ).value = String( Math.round( eintrag.position ) );
			}
		} );
	}

	/**
	 * Ein Modul auswählen.
	 *
	 * @param {string|null} uid Kennung.
	 */
	waehle( uid ) {
		this.ausgewaehlt = uid;

		this.liste.querySelectorAll( '[data-lw16-uid]' ).forEach( ( zeile ) => {
			zeile.classList.toggle( 'is-aktiv', zeile.dataset.lw16Uid === uid );
		} );

		if ( this.ansicht ) {
			this.ansicht.hebeHervor( uid );
		}
	}

	/**
	 * Code, Preis und versteckte Felder nachziehen.
	 */
	aktualisiere() {
		this.feldCode.value = this.code();
		this.zeichnePreis();
		this.zeichneChip();
		this.zeichneModulpreise();
	}

	/**
	 * Preis eines Moduls in der gewählten Farbe auf den Modulkarten.
	 */
	zeichneModulpreise() {
		const format = new Intl.NumberFormat( document.documentElement.lang || 'de-DE', {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2,
		} );

		this.angebot.querySelectorAll( '[data-lw16-modulpreis]' ).forEach( ( feld ) => {
			const teil = this.module.get( parseInt( feld.dataset.lw16Modulpreis, 10 ) );
			const preis = teil ? this.preisVon( teil ) : null;
			const karte = feld.closest( '.lw16-haken__modulknopf' );

			feld.textContent = null === preis ? this.texte.nicht_lieferbar : format.format( preis ) + ' ' + this.katalog.currency;
			karte.classList.toggle( 'is-ausverkauft', null === preis );
			karte.setAttribute( 'aria-disabled', null === preis ? 'true' : 'false' );
		} );
	}

	/**
	 * Kurzüberblick über dem Bild: Länge und Zahl der Module.
	 */
	zeichneChip() {
		if ( ! this.chip ) {
			return;
		}

		const anzahl = this.zustand.module.length;

		this.chip.textContent = mm( this.basis().height ) + ' mm · ' + anzahl + ( 1 === anzahl ? ' Modul' : ' Module' );
	}

	/**
	 * Die Konfiguration als Code – dieselbe Form wie in ConfigCodec.php.
	 *
	 * @returns {string}
	 */
	code() {
		const teile = [ ...this.zustand.module ]
			.sort( ( a, b ) => a.position - b.position )
			.map( ( m ) => m.artikel + '@' + String( runde( m.position ) ).replace( ',', '.' ) );

		const kopf = 'v1.' + this.zustand.basis + '.' + this.zustand.farbe;

		return teile.length ? kopf + '.' + teile.join( ',' ) : kopf;
	}

	/**
	 * Preis eines Teils in der gewählten Farbe.
	 *
	 * @param {object} teil Katalogeintrag.
	 * @returns {number|null} Null, wenn es das Teil in dieser Farbe nicht gibt.
	 */
	preisVon( teil ) {
		if ( Object.prototype.hasOwnProperty.call( teil.prices, this.zustand.farbe ) ) {
			return teil.prices[ this.zustand.farbe ];
		}

		if ( Object.prototype.hasOwnProperty.call( teil.prices, '*' ) ) {
			return teil.prices[ '*' ];
		}

		return null;
	}

	/**
	 * Ist das Teil in der gewählten Farbe gerade nicht lieferbar?
	 *
	 * Etwas anderes als „gibt es nicht in dieser Farbe": Hier wird das Teil
	 * angeboten, ist aber ausverkauft.
	 *
	 * @param {object} teil Katalogeintrag.
	 * @returns {boolean}
	 */
	nichtLieferbar( teil ) {
		return null === this.preisVon( teil );
	}

	/**
	 * Preis anzeigen.
	 */
	zeichnePreis() {
		let summe = this.preisVon( this.basis() ) || 0;
		const fehlt = [];

		if ( this.nichtLieferbar( this.basis() ) ) {
			fehlt.push( this.basis().name );
		}

		this.zustand.module.forEach( ( eintrag ) => {
			const teil = this.module.get( eintrag.artikel );
			const preis = teil ? this.preisVon( teil ) : null;

			if ( null === preis ) {
				if ( teil && ! fehlt.includes( teil.name ) ) {
					fehlt.push( teil.name );
				}
				return;
			}

			summe += preis;
		} );

		this.zeichneLieferstand( fehlt );

		if ( fehlt.length ) {
			this.preis.textContent = '';
			return;
		}

		const gesamt = summe * this.zustand.menge;

		this.preis.textContent = new Intl.NumberFormat( document.documentElement.lang || 'de-DE', {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2,
		} ).format( gesamt ) + ' ' + this.katalog.currency;
	}

	/**
	 * Hinweis unter dem Preis und Zustand des Warenkorb-Knopfs.
	 *
	 * @param {string[]} fehlt Namen der Teile, die gerade nicht lieferbar sind.
	 */
	zeichneLieferstand( fehlt ) {
		if ( this.lieferHinweis ) {
			this.lieferHinweis.textContent = fehlt.length ? this.texte.nicht_lieferbar_liste.replace( '%s', fehlt.join( ', ' ) ) : '';
		}

		if ( this.knopfKaufen ) {
			this.knopfKaufen.disabled = fehlt.length > 0;
		}

		this.wurzel.querySelectorAll( '[data-lw16-basis-status]' ).forEach( ( feld ) => {
			const basis = this.basen.get( parseInt( feld.dataset.lw16BasisStatus, 10 ) );
			const aus = basis && this.nichtLieferbar( basis );

			feld.textContent = aus ? this.texte.nicht_lieferbar : '';
			feld.closest( '.lw16-haken__option' ).classList.toggle( 'is-ausverkauft', !! aus );
		} );
	}

	/**
	 * Kurze Rückmeldung im Statusbereich.
	 *
	 * @param {string} text Meldung.
	 */
	sageAn( text ) {
		if ( ! this.meldung ) {
			return;
		}

		this.meldung.textContent = text;

		window.clearTimeout( this.meldungTimer );
		this.meldungTimer = window.setTimeout( () => {
			this.meldung.textContent = '';
		}, 4000 );
	}

	/**
	 * Link zur aktuellen Konfiguration kopieren.
	 */
	teile() {
		const adresse = new URL( window.location.href );
		adresse.searchParams.set( 'haken', this.code() );

		navigator.clipboard.writeText( adresse.toString() ).then(
			() => this.sageAn( this.texte.kopiert ),
			() => this.sageAn( adresse.toString() )
		);
	}
}

/* ------------------------------------------------------------------ */
/* Ansicht                                                             */
/* ------------------------------------------------------------------ */

/**
 * Die räumliche Ansicht.
 *
 * Bewusst kein freies Umkreisen: Der Haken hängt an der Wand, seine
 * Rückseite ist eine Montageplatte. Erlaubt sind dreißig Grad nach jeder
 * Seite – genug, um die Tiefe zu sehen, zu wenig, um sich zu verlieren.
 */
class Ansicht {
	/**
	 * @param {Konfigurator} konfigurator Zustand.
	 * @param {object}       THREE        Die Bibliothek.
	 * @param {Function}     GLTFLoader   Modellader.
	 * @param {Function}     RoomEnvironment Umgebungslicht.
	 */
	constructor( konfigurator, THREE, GLTFLoader, RoomEnvironment ) {
		this.k = konfigurator;
		this.THREE = THREE;
		this.behaelter = konfigurator.buehne;
		this.lader = new GLTFLoader();
		this.zwischenlager = new Map();
		this.koerper = new Map();
		this.basisKoerper = null;
		this.laeuft = true;

		this.ziel = new THREE.Vector3( 0, 0.3, 0 );
		this.azimut = 0;
		this.polar = Math.PI / 2;
		this.zielAzimut = 0;
		this.zielPolar = Math.PI / 2 - 0.06;

		this.baueSzene();
		this.baueUmgebung( RoomEnvironment );
		this.verdrahte();
		this.neuAufbauen();
		this.schleife();
	}

	/**
	 * Grundgerüst der Szene.
	 */
	baueSzene() {
		const THREE = this.THREE;

		this.renderer = new THREE.WebGLRenderer( { antialias: true, alpha: true } );
		this.renderer.setPixelRatio( Math.min( window.devicePixelRatio || 1, 2 ) );
		this.renderer.outputColorSpace = THREE.SRGBColorSpace;
		this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
		this.renderer.toneMappingExposure = 1.05;
		this.renderer.shadowMap.enabled = true;
		this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;
		this.renderer.domElement.className = 'lw16-haken__leinwand';
		this.renderer.domElement.tabIndex = 0;
		this.renderer.domElement.setAttribute( 'aria-label', 'Räumliche Ansicht des Hakens' );

		this.behaelter.appendChild( this.renderer.domElement );

		this.szene = new THREE.Scene();
		this.kamera = new THREE.PerspectiveCamera( 32, 1, 0.05, 40 );

		this.gruppe = new THREE.Group();
		this.szene.add( this.gruppe );

		this.baueRaum();

		const licht = new THREE.DirectionalLight( 0xffffff, 1.7 );
		licht.position.set( -1.2, 2.2, 2.4 );
		licht.target.position.set( 0, 0.2, 0 );
		licht.castShadow = true;
		licht.shadow.mapSize.set( 2048, 2048 );
		licht.shadow.camera.left = -3;
		licht.shadow.camera.right = 3;
		licht.shadow.camera.top = 3;
		licht.shadow.camera.bottom = -3;
		licht.shadow.camera.near = 0.5;
		licht.shadow.camera.far = 9;
		licht.shadow.radius = 3;
		licht.shadow.bias = -0.0008;
		licht.shadow.normalBias = 0.002;
		this.szene.add( licht );
		this.szene.add( licht.target );
		this.licht = licht;
	}

	/**
	 * Die Wand hinter dem Haken: eine ruhige Fläche in hellem Anthrazit.
	 *
	 * Das Basismodul wird an eine Wand geschraubt und schwebt nicht im
	 * Raum. Die Wand liegt deshalb bündig an der Rückseite der Montage-
	 * platte (z = 0), die Schatten fallen auf sie. Mehr gibt es bewusst
	 * nicht zu sehen: Der Haken soll wirken, nicht die Einrichtung.
	 */
	baueRaum() {
		const THREE = this.THREE;
		const wand = new THREE.Mesh(
			new THREE.PlaneGeometry( 12, 8 ),
			new THREE.MeshLambertMaterial( { color: 0x676b71 } )
		);

		// Die Wand bekommt kein Umgebungslicht; ohne diese Füllung wären ihre
		// Schatten schwarz statt grau.
		this.szene.add( new THREE.AmbientLight( 0xffffff, 1.1 ) );

		wand.position.set( 0, 1, -0.002 );
		wand.receiveShadow = true;
		this.szene.add( wand );
		this.raum = wand;
	}

	/**
	 * Umgebungslicht aus einer gerechneten Raumszene – kein Bild, das
	 * geladen werden müsste, und trotzdem weiche Reflexe.
	 *
	 * @param {Function} RoomEnvironment Umgebung.
	 */
	baueUmgebung( RoomEnvironment ) {
		const THREE = this.THREE;
		const pmrem = new THREE.PMREMGenerator( this.renderer );

		this.szene.environment = pmrem.fromScene( new RoomEnvironment(), 0.04 ).texture;
		pmrem.dispose();
	}

	/**
	 * Zeigen und Ziehen.
	 */
	verdrahte() {
		const leinwand = this.renderer.domElement;
		const THREE = this.THREE;

		this.strahl = new THREE.Raycaster();
		this.zeiger = new THREE.Vector2();
		this.ebene = new THREE.Plane();
		this.treffer = new THREE.Vector3();

		leinwand.addEventListener( 'pointerdown', ( ereignis ) => this.zeigerRunter( ereignis ) );
		leinwand.addEventListener( 'pointermove', ( ereignis ) => this.zeigerBewegt( ereignis ) );
		leinwand.addEventListener( 'pointerup', ( ereignis ) => this.zeigerHoch( ereignis ) );
		leinwand.addEventListener( 'pointercancel', ( ereignis ) => this.zeigerHoch( ereignis ) );
		leinwand.addEventListener( 'keydown', ( ereignis ) => this.taste( ereignis ) );
		leinwand.addEventListener( 'contextmenu', ( ereignis ) => ereignis.preventDefault() );

		if ( this.k.knopfZuruecksetzen ) {
			this.k.knopfZuruecksetzen.hidden = false;
			this.k.knopfZuruecksetzen.addEventListener( 'click', () => {
				this.zielAzimut = 0;
				this.zielPolar = Math.PI / 2 - 0.06;
				this.k.sageAn( this.k.texte.ansicht_neu );
			} );
		}

		this.beobachter = new ResizeObserver( () => this.passeAn() );
		this.beobachter.observe( this.behaelter );
		this.passeAn();
	}

	/**
	 * Größe und Bildausschnitt an den Container anpassen.
	 */
	passeAn() {
		const breite = this.behaelter.clientWidth || 1;
		const hoehe = this.behaelter.clientHeight || 1;

		this.renderer.setSize( breite, hoehe, false );
		this.kamera.aspect = breite / hoehe;
		this.kamera.updateProjectionMatrix();
		this.richteKameraAus();
	}

	/**
	 * Abstand und Ziel so wählen, dass der Haken das Bild füllt.
	 *
	 * Maßgeblich ist der Umriss dessen, was gerade in der Szene steht –
	 * eine breite Ablage darf nicht am Rand abgeschnitten werden, nur
	 * weil das Basismodul schmal ist. Gerechnet wird gegen beide
	 * Richtungen des Bildwinkels; der größere Abstand gewinnt.
	 */
	richteKameraAus() {
		const THREE = this.THREE;
		const basis = this.k.basis();
		const winkel = ( this.kamera.fov * Math.PI ) / 180;

		let hoehe = basis.height / MM;
		let breite = ( basis.width || 60 ) / MM;

		this.mitte = hoehe / 2;
		this.ziel = new THREE.Vector3( 0, this.mitte, 0 );

		const umriss = new THREE.Box3().setFromObject( this.gruppe );

		// Solange noch nichts geladen ist, gilt der Umriss des Basismoduls.
		if ( umriss.isEmpty() ) {
			umriss.set( new THREE.Vector3( -breite / 2, 0, 0 ), new THREE.Vector3( breite / 2, hoehe, 0.06 ) );
		}

		hoehe = umriss.max.y - umriss.min.y;
		breite = Math.max( umriss.max.x - umriss.min.x, umriss.max.z - umriss.min.z );
		this.mitte = ( umriss.min.y + umriss.max.y ) / 2;

		// Gedreht wird um die Mitte des Umrisses, nicht um die Achse des
		// Basismoduls: Module bauen nach vorn, und wer um die Achse dreht,
		// schiebt den Haken aus dem Bild.
		umriss.getCenter( this.ziel );

		const luft = 1.25;
		const senkrecht = ( hoehe * luft / 2 ) / Math.tan( winkel / 2 );
		const waagerecht = ( breite * luft / 2 ) / ( Math.tan( winkel / 2 ) * this.kamera.aspect );

		this.abstand = Math.max( senkrecht, waagerecht );
	}

	/**
	 * Ein Teil laden – aus dem Modell oder als maßhaltiger Ersatzkörper.
	 *
	 * @param {object} teil Katalogeintrag.
	 * @returns {Promise<object>} Vorlage zum Klonen.
	 */
	async vorlage( teil ) {
		const schluessel = teil.model || 'ersatz:' + teil.article;

		if ( this.zwischenlager.has( schluessel ) ) {
			return this.zwischenlager.get( schluessel );
		}

		const versprechen = ( async () => {
			if ( teil.model ) {
				try {
					const geladen = await this.lader.loadAsync( teil.model );

					if ( 'holzstange' === teil.construction ) {
						return this.holzstange( geladen.scene, teil );
					}

					const lage = await this.lageModul();

					return lage.platziere( this.THREE, geladen.scene, teil.height, teil.placement );
				} catch ( fehler ) {
					// Ein fehlendes Modell darf die Bestellung nicht aufhalten –
					// der Grund gehört trotzdem in die Konsole, sonst sucht
					// man ihn lange.
					if ( window.console ) {
						window.console.error( 'Konfigurator: Modell konnte nicht aufgebaut werden', teil.model, fehler );
					}

					return this.ersatzkoerper( teil );
				}
			}

			return this.ersatzkoerper( teil );
		} )();

		this.zwischenlager.set( schluessel, versprechen );

		return versprechen;
	}

	/**
	 * Das Modul mit der Rechnung für die Lage der Modelle.
	 *
	 * Dieselbe Datei benutzt die Vorschau im ERP.
	 *
	 * @returns {Promise<object>}
	 */
	lageModul() {
		if ( ! this.lageVersprechen ) {
			this.lageVersprechen = import( DATEN.urls.lage );
		}

		return this.lageVersprechen;
	}

	/**
	 * Die Holzstange mit ihren zwei Haltern.
	 *
	 * Das Modell ist ein einzelner Halter (zwanzig Millimeter breit, fünfzig
	 * hoch, fünfundvierzig tief, Rückseite an der Wand). Die Stange – ein
	 * Rundholz von zwanzig Millimetern – liegt in der Rille an seiner
	 * Vorderseite, ihre Achse also fünfundvierzig Millimeter von der Wand.
	 * Die beiden Halter sitzen im Abstand von neunhundert Millimetern
	 * mittig auf der Stange; ist die Stange kürzer als tausend Millimeter,
	 * rücken sie zusammen. Dieselben Zahlen rechnet PHP für die
	 * Sperrbereiche (Layout::holder_zones).
	 *
	 * @param {object} objekt Szene mit einem Halter.
	 * @param {object} teil   Katalogeintrag mit der Länge der Stange.
	 * @returns {object}
	 */
	holzstange( objekt, teil ) {
		const THREE = this.THREE;
		const laenge = teil.height / MM;
		const halterHoehe = 0.05;
		const abstand = Math.min( 0.9, laenge - 2 * halterHoehe );
		const erster = ( laenge - abstand ) / 2;

		objekt.updateMatrixWorld( true );

		// Das Modell kann in Millimetern oder in Metern vorliegen: Alles,
		// was größer als zwanzig Einheiten ist, sind Millimeter.
		const roh = new THREE.Box3().setFromObject( objekt );
		const faktor = Math.max( roh.max.x - roh.min.x, roh.max.y - roh.min.y, roh.max.z - roh.min.z ) > 20 ? 0.001 : 1;

		objekt.scale.setScalar( faktor );
		objekt.updateMatrixWorld( true );

		const kasten = new THREE.Box3().setFromObject( objekt );
		const mitte = kasten.getCenter( new THREE.Vector3() );

		// Mitte der Breite auf die Stange, Rückseite an die Wand, Mitte der
		// Höhe auf null – das Setzen auf die Stelle folgt unten.
		objekt.position.x -= mitte.x;
		objekt.position.y -= mitte.y;
		objekt.position.z -= kasten.min.z;
		objekt.traverse( ( knoten ) => {
			if ( knoten.isMesh ) {
				knoten.castShadow = true;
				knoten.receiveShadow = true;
			}
		} );

		const tiefe = kasten.max.z - kasten.min.z;
		const huelle = new THREE.Group();

		[ erster, erster + abstand ].forEach( ( stelle ) => {
			const halter = new THREE.Group();

			halter.add( objekt.clone( true ) );
			halter.position.y = stelle;
			huelle.add( halter );
		} );

		const stange = new THREE.Mesh(
			new THREE.CylinderGeometry( 0.01, 0.01, laenge, 32 ),
			new THREE.MeshStandardMaterial( { color: 0xc9a57a, roughness: 0.7, metalness: 0 } )
		);

		stange.position.set( 0, laenge / 2, tiefe );
		stange.castShadow = true;
		stange.receiveShadow = true;
		stange.userData.festeFarbe = true;
		huelle.add( stange );

		return huelle;
	}

	/**
	 * Ersatzkörper aus den gepflegten Maßen.
	 *
	 * @param {object} teil Katalogeintrag.
	 * @returns {object}
	 */
	ersatzkoerper( teil ) {
		const THREE = this.THREE;
		const breite = ( teil.width || 40 ) / MM;
		const hoehe = ( teil.height || 40 ) / MM;
		const tiefe = ( teil.depth || 40 ) / MM;

		const koerper = new THREE.Mesh(
			new THREE.BoxGeometry( breite, hoehe, tiefe ),
			new THREE.MeshStandardMaterial( { color: 0xffffff, roughness: 0.55, metalness: 0.02 } )
		);
		koerper.position.set( 0, hoehe / 2, tiefe / 2 );
		koerper.castShadow = true;
		koerper.receiveShadow = true;

		const huelle = new THREE.Group();
		huelle.add( koerper );

		return huelle;
	}

	/**
	 * Szene neu bestücken.
	 */
	async neuAufbauen() {
		const basis = this.k.basis();

		this.richteKameraAus();

		const vorlageBasis = await this.vorlage( basis );

		if ( this.basisKoerper ) {
			this.gruppe.remove( this.basisKoerper );
		}

		this.basisKoerper = vorlageBasis.clone( true );
		this.gruppe.add( this.basisKoerper );

		// Module: alles entfernen, was nicht mehr da ist, und alles
		// nachladen, was dazugekommen ist.
		const vorhanden = new Set( this.k.zustand.module.map( ( m ) => m.uid ) );

		this.koerper.forEach( ( koerper, uid ) => {
			if ( ! vorhanden.has( uid ) ) {
				this.gruppe.remove( koerper );
				this.koerper.delete( uid );
			}
		} );

		for ( const eintrag of this.k.zustand.module ) {
			const teil = this.k.module.get( eintrag.artikel );

			if ( ! teil ) {
				continue;
			}

			const alt = this.koerper.get( eintrag.uid );

			if ( alt && alt.userData.artikel === eintrag.artikel ) {
				continue;
			}

			if ( alt ) {
				this.gruppe.remove( alt );
			}

			const koerper = ( await this.vorlage( teil ) ).clone( true );
			koerper.userData.artikel = eintrag.artikel;
			koerper.userData.uid = eintrag.uid;
			this.koerper.set( eintrag.uid, koerper );
			this.gruppe.add( koerper );
		}

		this.setzePositionen();
		this.faerbe();
		this.hebeHervor( this.k.ausgewaehlt );
		this.richteKameraAus();
	}

	/**
	 * Module auf ihre Höhe setzen.
	 */
	setzePositionen() {
		this.k.zustand.module.forEach( ( eintrag ) => {
			const koerper = this.koerper.get( eintrag.uid );

			if ( koerper ) {
				koerper.position.y = eintrag.position / MM;
			}
		} );
	}

	/**
	 * Alles in der gewählten Farbe einfärben.
	 */
	faerbe() {
		const THREE = this.THREE;
		const farbe = ( this.k.katalog.colors.find( ( f ) => f.slug === this.k.zustand.farbe ) || {} ).hex || '#d8d8d6';
		const wert = new THREE.Color( farbe );

		this.gruppe.traverse( ( knoten ) => {
			if ( ! knoten.isMesh || knoten.userData.istSperre || knoten.userData.festeFarbe ) {
				return;
			}

			// Materialien werden beim Klonen geteilt. Ohne eigene Kopie
			// färbt eine Änderung alle Module gleichzeitig um.
			if ( ! knoten.userData.eigenesMaterial ) {
				knoten.material = knoten.material.clone();
				knoten.userData.eigenesMaterial = true;
			}

			if ( knoten.material.color ) {
				knoten.material.color.copy( wert );
			}
		} );
	}

	/**
	 * Das ausgewählte Modul hervorheben.
	 *
	 * @param {string|null} uid Kennung.
	 */
	hebeHervor( uid ) {
		this.koerper.forEach( ( koerper, schluessel ) => {
			koerper.traverse( ( knoten ) => {
				if ( knoten.isMesh && knoten.material.emissive ) {
					knoten.material.emissive.set( schluessel === uid ? 0x2b4a6b : 0x000000 );
					knoten.material.emissiveIntensity = schluessel === uid ? 0.35 : 0;
				}
			} );
		} );
	}

	/**
	 * Zeigerposition in Bildkoordinaten.
	 *
	 * @param {PointerEvent} ereignis Ereignis.
	 */
	merkeZeiger( ereignis ) {
		const kasten = this.renderer.domElement.getBoundingClientRect();

		this.zeiger.x = ( ( ereignis.clientX - kasten.left ) / kasten.width ) * 2 - 1;
		this.zeiger.y = -( ( ereignis.clientY - kasten.top ) / kasten.height ) * 2 + 1;
	}

	/**
	 * Welches Modul liegt unter dem Zeiger?
	 *
	 * @returns {string|null} Kennung.
	 */
	getroffenesModul() {
		this.strahl.setFromCamera( this.zeiger, this.kamera );

		const treffer = this.strahl.intersectObjects( [ ...this.koerper.values() ], true );

		if ( ! treffer.length ) {
			return null;
		}

		let knoten = treffer[ 0 ].object;

		while ( knoten && ! knoten.userData.uid ) {
			knoten = knoten.parent;
		}

		return knoten ? knoten.userData.uid : null;
	}

	/**
	 * Zeiger gedrückt: Modul greifen oder Ansicht drehen.
	 *
	 * @param {PointerEvent} ereignis Ereignis.
	 */
	zeigerRunter( ereignis ) {
		this.renderer.domElement.setPointerCapture( ereignis.pointerId );
		this.renderer.domElement.focus( { preventScroll: true } );
		this.merkeZeiger( ereignis );

		const uid = this.getroffenesModul();

		if ( uid ) {
			this.k.waehle( uid );
			this.zieht = uid;
			this.griff = this.hoeheUnterZeiger() - this.k.zustand.module.find( ( m ) => m.uid === uid ).position;
			ereignis.preventDefault();
			return;
		}

		this.k.waehle( null );
		this.dreht = { x: ereignis.clientX, y: ereignis.clientY };
	}

	/**
	 * Höhe in Millimetern, die der Zeiger gerade meint.
	 *
	 * Gerechnet wird gegen eine senkrechte Ebene durch das Teil, die zur
	 * Kamera zeigt – so entspricht die Mausbewegung dem, was man sieht,
	 * unabhängig vom Drehwinkel.
	 *
	 * @returns {number}
	 */
	hoeheUnterZeiger() {
		const THREE = this.THREE;
		const richtung = new THREE.Vector3();

		this.kamera.getWorldDirection( richtung );
		richtung.y = 0;
		richtung.normalize();

		this.ebene.setFromNormalAndCoplanarPoint( richtung, this.ziel );
		this.strahl.setFromCamera( this.zeiger, this.kamera );

		if ( ! this.strahl.ray.intersectPlane( this.ebene, this.treffer ) ) {
			return 0;
		}

		return this.treffer.y * MM;
	}

	/**
	 * Zeiger bewegt.
	 *
	 * @param {PointerEvent} ereignis Ereignis.
	 */
	zeigerBewegt( ereignis ) {
		if ( this.zieht ) {
			this.merkeZeiger( ereignis );
			this.k.verschiebe( this.zieht, this.hoeheUnterZeiger() - this.griff );
			this.setzePositionen();
			ereignis.preventDefault();
			return;
		}

		if ( ! this.dreht ) {
			this.merkeZeiger( ereignis );
			this.renderer.domElement.style.cursor = this.getroffenesModul() ? 'grab' : 'default';
			return;
		}

		const dx = ereignis.clientX - this.dreht.x;
		const dy = ereignis.clientY - this.dreht.y;

		this.dreht = { x: ereignis.clientX, y: ereignis.clientY };

		this.zielAzimut = begrenze( this.zielAzimut + dx * 0.006, -Math.PI / 6, Math.PI / 6 );
		this.zielPolar = begrenze( this.zielPolar + dy * 0.005, Math.PI / 2 - 0.42, Math.PI / 2 + 0.2 );
	}

	/**
	 * Zeiger losgelassen.
	 *
	 * @param {PointerEvent} ereignis Ereignis.
	 */
	zeigerHoch( ereignis ) {
		if ( this.zieht ) {
			this.k.zeichneListe();
			this.k.waehle( this.zieht );
		}

		this.zieht = null;
		this.dreht = null;

		if ( this.renderer.domElement.hasPointerCapture( ereignis.pointerId ) ) {
			this.renderer.domElement.releasePointerCapture( ereignis.pointerId );
		}
	}

	/**
	 * Tastatur: das gewählte Modul millimeterweise verschieben.
	 *
	 * @param {KeyboardEvent} ereignis Ereignis.
	 */
	taste( ereignis ) {
		if ( ! this.k.ausgewaehlt ) {
			return;
		}

		const schritt = ereignis.shiftKey ? 10 : 1;
		let richtung = 0;

		if ( 'ArrowUp' === ereignis.key ) {
			richtung = 1;
		} else if ( 'ArrowDown' === ereignis.key ) {
			richtung = -1;
		} else {
			return;
		}

		const eintrag = this.k.zustand.module.find( ( m ) => m.uid === this.k.ausgewaehlt );

		this.k.verschiebe( this.k.ausgewaehlt, eintrag.position + richtung * schritt, true );
		this.setzePositionen();
		ereignis.preventDefault();
	}

	/**
	 * Bildschleife mit weicher Nachführung.
	 */
	schleife() {
		const zeichne = () => {
			if ( ! this.laeuft ) {
				return;
			}

			this.azimut += ( this.zielAzimut - this.azimut ) * 0.14;
			this.polar += ( this.zielPolar - this.polar ) * 0.14;

			const r = this.abstand;
			this.kamera.position.set(
				this.ziel.x + r * Math.sin( this.polar ) * Math.sin( this.azimut ),
				this.ziel.y + r * Math.cos( this.polar ),
				this.ziel.z + r * Math.sin( this.polar ) * Math.cos( this.azimut )
			);
			this.kamera.lookAt( this.ziel );

			this.licht.target.position.copy( this.ziel );
			this.licht.target.updateMatrixWorld();

			this.renderer.render( this.szene, this.kamera );

			window.requestAnimationFrame( zeichne );
		};

		window.requestAnimationFrame( zeichne );
	}
}

/**
 * Wert in Grenzen halten.
 *
 * @param {number} wert Wert.
 * @param {number} min  Untere Grenze.
 * @param {number} max  Obere Grenze.
 * @returns {number}
 */
function begrenze( wert, min, max ) {
	return Math.min( Math.max( wert, min ), max );
}

/* ------------------------------------------------------------------ */
/* Start                                                               */
/* ------------------------------------------------------------------ */

/**
 * Kann dieser Browser WebGL?
 *
 * @returns {boolean}
 */
function kannWebGl() {
	try {
		const leinwand = document.createElement( 'canvas' );

		return !! ( window.WebGLRenderingContext && ( leinwand.getContext( 'webgl2' ) || leinwand.getContext( 'webgl' ) ) );
	} catch ( fehler ) {
		return false;
	}
}

/**
 * Alles starten.
 */
async function start() {
	if ( ! DATEN ) {
		// Ohne den Katalog gibt es nichts zu bedienen. Das ist kein
		// Zustand, den ein Besucher beheben kann - aber einer, den man
		// beim Suchen sofort sehen will, statt vor einem Hinweis zu
		// stehen, der ewig „wird geladen" sagt.
		window.console.warn( 'Konfigurator: window.LW16_HAKEN fehlt - das Skript wurde ohne seine Daten ausgeliefert.' );
		return;
	}

	const wurzeln = document.querySelectorAll( '[data-lw16-haken]' );

	for ( const wurzel of wurzeln ) {
		const konfigurator = new Konfigurator( wurzel );

		konfigurator.hinweis.textContent = konfigurator.texte.laedt;

		if ( ! kannWebGl() ) {
			konfigurator.hinweis.textContent = konfigurator.texte.kein_webgl;
			continue;
		}

		try {
			const [ THREE, loader, umgebung ] = await Promise.all( [
				import( 'three' ),
				import( 'three/addons/loaders/GLTFLoader.js' ),
				import( 'three/addons/environments/RoomEnvironment.js' ),
			] );

			konfigurator.hinweis.hidden = true;
			konfigurator.ansicht = new Ansicht( konfigurator, THREE, loader.GLTFLoader, umgebung.RoomEnvironment );
		} catch ( fehler ) {
			// Die Bibliothek fehlt oder ist blockiert: Das Formular bleibt.
			konfigurator.hinweis.textContent = konfigurator.texte.kein_webgl;
		}
	}
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', start );
} else {
	start();
}
