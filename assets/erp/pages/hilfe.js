/*
 * ERP-Seite „Anleitungen“ (nativ): Reiter für die Teile, die Anleitung selbst
 * erscheint im Rahmen (eigenes Dokument des Plugins mit eigenem Stylesheet).
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};
	UI.routes = UI.routes || {};

	UI.pages['anleitungen'] = function (item, ui) {
		var h = ui.h;
		ui.pageHead('Anleitungen', 'Die Betriebsanleitung zum Lesen.', ui.classicBtn(item));
		ui.view().classList.add('broad');

		var box = h('div', { class: 'card' }, 'Wird geladen …');
		ui.view().appendChild(box);

		ui.api('anleitungen').then(function (d) {
			box.textContent = '';
			box.className = '';

			if (d.missing) { box.className = 'notice warn'; box.textContent = d.missing; return; }

			var tabs = h('div', { class: 'presets hl-tabs', role: 'tablist' });
			var hint = h('p', { class: 'muted', style: 'margin:8px 0' });
			var frameBox = h('div', { class: 'hl-frame-box' });
			var open = h('a', { class: 'btn', target: '_blank', rel: 'noopener' }, ui.ico('ext'), 'In eigenem Tab öffnen (zum Drucken)');

			function show(part) {
				Array.prototype.forEach.call(tabs.children, function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-id') === part.id ? 'true' : 'false'); });
				hint.textContent = part.hint;
				open.href = part.url;
				frameBox.textContent = '';
				frameBox.appendChild(h('iframe', { class: 'hl-frame', src: part.url, title: part.label, loading: 'lazy' }));
			}

			d.parts.forEach(function (p) {
				tabs.appendChild(h('button', { class: 'chip', type: 'button', 'data-id': p.id, 'aria-pressed': 'false', onclick: function () { show(p); } }, p.label));
			});

			box.appendChild(tabs);
			box.appendChild(hint);
			box.appendChild(h('div', { style: 'margin-bottom:10px' }, open));
			box.appendChild(frameBox);
			if (d.parts.length) { show(d.parts[0]); }
		}).catch(function (e) { box.className = 'notice warn'; box.textContent = e.message; });
	};
})();
