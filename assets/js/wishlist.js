/* Lindenweg16 ERP – Wunschliste */
( function ( $ ) {
	'use strict';
	$( document ).on( 'click', '.lw16-wishlist-btn', function () {
		var btn = $( this );
		$.post( LW16Wishlist.ajaxUrl, {
			action: 'lw16_wishlist_toggle',
			nonce: LW16Wishlist.nonce,
			product: btn.data( 'product' )
		}, function ( res ) {
			if ( ! res || ! res.success ) { return; }
			var active = res.data.active;
			btn.attr( 'aria-pressed', active ? 'true' : 'false' );
			btn.find( '.lw16-heart' ).text( active ? '♥' : '♡' );
			if ( ! active && btn.closest( '.lw16-wishlist-card' ).length ) {
				btn.closest( '.lw16-wishlist-card' ).fadeOut();
			}
		} );
	} );
} )( jQuery );
