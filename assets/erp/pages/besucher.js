/*
 * ERP-Seite „Besucher jetzt“ (nativ): wie viele Personen gerade auf der Website
 * sind und auf welchen Seiten. Fragt alle 10 Sekunden nach. Die Zahlen kommen
 * ohne Cookie und ohne IP-Adresse zustande (includes/Erp/Presence.php); gezählt
 * werden geöffnete Seiten, nicht Menschen. Klassisches ES5, Daten nur als Text.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};
	UI.routes = UI.routes || {};

	var SECTIONS = { start: 'Start', shop: 'Shop', warenkorb: 'Warenkorb', kasse: 'Kasse', inhalt: 'Inhalt' };

	function dauer(s) {
		if (s < 60) { return 'unter 1 Min.'; }
		return Math.round(s / 60) + ' Min.';
	}

	UI.pages['besucher'] = function (item, ui) {
		var h = ui.h;
		ui.pageHead('Besucher jetzt', 'Wer gerade auf der Website ist und wo – anonym, ohne Cookie und ohne Einwilligungsbanner.', ui.classicBtn(item));

		var box = h('div');
		ui.view().appendChild(box);

		function paint(d) {
			box.textContent = '';

			var grid = h('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:14px' });
			function big(label, value, sub) {
				return h('div', { class: 'card' },
					h('div', { class: 'muted', style: 'font-size:12.5px' }, label),
					h('div', { style: 'font-size:34px;font-weight:700;line-height:1.2' }, String(value)),
					sub ? h('div', { class: 'muted', style: 'font-size:12.5px' }, sub) : null);
			}
			grid.appendChild(big('Jetzt online', d.online, d.online === 1 ? 'offene Seite' : 'offene Seiten'));
			grid.appendChild(big('Im Warenkorb / Kasse', d.sections.warenkorb + d.sections.kasse, d.sections.kasse + ' in der Kasse'));
			grid.appendChild(big('Am Handy', d.devices.m, d.devices.d + ' am Rechner'));
			box.appendChild(grid);

			if (!d.online) {
				box.appendChild(h('div', { class: 'card' },
					h('p', null, 'Gerade ist niemand auf der Website.'),
					h('p', { class: 'muted' }, 'Nicht gezählt werden angemeldete Personen (auch du selbst, wenn du im Shop angemeldet bist – zum Testen ein privates Fenster nehmen), Suchmaschinen und Browser mit „Do Not Track“. Hinweis: Eine Seite zählt, bis sie 90 Sekunden nichts mehr gemeldet hat.')));
				return;
			}

			var pages = h('table', { class: 'list' });
			pages.appendChild(h('thead', null, h('tr', null, h('th', null, 'Seite'), h('th', null, 'Bereich'), h('th', { class: 'num' }, 'Jetzt offen'))));
			var pb = h('tbody');
			d.pages.forEach(function (p) {
				pb.appendChild(h('tr', null,
					h('td', null, h('b', null, p.title || p.path), p.title ? h('div', { class: 'muted', style: 'font-size:12.5px' }, p.path) : null),
					h('td', null, SECTIONS[p.section] || p.section),
					h('td', { class: 'num' }, String(p.count))));
			});
			pages.appendChild(pb);
			box.appendChild(h('div', { class: 'card', style: 'margin-bottom:14px' }, h('h3', null, 'Auf diesen Seiten'), pages));

			var who = h('table', { class: 'list' });
			who.appendChild(h('thead', null, h('tr', null, h('th', null, 'Seite'), h('th', null, 'Seit'), h('th', null, 'Gerät'), h('th', null, 'Gekommen von'))));
			var wb = h('tbody');
			d.visitors.forEach(function (v) {
				wb.appendChild(h('tr', null,
					h('td', null, v.title || v.path),
					h('td', null, dauer(v.since)),
					h('td', null, v.dev === 'm' ? 'Handy' : (v.dev === 'd' ? 'Rechner' : '–')),
					h('td', null, v.ref || '–')));
			});
			who.appendChild(wb);
			box.appendChild(h('div', { class: 'card', style: 'margin-bottom:14px' }, h('h3', null, 'Offene Seiten im Einzelnen'), who,
				h('p', { class: 'muted', style: 'margin-top:8px' }, 'Eine Zeile ist eine geöffnete Seite, kein wiedererkannter Mensch: Jeder Seitenaufruf bekommt eine neue Zufallsnummer, die nur im Tab lebt. „Seit“ gilt deshalb für diese eine Seite. „Gekommen von“ steht nur beim ersten Aufruf aus einer fremden Seite (Suchmaschine, soziales Netz); sonst bleibt es leer.')));

			if (d.refs.length) {
				var refs = h('table', { class: 'list' });
				refs.appendChild(h('thead', null, h('tr', null, h('th', null, 'Herkunft'), h('th', { class: 'num' }, 'Offene Seiten'))));
				var rb = h('tbody');
				d.refs.forEach(function (r) { rb.appendChild(h('tr', null, h('td', null, r.host), h('td', { class: 'num' }, String(r.count)))); });
				refs.appendChild(rb);
				box.appendChild(h('div', { class: 'card' }, h('h3', null, 'Woher sie kommen'), refs));
			}
		}

		function load() {
			return ui.api('besucher').then(paint).catch(function (e) {
				box.textContent = '';
				box.appendChild(h('div', { class: 'notice warn' }, e.message || 'Nicht erreichbar.'));
			});
		}

		load();
		ui.every(load, 10000);
	};
})();
