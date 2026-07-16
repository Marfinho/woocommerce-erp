(function () {
	'use strict';

	var burger = document.querySelector('.lw16-header__burger');
	var header = document.querySelector('.lw16-header');

	if (burger && header) {
		burger.addEventListener('click', function () {
			header.classList.toggle('menu-open');
		});
	}

	// Update cart count via AJAX fragment
	if (typeof jQuery !== 'undefined') {
		jQuery(document.body).on('added_to_cart removed_from_cart updated_cart_totals', function () {
			var cartLink = document.querySelector('.lw16-header__cart');
			if (!cartLink) return;
			jQuery.get(window.location.href, function (html) {
				var doc = new DOMParser().parseFromString(html, 'text/html');
				var newCart = doc.querySelector('.lw16-header__cart');
				if (newCart) cartLink.innerHTML = newCart.innerHTML;
			});
		});
	}
})();
