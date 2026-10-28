/* Lindenweg16 ERP – Prämienpunkte im Block-Warenkorb und in der Block-Kasse.
 * Unter der Summe: Punkte einlösen (Guthaben und Punkte, für angemeldete
 * Kunden) und wie viele Punkte die Bestellung bringt. Die Daten kommen aus der
 * Store-API (extensions.lw16, LoyaltyCart.php); das Einlösen geht über
 * extensionCartUpdate, danach rechnet WooCommerce den Warenkorb neu. */
( function () {
	'use strict';
	var wp = window.wp;
	var wc = window.wc;
	if ( ! wp || ! wp.plugins || ! wp.element || ! wc || ! wc.blocksCheckout || ! wc.blocksCheckout.ExperimentalOrderMeta ) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;

	function money( value, currency ) {
		try {
			return new Intl.NumberFormat( 'de-DE', { style: 'currency', currency: currency || 'EUR' } ).format( value );
		} catch ( e ) {
			return value.toFixed( 2 ).replace( '.', ',' ) + ' €';
		}
	}

	function num( value ) {
		return new Intl.NumberFormat( 'de-DE' ).format( value );
	}

	function Redeem( props ) {
		var r = ( props.extensions && props.extensions.lw16 && props.extensions.lw16.redeem ) || {};
		var credit = useState( !! r.use_credit );
		var points = useState( String( r.use_points || '' ) );
		var busy = useState( false );
		var error = useState( '' );

		// Nach dem Neurechnen zeigt der Server, was wirklich gilt.
		useEffect( function () {
			credit[ 1 ]( !! r.use_credit );
			points[ 1 ]( r.use_points ? String( r.use_points ) : '' );
		}, [ r.use_credit, r.use_points ] );

		if ( ! r.available || ! wc.blocksCheckout.extensionCartUpdate ) {
			return null;
		}

		var step = r.step || 100;
		var wanted = Math.max( 0, Math.min( r.points_balance, parseInt( points[ 0 ], 10 ) || 0 ) );
		var applied = r.use_credit || r.use_points > 0;

		function send( data ) {
			busy[ 1 ]( true );
			error[ 1 ]( '' );
			wc.blocksCheckout.extensionCartUpdate( { namespace: 'lw16-redeem', data: data } )
				.catch( function ( e ) { error[ 1 ]( ( e && e.message ) || 'Das hat nicht geklappt. Bitte noch einmal versuchen.' ); } )
				.then( function () { busy[ 1 ]( false ); } );
		}

		var rows = [];
		if ( r.credit_balance > 0 ) {
			rows.push( el( 'label', { key: 'c', className: 'lw16-redeem-box__check' },
				el( 'input', { type: 'checkbox', checked: credit[ 0 ], disabled: busy[ 0 ], onChange: function ( e ) { credit[ 1 ]( e.target.checked ); } } ),
				el( 'span', null, 'Guthaben einsetzen (' + money( r.credit_balance, r.currency ) + ' verfügbar)' )
			) );
		}
		if ( r.points_balance > 0 ) {
			rows.push( el( 'div', { key: 'p', className: 'lw16-redeem-box__points' },
				el( 'label', { htmlFor: 'lw16-redeem-points' }, 'Punkte einlösen (du hast ' + num( r.points_balance ) + ')' ),
				el( 'div', { className: 'lw16-redeem-box__row' },
					el( 'input', {
						id: 'lw16-redeem-points', type: 'number', inputMode: 'numeric', min: 0, max: r.points_balance, step: step,
						value: points[ 0 ], disabled: busy[ 0 ], placeholder: '0',
						onChange: function ( e ) { points[ 1 ]( e.target.value ); }
					} ),
					el( 'button', { type: 'button', className: 'lw16-redeem-box__all', disabled: busy[ 0 ], onClick: function () { points[ 1 ]( String( r.points_balance ) ); } }, 'Alle' )
				),
				el( 'small', null, '= ' + money( Math.floor( wanted / step * 100 ) / 100, r.currency ) + ' Rabatt · ' + num( step ) + ' Punkte = 1 €' )
			) );
		}

		return el( 'div', { className: 'lw16-redeem-box' },
			el( 'p', { className: 'lw16-redeem-box__title' }, 'Punkte und Guthaben einlösen' ),
			rows,
			el( 'div', { className: 'lw16-redeem-box__actions' },
				el( 'button', {
					type: 'button', className: 'lw16-redeem-box__apply', disabled: busy[ 0 ],
					onClick: function () { send( { credit: credit[ 0 ] ? 1 : 0, points: wanted } ); }
				}, busy[ 0 ] ? 'Wird berechnet …' : 'Anwenden' ),
				applied ? el( 'button', {
					type: 'button', className: 'lw16-redeem-box__remove', disabled: busy[ 0 ],
					onClick: function () { send( { credit: 0, points: 0 } ); }
				}, 'Nicht einlösen' ) : null
			),
			error[ 0 ] ? el( 'p', { className: 'lw16-redeem-box__error', role: 'alert' }, error[ 0 ] ) : null
		);
	}

	function Points( props ) {
		var d = props.extensions && props.extensions.lw16;
		if ( ! d || ! d.points ) {
			return null;
		}
		return el( 'div', { className: 'lw16-cart-points' },
			el( 'span', { className: 'lw16-cart-points__main' }, d.text ),
			d.detail ? el( 'small', null, d.detail ) : null
		);
	}

	function Box( props ) {
		return el( wp.element.Fragment, null, el( Redeem, props ), el( Points, props ) );
	}

	wp.plugins.registerPlugin( 'lw16-praemienpunkte', {
		scope: 'woocommerce-checkout',
		render: function () {
			return el( wc.blocksCheckout.ExperimentalOrderMeta, null, el( Box ) );
		}
	} );
} )();
