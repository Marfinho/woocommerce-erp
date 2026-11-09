/*
 * Produktseite: Galerie je Variante.
 *
 * Der Server legt in window.LW16_VARIANT_GALLERY je Variante fest, welche
 * Bildfolge sie zeigt (sets[0] ist die Galerie des Produkts). Gewählt wird
 * über den Block „In den Warenkorb mit Optionen“ (Schaltflächen mit
 * role="radio" und aria-checked) oder ein klassisches Formular (select).
 * Bei jeder Änderung wird die passende Folge gesucht und die Galerie
 * ersetzt; WooCommerce startet sie neu (Slider, Zoom, Lightbox).
 *
 * Klassisches ES5, keine Abhängigkeit außer jQuery und wc-single-product.
 */
(function () {
	'use strict';

	var D = window.LW16_VARIANT_GALLERY;
	if (!D || !D.sets || !D.variations) { return; }

	var current = 0;
	var timer = null;

	// Gewählte Ausprägungen: attribute_pa_farbe => weiss.
	function selected() {
		var out = {};

		Array.prototype.forEach.call(document.querySelectorAll('[role="radio"][id^="attribute_"][aria-checked="true"]'), function (b) {
			var value = b.value || b.getAttribute('value') || '';
			var suffix = '-' + value;
			if (value && b.id.slice(-suffix.length) === suffix) {
				out[b.id.slice(0, -suffix.length)] = value;
			}
		});

		Array.prototype.forEach.call(document.querySelectorAll('select[name^="attribute_"], select[id^="attribute_"]'), function (s) {
			var key = s.getAttribute('name') || s.id;
			if (s.value) { out[key] = s.value; }
		});

		return out;
	}

	// Die Bildfolge der gewählten Variante; 0 = Galerie des Produkts.
	function setFor(sel) {
		for (var i = 0; i < D.variations.length; i++) {
			var v = D.variations[i];
			var ok = true;

			for (var key in v.attrs) {
				if (!Object.prototype.hasOwnProperty.call(v.attrs, key)) { continue; }
				var want = String(v.attrs[key] || '');
				if (sel[key] === undefined || (want !== '' && sel[key] !== want)) { ok = false; break; }
			}

			if (ok) { return v.set; }
		}

		return 0;
	}

	function show(index) {
		if (index === current || D.sets[index] === undefined) { return; }

		var old = document.querySelector('.woocommerce-product-gallery');
		if (!old) { return; }

		var columns = old.getAttribute('data-columns') || '4';
		var el = document.createElement('div');
		el.className = 'woocommerce-product-gallery woocommerce-product-gallery--with-images woocommerce-product-gallery--columns-' + columns + ' images';
		el.setAttribute('data-columns', columns);
		el.style.opacity = '0';
		el.style.transition = 'opacity .25s ease-in-out';

		var wrapper = document.createElement('div');
		wrapper.className = 'woocommerce-product-gallery__wrapper';
		// Markup von wc_get_gallery_image_html() auf dem Server, bereits maskiert.
		wrapper.innerHTML = D.sets[index];
		el.appendChild(wrapper);

		old.parentNode.replaceChild(el, old);
		current = index;

		var $ = window.jQuery;
		if ($ && $.fn && $.fn.wc_product_gallery && window.wc_single_product_params) {
			$(el).wc_product_gallery(window.wc_single_product_params);
			// Falls der Start die Galerie nicht selbst einblendet.
			setTimeout(function () { el.style.opacity = '1'; }, 400);
		} else {
			el.style.opacity = '1';
		}
	}

	function update() {
		timer = null;
		show(setFor(selected()));
	}

	function later() {
		if (timer) { clearTimeout(timer); }
		timer = setTimeout(update, 30);
	}

	function start() {
		var form = document.querySelector('.wc-block-add-to-cart-with-options, form.variations_form') || document.body;

		if (window.MutationObserver) {
			new MutationObserver(later).observe(form, { attributes: true, subtree: true, attributeFilter: ['aria-checked'] });
		}
		form.addEventListener('change', later);
		form.addEventListener('click', later);

		// Die vorgewählte Variante gleich zeigen – nachdem WooCommerce die
		// Galerie selbst gestartet hat.
		setTimeout(update, 60);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
}());
