/* Lindenweg16 ERP – Prämienpunkte im Block-Warenkorb und in der Block-Kasse.
 * Die Zahl kommt aus der Store-API (extensions.lw16, LoyaltyCart.php) und
 * steht unter der Summe; sie rechnet sich bei jeder Änderung des Warenkorbs neu. */
( function () {
	'use strict';
	var wp = window.wp;
	var wc = window.wc;
	if ( ! wp || ! wp.plugins || ! wp.element || ! wc || ! wc.blocksCheckout || ! wc.blocksCheckout.ExperimentalOrderMeta ) {
		return;
	}
	var el = wp.element.createElement;

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

	wp.plugins.registerPlugin( 'lw16-praemienpunkte', {
		scope: 'woocommerce-checkout',
		render: function () {
			return el( wc.blocksCheckout.ExperimentalOrderMeta, null, el( Points ) );
		}
	} );
} )();
