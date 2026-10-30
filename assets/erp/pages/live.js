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

	function axis(labels) {
		var ax = h('div', { class: 'lv-ax' });
		labels.forEach(function (t) { ax.appendChild(h('span', null, t)); });
		return ax;
	}

	// Balken je Stunde, die laufende hervorgehoben; füllt die Höhe, die der Karte übrig bleibt.
	function bars(values, current, color) {
		var max = Math.max.apply(null, values.concat([1]));
		var n = values.length;
		var root = svg('svg', { viewBox: '0 0 ' + n * 10 + ' 100', preserveAspectRatio: 'none', role: 'img', 'aria-label': 'Verlauf nach Stunde' });
		values.forEach(function (v, i) {
			var bh = Math.max(v > 0 ? 4 : 1.5, v / max * 100);
			root.appendChild(svg('rect', { x: i * 10 + 1, y: 100 - bh, width: 8, height: bh, class: 'bar' + (i === current ? ' now' : '') + (i > current ? ' future' : ''), fill: color }));
		});
		return h('div', { class: 'lv-chart' }, h('div', { class: 'lv-plot' }, root), axis(['0', '6', '12', '18', '23']));
	}

	function line(series) {
		var vals = series.map(function (s) { return s.views; });
		var max = Math.max.apply(null, vals.concat([1]));
		var w = 240, step = w / (vals.length - 1);
		var d = '';
		vals.forEach(function (v, i) { d += (i ? 'L' : 'M') + (i * step).toFixed(1) + ' ' + (96 - v / max * 88).toFixed(1) + ' '; });
		var root = svg('svg', { viewBox: '0 0 ' + w + ' 100', preserveAspectRatio: 'none', role: 'img', 'aria-label': 'Seitenaufrufe der letzten 24 Stunden' },
			svg('path', { d: d + 'L' + w + ' 100 L0 100 Z', class: 'area' }), svg('path', { d: d, class: 'ln', fill: 'none', 'vector-effect': 'non-scaling-stroke' }));
		var times = [0, 6, 12, 18, 23].map(function (i) { return new Date(series[i].ts * 1000).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }); });
		return h('div', { class: 'lv-chart' }, h('div', { class: 'lv-plot' }, root), axis(times));
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
			state.textContent = failed ? 'Keine Verbindung – Stand ' + new Date(fetched).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) : (fetched ? 'live · vor ' + age + ' s' : 'lädt …');
		}

		function sales(b) {
			var c = card('Verkauf heute', 'lv-sales');
			if (!b.available) { c.body.appendChild(h('p', { class: 'muted' }, 'WooCommerce ist nicht aktiv.')); return c.el; }
			c.body.appendChild(h('div', { class: 'lv-stats' },
				big(eur(b.revenue), 'Umsatz', delta(b.revenue, b.revenue_yday, eur(b.revenue_yday))),
				big(int(b.orders), b.orders === 1 ? 'Bestellung' : 'Bestellungen', delta(b.orders, b.orders_yday, int(b.orders_yday))),
				big(b.basket === null ? '–' : eur(b.basket, 2), 'Ø Warenkorb'),
				big(int(b.to_ship), 'zu versenden')));
			c.body.appendChild(bars(b.hours, b.hour_now, 'var(--lv-accent)'));
			var list = h('ul', { class: 'lv-orders' });
			b.last.forEach(function (o) {
				var fresh = armed && !seen[o.number];
				seen[o.number] = true;
				list.appendChild(h('li', { class: fresh ? 'new' : '' }, h('b', null, '#' + o.number), h('span', null, new Date(o.ts * 1000).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })), h('b', null, eur(o.total, 2))));
			});
			if (!b.last.length) { list.appendChild(h('li', { class: 'none' }, 'Heute noch keine Bestellung.')); }
			c.body.appendChild(list);
			return c.el;
		}

		function traffic(b) {
			var c = card('Website', 'lv-traffic');
			c.body.appendChild(h('div', { class: 'lv-stats three' },
				big(int(b.last30), 'letzte 30 Min.'),
				big(int(b.today), 'Aufrufe heute', delta(b.today, b.yesterday, int(b.yesterday))),
				big(int(b.ar_today), 'AR angezeigt')));
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
				? 'Noch kein Aufruf gezählt. Angemeldete Personen zählen nicht: Website in einem privaten Fenster öffnen.'
				: 'Letzter Aufruf vor ' + (ago < 10 ? 'unter 10' : ago) + ' Min. · Aufrufe, nicht Besucher.'));
			return c.el;
		}

		function kpis(b) {
			var g = h('div', { class: 'lv-kpis', title: 'Kennzahlen ' + b.label });
			b.rows.forEach(function (k) {
				g.appendChild(h('div', { class: 'lv-kpi lv-' + (k.level || 'grau') },
					h('div', { class: 'l' }, k.label),
					h('div', { class: 'v' }, k.text),
					k.target ? h('div', { class: 'tgt' }, 'Ziel ' + k.target) : null));
			});
			return g;
		}

		function production(b) {
			var c = card('Produktion', 'lv-prod');
			c.body.appendChild(h('div', { class: 'lv-runs' },
				big(int(b.runs.freigegeben), 'freigegeben'), big(int(b.runs.im_druck), 'im Druck'), big(int(b.runs.zu_melden), 'zu melden')));
			var pr = h('div', { class: 'lv-printers' });
			b.printers.forEach(function (p) {
				var rest = p.progress !== null && p.remaining !== null ? (p.remaining < 60 ? p.remaining + ' min' : Math.floor(p.remaining / 60) + ' h ' + ('0' + (p.remaining % 60)).slice(-2)) : '';
				pr.appendChild(h('div', { class: 'lv-printer st-' + (p.online ? p.status : 'offline') },
					h('b', { class: 'nm' }, p.name),
					h('div', { class: 'pc' }, p.progress !== null ? p.progress + ' %' : p.text),
					p.progress !== null ? h('div', { class: 'meter' }, h('i', { style: 'width:' + p.progress + '%' })) : null,
					h('div', { class: 'sub' }, p.progress !== null ? (rest ? 'noch ' + rest : p.text) : '')));
			});
			if (!b.printers.length) { pr.appendChild(h('p', { class: 'muted' }, 'Kein Drucker angelegt.')); }
			c.body.appendChild(pr);
			b.alerts.forEach(function (a) {
				c.body.appendChild(h('div', { class: 'lv-alert' }, '⚠ ' + a.label + ' wird knapp: ' + (a.days === null ? 'Reichweite unbekannt' : 'reicht noch ' + a.days + ' Tage')));
			});
			return c.el;
		}

		// Zeigt nur, was ganz in die Karte passt; der Rest steht als „+ N weitere“.
		function fitList(list, more, total) {
			var rows = list.childNodes, shown = rows.length, i;
			for (i = 0; i < rows.length; i++) { rows[i].style.display = ''; }
			for (i = 0; i < rows.length; i++) {
				if (rows[i].offsetTop + rows[i].offsetHeight > list.clientHeight + 1) { shown = i; break; }
			}
			for (i = shown; i < rows.length; i++) { rows[i].style.display = 'none'; }
			more.textContent = total > shown ? '+ ' + (total - shown) + ' weitere' : '';
		}

		function tasks(b) {
			var c = card('Aufgaben' + (b.total ? ' · ' + b.total : ''), 'lv-tasks');
			if (!b.rows.length) { c.body.appendChild(h('p', { class: 'lv-done' }, '✓ Alles erledigt')); return c; }
			var urgent = { melden: 1, wartung: 1, lieferung: 1, rechnung: 1 };
			var list = h('ul', { class: 'lv-tlist' });
			b.rows.forEach(function (t) {
				list.appendChild(h('li', { class: urgent[t.kind] ? 'hot' : '' }, h('b', null, t.title), h('span', null, t.sub)));
			});
			var more = h('p', { class: 'lv-note' }, '');
			c.body.appendChild(list);
			c.body.appendChild(more);
			c.fit = function () { fitList(list, more, b.total); };
			return c;
		}

		function campaigns(b) {
			var c = card('Kampagnen', 'lv-camp');
			if (!b.rows.length) { c.body.appendChild(h('p', { class: 'muted' }, 'Keine laufende Kampagne.')); return c.el; }
			var t = h('table', { class: 'lv-table' }, h('thead', null, h('tr', null, h('th', null, 'Kampagne'), h('th', null, 'Kanal'), h('th', { class: 'num' }, 'Kosten'), h('th', { class: 'num' }, 'Umsatz'), h('th', { class: 'num' }, 'je € Einsatz'), h('th', { class: 'num' }, 'Bestellungen'), h('th', { class: 'num' }, 'Budget'))));
			var tb = h('tbody');
			b.rows.slice(0, 4).forEach(function (k) {
				tb.appendChild(h('tr', null,
					h('td', null, h('b', null, k.name)), h('td', { class: 'sub' }, k.channel),
					h('td', { class: 'num' }, eur(k.cost)), h('td', { class: 'num' }, eur(k.revenue)),
					h('td', { class: 'num' }, k.roas === null ? '–' : k.roas.toLocaleString('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '×'),
					h('td', { class: 'num' }, int(k.orders)),
					h('td', { class: 'num ' + (k.light === 'ueberzogen' ? 'bad' : (k.light === 'knapp' ? 'warn' : '')) }, k.budget === null ? '–' : k.budget + ' %')));
			});
			t.appendChild(tb);
			c.body.appendChild(t);
			return c.el;
		}

		function paint() {
			grid.textContent = '';
			var b = data.blocks;
			var any = false;
			if (b.kpis) { grid.appendChild(kpis(b.kpis)); any = true; }
			var mid = h('div', { class: 'lv-mid' });
			[['sales', sales], ['traffic', traffic]].forEach(function (o) { if (b[o[0]]) { mid.appendChild(o[1](b[o[0]])); any = true; } });
			var side = h('div', { class: 'lv-side' });
			var fits = [];
			[['production', production], ['tasks', tasks]].forEach(function (o) {
				if (!b[o[0]]) { return; }
				var r = o[1](b[o[0]]);
				side.appendChild(r.el || r);
				if (r.fit) { fits.push(r.fit); }
				any = true;
			});
			if (side.childNodes.length) { mid.appendChild(side); }
			if (mid.childNodes.length) { grid.appendChild(mid); }
			if (b.campaigns) { grid.appendChild(campaigns(b.campaigns)); any = true; }
			fits.forEach(function (f) { f(); });
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
