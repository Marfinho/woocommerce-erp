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

		var tabs = h('div', { style: 'display:flex;gap:8px;margin-bottom:14px' });
		var box = h('div');
		var wege = h('div', { style: 'display:none' });
		var btnJetzt = h('button', { class: 'pill on', type: 'button' }, 'Jetzt');
		var btnWege = h('button', { class: 'pill', type: 'button' }, 'Wege');
		tabs.appendChild(btnJetzt);
		tabs.appendChild(btnWege);
		ui.view().appendChild(tabs);
		ui.view().appendChild(box);
		ui.view().appendChild(wege);

		function show(w) {
			box.style.display = w ? 'none' : '';
			wege.style.display = w ? '' : 'none';
			btnJetzt.className = 'pill' + (w ? '' : ' on');
			btnWege.className = 'pill' + (w ? ' on' : '');
			if (w) { loadWege(); }
		}
		btnJetzt.addEventListener('click', function () { show(false); });
		btnWege.addEventListener('click', function () { show(true); });

		var days = 30;
		var seite = '';

		function label(p) {
			if (p === '') { return 'Direkt / Neuladen'; }
			if (p.charAt(0) === '@') { return p.slice(1); }
			return p;
		}

		function list(title, rows, empty) {
			var t = h('table', { class: 'list' });
			var b = h('tbody');
			rows.forEach(function (r) {
				var name = h('td', null, r.path.charAt(0) === '/' ? h('a', { href: '#', 'data-p': r.path }, label(r.path)) : label(r.path));
				b.appendChild(h('tr', null, name, h('td', { class: 'num' }, String(r.count))));
			});
			t.appendChild(b);
			var card = h('div', { class: 'card' }, h('h3', null, title), rows.length ? t : h('p', { class: 'muted' }, empty));
			Array.prototype.forEach.call(card.querySelectorAll('a[data-p]'), function (a) {
				a.addEventListener('click', function (e) { e.preventDefault(); seite = a.getAttribute('data-p'); loadWege(); });
			});
			return card;
		}

		function paintWege(d) {
			wege.textContent = '';

			var bar = h('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px' });
			[7, 30, 90].forEach(function (n) {
				var b = h('button', { class: 'pill' + (n === d.days ? ' on' : ''), type: 'button' }, n + ' Tage');
				b.addEventListener('click', function () { days = n; loadWege(); });
				bar.appendChild(b);
			});
			var sel = h('select', { class: 'field', 'aria-label': 'Seite wählen', style: 'max-width:22em' }, h('option', { value: '' }, '— alle Wege —'));
			d.pages.forEach(function (p) { sel.appendChild(h('option', { value: p.path, selected: p.path === d.page }, p.path + ' (' + p.count + ')')); });
			sel.addEventListener('change', function () { seite = sel.value; loadWege(); });
			bar.appendChild(sel);
			wege.appendChild(bar);

			if (d.page) {
				var two = h('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px' });
				two.appendChild(list('Davor: wo kommen sie her?', d.before, 'Zu wenige Fälle (unter 3).'));
				two.appendChild(list('Danach: wohin gehen sie?', d.after, 'Zu wenige Fälle (unter 3).'));
				wege.appendChild(h('h3', { style: 'margin:0 0 8px' }, d.page));
				wege.appendChild(two);
			} else {
				var cart = d.cart.in
					? h('div', { class: 'card', style: 'margin-bottom:14px' }, h('h3', null, 'Warenkorb'),
						h('p', null, d.cart.in + ' Aufrufe des Warenkorbs, davon ' + d.cart.to_kasse + ' weiter zur Kasse (' + Math.round(d.cart.to_kasse / d.cart.in * 100) + ' %).'),
						h('p', { class: 'muted' }, 'Die Differenz sind Aufrufe, nach denen niemand zur Kasse ging oder die Kasse über einen anderen Weg erreicht wurde.'))
					: null;
				if (cart) { wege.appendChild(cart); }

				var t = h('table', { class: 'list' });
				t.appendChild(h('thead', null, h('tr', null, h('th', null, 'Von'), h('th', null, 'Nach'), h('th', { class: 'num' }, 'Fälle'))));
				var tb = h('tbody');
				d.transitions.forEach(function (r) { tb.appendChild(h('tr', null, h('td', null, r.from), h('td', null, r.to), h('td', { class: 'num' }, String(r.count)))); });
				t.appendChild(tb);
				wege.appendChild(h('div', { class: 'card', style: 'margin-bottom:14px' }, h('h3', null, 'Häufigste Wege'),
					d.transitions.length ? t : h('p', { class: 'muted' }, 'Noch zu wenig Daten: Wege erscheinen ab 3 gleichen Fällen.'),
					d.hidden ? h('p', { class: 'muted', style: 'margin-top:8px' }, d.hidden + ' weitere Aufrufe verteilen sich auf seltene Wege (unter 3 Fälle) und werden nicht einzeln gezeigt.') : null));

				var two2 = h('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-bottom:14px' });
				two2.appendChild(list('Einstiegsseiten', d.entries, 'Noch keine Daten.'));
				two2.appendChild(list('Gekommen von (fremde Seiten)', d.sources, 'Noch keine Daten.'));
				wege.appendChild(two2);
			}

			wege.appendChild(h('p', { class: 'muted' }, 'Gezählt wird nur jeder einzelne Schritt von einer Seite zur nächsten, je Tag. Es wird nicht gespeichert, welche Aufrufe zusammengehören – ein ganzer Weg einer Person lässt sich daraus nicht rekonstruieren. Einstieg heißt: direkt, Lesezeichen oder neu geladen. Aufbewahrung 90 Tage.'));
		}

		function loadWege() {
			return ui.api('besucher/wege?tage=' + days + (seite ? '&seite=' + encodeURIComponent(seite) : '')).then(paintWege).catch(function (e) {
				wege.textContent = '';
				wege.appendChild(h('div', { class: 'notice warn' }, e.message || 'Nicht erreichbar.'));
			});
		}

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
