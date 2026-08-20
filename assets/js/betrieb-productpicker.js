/**
 * Suchbares Auswahlfeld für WooCommerce-Produkte.
 *
 * Bewusst ohne Fremdbibliothek und ohne AJAX: Der Produktkatalog wird einmal
 * mit der Seite ausgeliefert und im Browser gefiltert. Das ist bei einem
 * Sortiment dieser Größe schneller als jede Abfrage und funktioniert
 * unabhängig davon, welche WooCommerce-Rechte der angemeldete Benutzer hat –
 * der eingebaute Produktsucher von WooCommerce verlangt Rechte zum Bearbeiten
 * von Produkten, die eine Betriebsrolle nicht haben soll.
 */
( function () {
	'use strict';

	var MAX_VISIBLE = 12;

	function normalize( text ) {
		return String( text )
			.toLowerCase()
			.replace( /ä/g, 'ae' )
			.replace( /ö/g, 'oe' )
			.replace( /ü/g, 'ue' )
			.replace( /ß/g, 'ss' );
	}

	function matches( product, needle ) {
		if ( ! needle ) {
			return true;
		}

		// Jedes Wort muss irgendwo vorkommen - so findet "hak anth" auch
		// "Haster Haken anthrazit", egal in welcher Reihenfolge getippt wird.
		return needle.split( /\s+/ ).every( function ( part ) {
			return product.haystack.indexOf( part ) !== -1;
		} );
	}

	function setup( root ) {
		var products = JSON.parse( root.dataset.products || '[]' );
		var input = root.querySelector( '.lw16-picker__input' );
		var hidden = root.querySelector( '.lw16-picker__value' );
		var list = root.querySelector( '.lw16-picker__list' );
		var clear = root.querySelector( '.lw16-picker__clear' );
		var active = -1;

		products.forEach( function ( product ) {
			product.haystack = normalize( product.label + ' ' + product.sku + ' ' + product.id );
		} );

		function close() {
			list.hidden = true;
			active = -1;
		}

		function choose( product ) {
			hidden.value = product ? product.id : '';
			input.value = product ? product.label : '';
			clear.hidden = ! product;
			close();
		}

		function render( needle ) {
			var found = products.filter( function ( product ) {
				return matches( product, needle );
			} );

			list.textContent = '';

			if ( ! found.length ) {
				var empty = document.createElement( 'li' );
				empty.className = 'lw16-picker__empty';
				empty.textContent = root.dataset.empty || '';
				list.appendChild( empty );
				list.hidden = false;
				return;
			}

			found.slice( 0, MAX_VISIBLE ).forEach( function ( product, index ) {
				var item = document.createElement( 'li' );
				item.className = 'lw16-picker__item';
				item.setAttribute( 'role', 'option' );
				item.dataset.index = index;

				var name = document.createElement( 'span' );
				name.className = 'lw16-picker__label';
				name.textContent = product.label;

				var meta = document.createElement( 'small' );
				meta.textContent = ( product.sku ? product.sku + ' · ' : '' ) + '#' + product.id;

				item.appendChild( name );
				item.appendChild( meta );

				// mousedown statt click: Der Blur des Eingabefelds wuerde die
				// Liste sonst schliessen, bevor der Klick ankommt.
				item.addEventListener( 'mousedown', function ( event ) {
					event.preventDefault();
					choose( product );
				} );

				list.appendChild( item );
			} );

			if ( found.length > MAX_VISIBLE ) {
				var more = document.createElement( 'li' );
				more.className = 'lw16-picker__empty';
				more.textContent = ( root.dataset.more || '' ).replace( '%d', found.length - MAX_VISIBLE );
				list.appendChild( more );
			}

			list.hidden = false;
			active = -1;
		}

		function highlight( delta ) {
			var items = list.querySelectorAll( '.lw16-picker__item' );

			if ( ! items.length ) {
				return;
			}

			active = ( active + delta + items.length ) % items.length;

			items.forEach( function ( item, index ) {
				item.classList.toggle( 'is-active', index === active );
			} );
			items[ active ].scrollIntoView( { block: 'nearest' } );
		}

		input.addEventListener( 'input', function () {
			hidden.value = '';
			clear.hidden = ! input.value;
			render( normalize( input.value.trim() ) );
		} );

		input.addEventListener( 'focus', function () {
			render( normalize( input.value.trim() ) );
		} );

		input.addEventListener( 'blur', function () {
			window.setTimeout( close, 120 );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowDown' === event.key ) {
				event.preventDefault();
				highlight( 1 );
			} else if ( 'ArrowUp' === event.key ) {
				event.preventDefault();
				highlight( -1 );
			} else if ( 'Enter' === event.key ) {
				var current = list.querySelector( '.lw16-picker__item.is-active' );
				if ( current ) {
					event.preventDefault();
					var visible = products.filter( function ( product ) {
						return matches( product, normalize( input.value.trim() ) );
					} );
					choose( visible[ parseInt( current.dataset.index, 10 ) ] );
				}
			} else if ( 'Escape' === event.key ) {
				close();
			}
		} );

		clear.addEventListener( 'click', function () {
			choose( null );
			input.focus();
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.lw16-picker' ).forEach( setup );
	} );
}() );
