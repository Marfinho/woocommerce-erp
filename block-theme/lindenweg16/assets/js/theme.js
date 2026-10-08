/**
 * Lindenweg16 – kleines Theme-Skript ohne jQuery und ohne Build-Schritt.
 *
 * Jede Funktion ist eine Zugabe: Ohne JavaScript bleibt die Seite voll
 * bedienbar (Laufband als Pillen-Reihe, kein fixierter Balken, kein
 * Fortschrittsbalken).
 *
 *  1. Header: stärkerer Schatten nach dem ersten Scrollen
 *  2. Laufband: Inhalt verdoppeln, damit die Schleife nahtlos läuft
 *  3. Produktseite mobil: fixierter „In den Warenkorb“-Balken
 *  4. Mini-Cart: „Noch X € bis zum kostenlosen Versand“
 */
( function () {
	'use strict';

	var config = window.lindenweg16 || {};
	var i18n = config.i18n || {};

	function ready( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	/* 1. Header -------------------------------------------------------- */

	function initHeader() {
		var ticking = false;

		function update() {
			document.body.classList.toggle( 'lw16-is-scrolled', window.scrollY > 8 );
			ticking = false;
		}

		window.addEventListener(
			'scroll',
			function () {
				if ( ! ticking ) {
					window.requestAnimationFrame( update );
					ticking = true;
				}
			},
			{ passive: true }
		);
		update();
	}

	/* 2. Laufband ------------------------------------------------------ */

	function initMarquees() {
		document.querySelectorAll( '.lw16-marquee' ).forEach( function ( marquee ) {
			var track = marquee.querySelector( '.lw16-marquee__track' );

			if ( ! track || marquee.classList.contains( 'is-ready' ) ) {
				return;
			}

			var items = Array.prototype.slice.call( track.children );

			// Die Kopie ist nur Dekoration: für Screenreader und Tastatur unsichtbar.
			items.forEach( function ( item ) {
				var clone = item.cloneNode( true );
				clone.setAttribute( 'aria-hidden', 'true' );
				clone.querySelectorAll( 'a, button, input, [tabindex]' ).forEach( function ( el ) {
					el.setAttribute( 'tabindex', '-1' );
				} );
				clone.removeAttribute( 'id' );
				track.appendChild( clone );
			} );

			// Gleiches Tempo, egal wie viele Pillen es sind.
			var seconds = Math.max( 18, Math.round( track.scrollWidth / 2 / 45 ) );
			marquee.style.setProperty( '--lw16-marquee-duration', seconds + 's' );
			marquee.classList.add( 'is-ready' );
		} );
	}

	/* 3. Fixierter Warenkorb-Balken auf der Produktseite --------------- */

	function initStickyAddToCart() {
		var form = document.querySelector(
			'.lw16-product__summary .wp-block-woocommerce-add-to-cart-with-options, .lw16-product__summary .wp-block-woocommerce-add-to-cart-form'
		);

		if ( ! form ) {
			return;
		}

		var titleEl = document.querySelector( '.lw16-product__summary .wp-block-post-title' );
		var priceEl = document.querySelector( '.lw16-product__summary .wc-block-components-product-price, .lw16-product__summary .price' );

		var bar = document.createElement( 'div' );
		bar.className = 'lw16-sticky-atc';
		bar.setAttribute( 'role', 'region' );
		bar.setAttribute( 'aria-label', i18n.stickyAddToCart || '' );

		var info = document.createElement( 'div' );
		info.className = 'lw16-sticky-atc__info';

		var title = document.createElement( 'span' );
		title.className = 'lw16-sticky-atc__title';
		title.textContent = titleEl ? titleEl.textContent.trim() : '';
		info.appendChild( title );

		var price = document.createElement( 'span' );
		price.className = 'lw16-sticky-atc__price';
		info.appendChild( price );

		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'lw16-sticky-atc__button';
		button.textContent = i18n.stickyAddToCart || '';

		bar.appendChild( info );
		bar.appendChild( button );
		bar.setAttribute( 'hidden', '' );
		document.body.appendChild( bar );
		document.body.classList.add( 'lw16-has-sticky-atc' );

		function syncPrice() {
			// Nur Text übernehmen – kein HTML aus dem DOM weiterreichen.
			if ( ! priceEl ) {
				return;
			}
			var current = ( priceEl.querySelector( 'ins' ) || priceEl ).cloneNode( true );
			// Screenreader-Texte („Preisspanne: …“) nicht doppelt anzeigen.
			current.querySelectorAll( '.screen-reader-text, .wc-block-components-product-price__screen-reader-text' ).forEach( function ( el ) {
				el.remove();
			} );
			price.textContent = current.textContent.replace( /\s+/g, ' ' ).trim();
		}

		function realButton() {
			return form.querySelector( 'button[type="submit"], .single_add_to_cart_button, .wp-block-woocommerce-product-button button' );
		}

		function needsChoice() {
			var btn = realButton();
			return ! btn || btn.disabled || btn.getAttribute( 'aria-disabled' ) === 'true' || btn.classList.contains( 'disabled' );
		}

		function syncLabel() {
			button.textContent = needsChoice() ? ( i18n.stickyChooseOption || '' ) : ( i18n.stickyAddToCart || '' );
		}

		button.addEventListener( 'click', function () {
			var btn = realButton();

			if ( needsChoice() || ! btn ) {
				// Erst Ausführung wählen: zum Formular scrollen und das erste Feld fokussieren.
				form.scrollIntoView( { behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth', block: 'center' } );
				var first = form.querySelector( 'button, select, input' );
				if ( first ) {
					first.focus( { preventScroll: true } );
				}
				return;
			}

			btn.click();
		} );

		// Per Scroll statt IntersectionObserver: Ein schneller Sprung von unterhalb
		// nach oberhalb des Formulars kreuzt den sichtbaren Bereich nie.
		var shown = false;
		var hideTimer;
		var queued = false;

		function update() {
			queued = false;
			// Erst zeigen, wenn das Formular nach oben aus dem Bild gescrollt ist.
			var show = form.getBoundingClientRect().bottom < 0;

			if ( show === shown ) {
				return;
			}
			shown = show;
			window.clearTimeout( hideTimer );

			if ( show ) {
				syncPrice();
				syncLabel();
				bar.removeAttribute( 'hidden' );
				window.requestAnimationFrame( function () {
					window.requestAnimationFrame( function () {
						bar.classList.add( 'is-visible' );
					} );
				} );
			} else {
				bar.classList.remove( 'is-visible' );
				hideTimer = window.setTimeout( function () {
					bar.setAttribute( 'hidden', '' );
				}, 400 );
			}
		}

		function queue() {
			if ( ! queued ) {
				queued = true;
				window.requestAnimationFrame( update );
			}
		}

		window.addEventListener( 'scroll', queue, { passive: true } );
		window.addEventListener( 'resize', queue );
		update();

		form.addEventListener( 'change', function () {
			window.setTimeout( function () {
				syncPrice();
				syncLabel();
			}, 50 );
		} );
	}

	/* 4. Mini-Cart: Fortschritt bis zum kostenlosen Versand ------------ */

	function formatMoney( amount, fs ) {
		var fixed = amount.toFixed( fs.decimals );
		var parts = fixed.split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, fs.thousandSep );
		var number = parts.join( fs.decimals > 0 ? fs.decimalSep : '' );

		switch ( fs.position ) {
			case 'left':
				return fs.symbol + number;
			case 'left_space':
				return fs.symbol + ' ' + number;
			case 'right':
				return number + fs.symbol;
			default:
				return number + ' ' + fs.symbol;
		}
	}

	function initFreeShipping() {
		var fs = config.freeShipping;

		if ( ! fs || ! fs.threshold || ! config.storeApi || ! window.fetch ) {
			return;
		}

		var cartTotal = null;
		var pending = null;

		function loadCart() {
			if ( pending ) {
				return pending;
			}

			pending = window
				.fetch( config.storeApi, { credentials: 'same-origin', headers: { Accept: 'application/json' } } )
				.then( function ( response ) {
					return response.ok ? response.json() : null;
				} )
				.then( function ( cart ) {
					pending = null;
					if ( ! cart || ! cart.totals ) {
						return null;
					}
					var minor = parseInt( cart.totals.currency_minor_unit, 10 ) || 0;
					var items = parseInt( cart.totals.total_items, 10 ) || 0;
					var tax = parseInt( cart.totals.total_items_tax, 10 ) || 0;
					// Warenwert inkl. MwSt., ohne Versand – so rechnet WooCommerce den Mindestbestellwert.
					cartTotal = ( items + tax ) / Math.pow( 10, minor );
					return cartTotal;
				} )
				.catch( function () {
					pending = null;
					return null;
				} );

			return pending;
		}

		function render( footer ) {
			if ( cartTotal === null ) {
				return;
			}

			var box = footer.querySelector( '.lw16-freeship' );

			if ( ! box ) {
				box = document.createElement( 'div' );
				box.className = 'lw16-freeship';

				var text = document.createElement( 'p' );
				text.className = 'lw16-freeship__text';
				text.setAttribute( 'aria-live', 'polite' );

				var barWrap = document.createElement( 'div' );
				barWrap.className = 'lw16-freeship__bar';
				barWrap.setAttribute( 'aria-hidden', 'true' );
				barWrap.appendChild( document.createElement( 'span' ) );

				box.appendChild( text );
				box.appendChild( barWrap );
				footer.insertBefore( box, footer.firstChild );
			}

			var left = Math.max( 0, fs.threshold - cartTotal );
			var progress = Math.min( 100, ( cartTotal / fs.threshold ) * 100 );
			var textEl = box.querySelector( '.lw16-freeship__text' );

			box.classList.toggle( 'is-done', left <= 0 );

			if ( left <= 0 ) {
				textEl.textContent = i18n.freeShippingDone || '';
			} else {
				// Der Betrag wird als eigener Knoten eingesetzt, nie als HTML.
				var template = i18n.freeShippingLeft || '%s';
				var pieces = template.split( '%s' );
				textEl.textContent = '';
				textEl.appendChild( document.createTextNode( pieces[ 0 ] || '' ) );
				var strong = document.createElement( 'strong' );
				strong.textContent = formatMoney( left, fs );
				textEl.appendChild( strong );
				textEl.appendChild( document.createTextNode( pieces.slice( 1 ).join( '%s' ) ) );
			}

			box.querySelector( '.lw16-freeship__bar span' ).style.setProperty( '--lw16-progress', progress.toFixed( 1 ) + '%' );
		}

		function renderAll() {
			document.querySelectorAll( '.wc-block-mini-cart__footer' ).forEach( render );
		}

		function refresh() {
			loadCart().then( renderAll );
		}

		// Der Drawer wird erst beim Öffnen gerendert – darauf warten.
		var observer = new MutationObserver( function ( mutations ) {
			for ( var i = 0; i < mutations.length; i++ ) {
				var added = mutations[ i ].addedNodes;
				for ( var j = 0; j < added.length; j++ ) {
					var node = added[ j ];
					if ( node.nodeType !== 1 ) {
						continue;
					}
					if ( node.matches( '.wc-block-mini-cart__footer' ) || node.querySelector( '.wc-block-mini-cart__footer' ) ) {
						refresh();
						return;
					}
				}
			}
		} );
		observer.observe( document.body, { childList: true, subtree: true } );

		[ 'wc-blocks_added_to_cart', 'wc-blocks_removed_from_cart', 'wc-blocks_cart_changed' ].forEach( function ( name ) {
			document.body.addEventListener( name, refresh );
			document.addEventListener( name, refresh );
		} );

		// Mengen im Drawer ändern: kurz warten, bis WooCommerce gespeichert hat.
		var debounce;
		document.addEventListener( 'click', function ( event ) {
			if ( event.target.closest && event.target.closest( '.wc-block-mini-cart__drawer .wc-block-components-quantity-selector, .wc-block-mini-cart__drawer .wc-block-cart-item__remove-link' ) ) {
				window.clearTimeout( debounce );
				debounce = window.setTimeout( refresh, 900 );
			}
		} );

		if ( document.querySelector( '.wc-block-mini-cart__footer' ) ) {
			refresh();
		}
	}

	ready( function () {
		initHeader();
		initMarquees();
		initStickyAddToCart();
		initFreeShipping();
	} );
} )();
