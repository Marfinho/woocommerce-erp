/*
 * Live-Dashboard für den Vollbildschirm (#/live, Kiosk-Ansicht mit #/live/tv).
 * Zeigt Verkauf heute, Kennzahlen, Produktion und Drucker, Kampagnen und
 * Seitenaufrufe; jeder Block nur, wenn die Person ihn sehen darf. Fragt alle
 * 15 Sekunden nach, hält den Bildschirm wach und behält bei einem Verbindungs-
 * abbruch den letzten Stand. Server: includes/Erp/Pages/Live.php.
 * Klassisches ES5, Daten nur als Text.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.routes = UI.routes || {};

	var SVGNS = 'http://www.w3.org/2000/svg';
	var wake = null;

	function h() { return UI.h.apply(null, arguments); }

	function eur(v, d) { return Number(v).toLocaleString('de-DE', { style: 'currency', currency: 'EUR', minimumFractionDigits: d === undefined ? 0 : d, maximumFractionDigits: d === undefined ? 0 : d }); }

	function int(v) { return Number(v).toLocaleString('de-DE', { maximumFractionDigits: 0 }); }

	function pct(now, before) {
		if (!before) { return null; }
		return Math.round((now - before) / before * 100);
	}

	function svg(tag, attrs) {
		var el = document.createElementNS(SVGNS, tag);
		Object.keys(attrs || {}).forEach(function (k) { el.setAttribute(k, attrs[k]); });
		for (var i = 2; i < arguments.length; i++) { if (arguments[i]) { el.appendChild(arguments[i]); } }
		return el;
	}

	function delta(now, before, label) {
		var p = pct(now, before);
		if (p === null) { return h('span', { class: 'lv-delta' }, 'gestern: ' + label); }
		return h('span', { class: 'lv-delta ' + (p >= 0 ? 'up' : 'down') }, (p >= 0 ? '▲ ' : '▼ ') + Math.abs(p) + ' % zu gestern (' + label + ')');
	}

	// Balken je Stunde, die laufende hervorgehoben.
	function bars(values, current, color) {
		var max = Math.max.apply(null, values.concat([1]));
		var w = 480, hh = 110, bw = w / values.length;
		var root = svg('svg', { viewBox: '0 0 ' + w + ' ' + (hh + 22), class: 'lv-chart', role: 'img', 'aria-label': 'Verlauf nach Stunde' });
		values.forEach(function (v, i) {
			var bh = Math.max(v > 0 ? 3 : 1, v / max * hh);
			root.appendChild(svg('rect', { x: i * bw + 2, y: hh - bh, width: bw - 4, height: bh, rx: 3, class: 'bar' + (i === current ? ' now' : '') + (i > current ? ' future' : ''), fill: color }));
			if (i % 3 === 0) {
				var t = svg('text', { x: i * bw + bw / 2, y: hh + 16, 'text-anchor': 'middle', class: 'ax' });
				t.textContent = String(i);
				root.appendChild(t);
			}
		});
		return root;
	}

	function line(series) {
		var vals = series.map(function (s) { return s.views; });
		var max = Math.max.apply(null, vals.concat([1]));
		var w = 480, hh = 110, step = w / (vals.length - 1);
		var d = '';
		vals.forEach(function (v, i) { d += (i ? 'L' : 'M') + (i * step).toFixed(1) + ' ' + (hh - v / max * (hh - 8) - 4).toFixed(1) + ' '; });
		var area = d + 'L' + w + ' ' + hh + ' L0 ' + hh + ' Z';
		var root = svg('svg', { viewBox: '0 0 ' + w + ' ' + (hh + 22), class: 'lv-chart', role: 'img', 'aria-label': 'Seitenaufrufe der letzten 24 Stunden' },
			svg('path', { d: area, class: 'area' }), svg('path', { d: d, class: 'ln', fill: 'none' }));
		[0, 6, 12, 18, 23].forEach(function (i) {
			var t = svg('text', { x: Math.min(w - 14, Math.max(14, i * step)), y: hh + 16, 'text-anchor': 'middle', class: 'ax' });
			t.textContent = new Date(series[i].ts * 1000).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
			root.appendChild(t);
		});
		return root;
	}

	function card(title, cls) {
		var body = h('div', { class: 'lv-body' });
		var el = h('section', { class: 'lv-card ' + (cls || '') }, h('h3', null, title), body);
		return { el: el, body: body };
	}

	function big(value, label, sub) {
		return h('div', { class: 'lv-big' }, h('div', { class: 'v' }, value), h('div', { class: 'l' }, label), sub || null);
	}

	UI.routes['live'] = function (r, ui) {
		var kiosk = r.id === 'tv';
		var app = ui.app();
		var root = h('div', { class: 'lv' });
		var data = null;
		var failed = '';
		var seen = {};
		var armed = false; // erst nach dem ersten Stand fällt eine neue Bestellung auf
		var fetched = 0;
		var clock = h('div', { class: 'lv-clock' }, '');
		var date = h('div', { class: 'lv-date' }, '');
		var state = h('div', { class: 'lv-state' }, '');
		var grid = h('div', { class: 'lv-grid' });

		ui.setHead('Live', ui.boot().shop.name);
		if (kiosk) { app.classList.add('kiosk'); }

		function fullscreen() {
			app.classList.add('kiosk');
			var el = document.documentElement;
			if (el.requestFullscreen) { el.requestFullscreen().catch(function () {}); }
			keepAwake();
		}

		function leave() {
			app.classList.remove('kiosk');
			if (document.fullscreenElement && document.exitFullscreen) { document.exitFullscreen().catch(function () {}); }
			if (wake) { wake.release().catch(function () {}); wake = null; }
		}

		function keepAwake() {
			if (!navigator.wakeLock || wake) { return; }
			navigator.wakeLock.request('screen').then(function (l) { wake = l; l.addEventListener('release', function () { wake = null; }); }).catch(function () {});
		}

		root.appendChild(h('header', { class: 'lv-head' },
			h('div', null, h('div', { class: 'lv-shop' }, ui.boot().shop.name), date),
			clock,
			h('div', { class: 'lv-tools' }, state,
				h('button', { class: 'btn', type: 'button', onclick: fullscreen }, 'Vollbild'),
				h('a', { class: 'btn', href: '#/start', onclick: leave }, 'Beenden'))));
		root.appendChild(grid);
		ui.view().appendChild(root);

		function tick() {
			var n = new Date();
			clock.textContent = n.toLocaleTimeString('de-DE');
			date.textContent = n.toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
			var age = Math.round((Date.now() - fetched) / 1000);
			state.className = 'lv-state' + (failed ? ' bad' : (age > 45 ? ' stale' : ''));
			state.textContent = failed ? 'Keine Verbindung – Stand ' + new Date(fetched).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) : (fetched ? '● live · vor ' + age + ' s' : 'lädt …');
		}

		function sales(b) {
			var c = card('Verkauf heute', 'wide');
			if (!b.available) { c.body.appendChild(h('p', { class: 'muted' }, 'WooCommerce ist nicht aktiv.')); return c.el; }
			var left = h('div', { class: 'lv-cols' },
				h('div', null, big(eur(b.revenue), 'Umsatz', delta(b.revenue, b.revenue_yday, eur(b.revenue_yday))), big(int(b.orders), b.orders === 1 ? 'Bestellung' : 'Bestellungen', delta(b.orders, b.orders_yday, int(b.orders_yday)))),
				h('div', null, big(b.basket === null ? '–' : eur(b.basket, 2), 'Ø Warenkorb'), big(int(b.to_ship), 'zu versenden', null)));
			c.body.appendChild(left);
			c.body.appendChild(bars(b.hours, b.hour_now, 'var(--lv-accent)'));
			var list = h('ul', { class: 'lv-orders' });
			b.last.forEach(function (o) {
				var fresh = armed && !seen[o.number];
				seen[o.number] = true;
				list.appendChild(h('li', { class: fresh ? 'new' : '' }, h('b', null, '#' + o.number), h('span', null, new Date(o.ts * 1000).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })), h('b', null, eur(o.total, 2))));
			});
			c.body.appendChild(list);
			return c.el;
		}

		function traffic(b) {
			var c = card('Seitenaufrufe Website', 'wide');
			c.body.appendChild(h('div', { class: 'lv-cols' },
				h('div', null, big(int(b.last30), 'letzte 30 Minuten')),
				h('div', null, big(int(b.today), 'heute', delta(b.today, b.yesterday, int(b.yesterday) + ' gesamt')))));
			c.body.appendChild(line(b.series));
			var max = Math.max.apply(null, Object.keys(b.sections).map(function (k) { return b.sections[k]; }).concat([1]));
			var names = { start: 'Start', shop: 'Shop', warenkorb: 'Warenkorb', kasse: 'Kasse', inhalt: 'Inhalt' };
			var secs = h('div', { class: 'lv-secs' });
			Object.keys(names).forEach(function (k) {
				secs.appendChild(h('div', { class: 'row' }, h('span', null, names[k]), h('div', { class: 'meter' }, h('i', { style: 'width:' + Math.round(b.sections[k] / max * 100) + '%' })), h('b', null, int(b.sections[k]))));
			});
			c.body.appendChild(secs);
			var ago = b.last_ts ? Math.max(0, Math.round((Date.now() / 1000 - b.last_ts) / 60)) : null;
			c.body.appendChild(h('p', { class: 'lv-note' }, ago === null
				? 'Noch kein Aufruf gezählt. Angemeldete Personen (auch du im selben Browser) zählen nicht: Öffne die Website in einem privaten Fenster, um den Zähler zu prüfen.'
				: 'Letzter gezählter Aufruf: vor ' + (ago < 10 ? 'unter 10' : ago) + ' Min.'));
			c.body.appendChild(h('p', { class: 'lv-note' }, 'Aufrufe, nicht Besucher: anonym gezählt, ohne Cookie und Adresse. AR-Ansicht heute: ' + int(b.ar_today) + ' angezeigt.'));
			return c.el;
		}

		function kpis(b) {
			var c = card('Kennzahlen ' + b.label, 'wide');
			var g = h('div', { class: 'lv-kpis' });
			b.rows.forEach(function (k) {
				var m = k.meter;
				g.appendChild(h('div', { class: 'lv-kpi lv-' + (k.level || 'grau') },
					h('div', { class: 'l' }, k.label),
					h('div', { class: 'v' }, k.text),
					k.target ? h('div', { class: 'tgt' }, 'Ziel ' + k.target) : null,
					m && m.value !== undefined && m.value !== null ? h('div', { class: 'meter' }, h('i', { style: 'width:' + Math.round(Math.max(0, Math.min(1, m.value)) * 100) + '%' })) : null));
			});
			c.body.appendChild(g);
			return c.el;
		}

		function production(b) {
			var c = card('Produktion', 'wide');
			c.body.appendChild(h('div', { class: 'lv-runs' },
				big(int(b.runs.freigegeben), 'freigegeben'), big(int(b.runs.im_druck), 'im Druck'), big(int(b.runs.zu_melden), 'zu melden')));
			var pr = h('div', { class: 'lv-printers' });
			b.printers.forEach(function (p) {
				pr.appendChild(h('div', { class: 'lv-printer st-' + (p.online ? p.status : 'offline') },
					h('div', { class: 'row' }, h('b', null, p.name), h('span', null, p.text)),
					p.progress !== null ? h('div', { class: 'meter' }, h('i', { style: 'width:' + p.progress + '%' })) : null,
					h('div', { class: 'sub' }, p.progress !== null ? p.progress + ' %' + (p.remaining !== null ? ' · noch ' + (p.remaining < 60 ? p.remaining + ' min' : Math.floor(p.remaining / 60) + ' h ' + ('0' + (p.remaining % 60)).slice(-2)) : '') : '')));
			});
			if (!b.printers.length) { pr.appendChild(h('p', { class: 'muted' }, 'Kein Drucker angelegt.')); }
			c.body.appendChild(pr);
			b.alerts.forEach(function (a) {
				c.body.appendChild(h('div', { class: 'lv-alert' }, '⚠ ' + a.label + ' wird knapp: ' + (a.days === null ? 'Reichweite unbekannt' : 'reicht noch ' + a.days + ' Tage')));
			});
			return c.el;
		}

		function campaigns(b) {
			var c = card('Kampagnen', 'wide');
			if (!b.rows.length) { c.body.appendChild(h('p', { class: 'muted' }, 'Keine laufende Kampagne.')); return c.el; }
			var t = h('table', { class: 'lv-table' }, h('thead', null, h('tr', null, h('th', null, 'Kampagne'), h('th', { class: 'num' }, 'Kosten'), h('th', { class: 'num' }, 'Umsatz'), h('th', { class: 'num' }, 'je € Einsatz'), h('th', { class: 'num' }, 'Budget'))));
			var tb = h('tbody');
			b.rows.forEach(function (k) {
				tb.appendChild(h('tr', null,
					h('td', null, h('b', null, k.name), h('div', { class: 'sub' }, k.channel + (k.orders ? ' · ' + k.orders + ' Bestellungen' : ''))),
					h('td', { class: 'num' }, eur(k.cost)), h('td', { class: 'num' }, eur(k.revenue)),
					h('td', { class: 'num' }, k.roas === null ? '–' : k.roas.toLocaleString('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '×'),
					h('td', { class: 'num ' + (k.light === 'ueberzogen' ? 'bad' : (k.light === 'knapp' ? 'warn' : '')) }, k.budget === null ? '–' : k.budget + ' %')));
			});
			t.appendChild(tb);
			c.body.appendChild(t);
			return c.el;
		}

		function paint() {
			grid.textContent = '';
			var b = data.blocks;
			var order = [['sales', sales], ['traffic', traffic], ['kpis', kpis], ['production', production], ['campaigns', campaigns]];
			var any = false;
			order.forEach(function (o) { if (b[o[0]]) { grid.appendChild(o[1](b[o[0]])); any = true; } });
			if (!any) { grid.appendChild(h('div', { class: 'lv-card' }, h('p', { class: 'muted' }, 'Für dein Profil gibt es keine Live-Zahlen. Verkauf, Berichte, Produktion und Marketing schalten Blöcke frei.'))); }
		}

		function load() {
			return ui.api('live').then(function (d) {
				data = d; failed = ''; fetched = Date.now();
				paint();
				armed = true;
			}).catch(function (e) { failed = e.message; });
		}

		document.addEventListener('visibilitychange', function onVis() {
			if (!document.body.contains(root)) { document.removeEventListener('visibilitychange', onVis); return; }
			if (!document.hidden) { keepAwake(); load(); }
		});

		// Wer die Seite verlässt, gibt den Bildschirm wieder frei.
		window.addEventListener('hashchange', function away() {
			if (location.hash.indexOf('#/live') !== 0) {
				window.removeEventListener('hashchange', away);
				if (document.fullscreenElement && document.exitFullscreen) { document.exitFullscreen().catch(function () {}); }
				if (wake) { wake.release().catch(function () {}); wake = null; }
			}
		});

		ui.every(tick, 1000);
		ui.every(load, 15000);
		tick();
		load();
		if (kiosk) { keepAwake(); }
	};
})();
