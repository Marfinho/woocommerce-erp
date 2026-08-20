/**
 * Live-Anzeige der Rabattstaffel.
 *
 * Rechnet mit, während Mengen eingetragen werden: erreichte Stufe, Bestellwert,
 * wie viel bis zur nächsten Stufe fehlt und was sie einbringt.
 *
 * WICHTIG: Diese Anzeige ist eine Hilfe, keine Quelle der Wahrheit. Verbindlich
 * rechnet der Server (DiscountResolver) beim Absenden und schreibt den Rabatt
 * auf der Bestellung fest. Weichen beide je voneinander ab, gilt der Server.
 *
 * Zwei Betriebsarten:
 * - "admin": zeigt zusätzlich den Deckungsbeitrag je Position und warnt, wenn
 *   er unter die Schwelle fällt.
 * - "public": Nachbestell-Seite des Händlers. Zeigt ausschließlich seine
 *   eigenen Konditionen – keine Kosten, keine Deckungsbeiträge, keine Bestände.
 */
( function () {
	'use strict';

	var config = window.LW16Staffel;

	if ( ! config || ! config.articles ) {
		return;
	}

	var target = document.getElementById( 'lw16-staffel' );

	if ( ! target ) {
		return;
	}

	/**
	 * Text für die Einbettung in HTML entschärfen.
	 *
	 * Stufennamen und Artikelnummern kommen aus der Datenbank; sie werden hier
	 * zusammengesetzt statt über textContent gesetzt.
	 *
	 * @param {string} text Text.
	 * @return {string} Entschärfter Text.
	 */
	function escapeHtml( text ) {
		var div = document.createElement( 'div' );
		div.textContent = String( text );
		return div.innerHTML;
	}

	/**
	 * Betrag in deutscher Schreibweise.
	 *
	 * @param {number} value Betrag.
	 * @return {string} Formatierter Betrag.
	 */
	function euro( value ) {
		return ( Math.round( value * 100 ) / 100 )
			.toFixed( 2 )
			.replace( '.', ',' ) + ' €';
	}

	/**
	 * Eingetragene Mengen einsammeln.
	 *
	 * @return {Array} Positionen mit Artikel und Menge.
	 */
	function collect() {
		var items = [];

		Object.keys( config.articles ).forEach( function ( id ) {
			var input = document.querySelector( '[name="menge[' + id + ']"]' );

			if ( ! input ) {
				return;
			}

			var qty = parseFloat( String( input.value ).replace( ',', '.' ) ) || 0;

			if ( qty > 0 ) {
				items.push( { id: id, qty: qty, article: config.articles[ id ] } );
			}
		} );

		return items;
	}

	/**
	 * Stufe zum rabattfähigen Bestellwert bestimmen.
	 *
	 * Gleiche Regel wie serverseitig: Der Wert zählt zum UVP, also vor Rabatt –
	 * sonst rutschte eine Bestellung durch den Rabatt unter die eigene Schwelle.
	 *
	 * @param {number} base Rabattfähiger Bestellwert.
	 * @return {Object} Aktuelle und nächste Stufe.
	 */
	function resolveTier( base ) {
		var current = null;
		var next = null;

		config.tiers.forEach( function ( tier ) {
			if ( base >= tier.min ) {
				if ( ! current || tier.pct >= current.pct ) {
					current = tier;
				}
			} else if ( ! next ) {
				next = tier;
			}
		} );

		return { current: current, next: next };
	}

	/**
	 * Deckungsbeitrag II je Stück – spiegelt CostCalculator::position_contribution.
	 *
	 * Verpackung und feste Zahlungsgebühr fehlen bewusst: Sie fallen einmal je
	 * Bestellung an, nicht je Position.
	 *
	 * @param {Object} cost  Kostenwerte des Artikels.
	 * @param {number} price Stückpreis brutto nach Rabatt.
	 * @return {number} Deckungsbeitrag II.
	 */
	function positionDb2( cost, price ) {
		var s = config.settings;
		var netto = price / ( 1 + s.vat_rate );
		var ausschuss = cost.hk * s.scrap_surcharge_pct;
		var zahlung = price * s.payment_fee_pct;
		var handling = cost.post / 60 * s.labor_rate_per_h;

		return netto - ( cost.hk + ausschuss + zahlung ) - handling;
	}

	/**
	 * Anzeige neu aufbauen.
	 */
	function render() {
		var items = collect();

		if ( ! items.length ) {
			target.innerHTML = '';
			return;
		}

		var discountable = 0;
		var fixed = 0;

		items.forEach( function ( item ) {
			var value = item.qty * item.article.price;

			if ( item.article.discountable ) {
				discountable += value;
			} else {
				fixed += value;
			}
		} );

		var tier = resolveTier( discountable );
		var pct = tier.current ? tier.current.pct : 0;
		var netValue = discountable * ( 1 - pct / 100 ) + fixed;

		var html = '<div class="lw16-staffel__row"><span>' + config.i18n.orderValue + '</span><strong>' +
			euro( discountable + fixed ) + '</strong></div>';

		html += '<div class="lw16-staffel__row"><span>' + config.i18n.tier + '</span><strong>' +
			( tier.current ? escapeHtml( tier.current.name ) + ' · ' + pct.toString().replace( '.', ',' ) + ' %' : config.i18n.noTier ) +
			'</strong></div>';

		html += '<div class="lw16-staffel__row"><span>' + config.i18n.afterDiscount + '</span><strong>' +
			euro( netValue ) + '</strong></div>';

		if ( fixed > 0 ) {
			html += '<p class="lw16-staffel__note">' + config.i18n.notDiscountable.replace( '%s', euro( fixed ) ) + '</p>';
		}

		// Der Hinweis, der im Gespräch zählt: was fehlt und was es bringt.
		if ( tier.next ) {
			var missing = tier.next.min - discountable;
			var saving = ( tier.next.min * tier.next.pct / 100 ) - ( tier.next.min * pct / 100 );

			html += '<p class="lw16-staffel__next">' +
				config.i18n.nextTier
					.replace( '%1$s', euro( missing ) )
					.replace( '%2$s', escapeHtml( tier.next.name ) )
					.replace( '%3$s', tier.next.pct.toString().replace( '.', ',' ) + ' %' )
					.replace( '%4$s', euro( saving ) ) +
				'</p>';
		}

		// Deckungsbeitrag nur intern.
		if ( 'admin' === config.mode && config.costs ) {
			var warnings = [];

			items.forEach( function ( item ) {
				var cost = config.costs[ item.id ];

				if ( ! cost ) {
					return;
				}

				var price = item.article.discountable
					? item.article.price * ( 1 - pct / 100 )
					: item.article.price;

				var db2 = positionDb2( cost, price );

				if ( db2 < config.settings.min_db2 ) {
					warnings.push(
						config.i18n.lowMargin
							.replace( '%1$s', item.article.sku )
							.replace( '%2$s', euro( db2 ) )
							.replace( '%3$s', euro( config.settings.min_db2 ) )
					);
				}
			} );

			if ( warnings.length ) {
				html += '<div class="lw16-staffel__warn"><strong>' + config.i18n.marginWarning + '</strong><ul>';
				warnings.forEach( function ( warning ) {
					var li = document.createElement( 'li' );
					li.textContent = warning;
					html += li.outerHTML;
				} );
				html += '</ul></div>';
			}
		}

		target.innerHTML = html;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Object.keys( config.articles ).forEach( function ( id ) {
			var input = document.querySelector( '[name="menge[' + id + ']"]' );

			if ( input ) {
				input.addEventListener( 'input', render );
				input.addEventListener( 'change', render );
			}
		} );

		render();
	} );
}() );
