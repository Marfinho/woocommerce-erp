/*
 * Buchhaltung, Reiter „Marketing-Kosten“: Budget gegen Kosten gegen Umsatz je Kostenstelle,
 * Summen nach Kanal und Monat (SVG-Balken), Belege ohne Kampagne mit Ein-Klick-Zuordnung,
 * CSV-Export. Server: includes/Erp/Pages/BuchMarketing.php (Route erp/buch/marketing*).
 * Rechnen tut der Server (KampagnenReport); hier wird nur angezeigt.
 *
 * Der Reiter meldet sich bei der Buchhaltungsseite an: UI.buchTabs['marketing'].
 * Er braucht von ctx nur ui und h (und canWrite, wenn vorhanden) und bringt Datumsfelder,
 * Tabelle und Download selbst mit. Alle Texte gelangen über h() bzw. textContent in die
 * Seite, nie als HTML-Text.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.buchTabs = UI.buchTabs || {};

	var SVGNS = 'http://www.w3.org/2000/svg';
	var AMPEL = { im_rahmen: 'now', knapp: '', ueberzogen: 'later', kein_budget: '' };

	function svg(tag, attrs, text) {
		var n = document.createElementNS(SVGNS, tag);
		Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
		if (text !== undefined && text !== null) { n.textContent = text; }
		return n;
	}

	function pad(n) { return (n < 10 ? '0' : '') + n; }

	function ymd(s) {
		var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || '');
		return m ? m[3] + '.' + m[2] + '.' + m[1] : '';
	}

	function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

	function dec(n) { return String(Math.round(Number(n) * 100) / 100).replace('.', ','); }

	function pct(n) { return (Math.round(Number(n) * 10) / 10).toString().replace('.', ',') + ' %'; }

	function monthLabel(key) {
		var names = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
		var p = key.split('-');
		return names[parseInt(p[1], 10) - 1] + ' ' + p[0].slice(2);
	}

	function download(name, mime, text) {
		var blob = new Blob([text], { type: mime });
		var url = URL.createObjectURL(blob);
		var a = document.createElement('a');
		a.href = url;
		a.download = name;
		document.body.appendChild(a);
		a.click();
		a.remove();
		setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
	}

	function presets() {
		var now = new Date();
		var y = now.getFullYear();
		var back = new Date(now.getTime() - 89 * 86400000);
		return [
			{ key: 'jahr', label: 'Dieses Jahr', von: y + '-01-01', bis: y + '-12-31' },
			{ key: 'vorjahr', label: 'Letztes Jahr', von: (y - 1) + '-01-01', bis: (y - 1) + '-12-31' },
			{ key: 'quartal', label: 'Letzte 90 Tage', von: iso(back), bis: iso(now) }
		];
	}

	/* ---------- Diagramme ---------- */

	// Einsatz und Umsatz je Kanal als waagerechte Doppelbalken.
	function channelChart(h, ui, channels) {
		var max = 0;
		channels.forEach(function (c) { max = Math.max(max, c.einsatz, c.umsatz); });
		if (max <= 0) { return h('p', { class: 'muted' }, 'Noch keine Kosten oder Umsätze in diesem Zeitraum.'); }
		var W = 360, rowH = 54, left = 0, barMax = W - 100;
		var root = svg('svg', { viewBox: '0 0 ' + W + ' ' + (channels.length * rowH + 4), class: 'bm-chart', role: 'img',
			'aria-label': 'Einsatz und Umsatz nach Kanal: ' + channels.map(function (c) { return c.channel + ' Einsatz ' + dec(c.einsatz) + ' Euro, Umsatz ' + dec(c.umsatz) + ' Euro'; }).join('; ') });
		channels.forEach(function (c, i) {
			var y = i * rowH + 4;
			root.appendChild(svg('text', { x: left, y: y + 12, class: 'bm-lab' }, c.channel));
			var w1 = Math.max(c.einsatz > 0 ? 2 : 0, c.einsatz / max * barMax);
			var w2 = Math.max(c.umsatz > 0 ? 2 : 0, c.umsatz / max * barMax);
			root.appendChild(svg('rect', { x: left, y: y + 18, width: w1, height: 9, rx: 2, class: 'bm-bar-cost' }));
			root.appendChild(svg('text', { x: left + w1 + 4, y: y + 26, class: 'bm-val' }, ui.money(c.einsatz, 'EUR')));
			root.appendChild(svg('rect', { x: left, y: y + 31, width: w2, height: 9, rx: 2, class: 'bm-bar-rev' }));
			root.appendChild(svg('text', { x: left + w2 + 4, y: y + 39, class: 'bm-val' }, ui.money(c.umsatz, 'EUR')));
		});
		return h('div', null, root,
			h('div', { class: 'bm-legend' },
				h('span', null, h('i', { class: 'bm-key bm-bar-cost' }), ' Einsatz'),
				h('span', null, h('i', { class: 'bm-key bm-bar-rev' }), ' Umsatz')));
	}

	// Kosten je Monat als senkrechte Balken.
	function monthChart(h, ui, months) {
		var max = 0;
		months.forEach(function (m) { max = Math.max(max, m.sum); });
		if (!months.length || max <= 0) { return h('p', { class: 'muted' }, 'Noch keine Kosten aus zugeordneten Belegen.'); }
		var W = 360, H = 150, top = 16, bottom = 24, n = months.length;
		var slot = W / n, bw = Math.max(4, Math.min(34, slot * 0.7));
		var every = Math.ceil(n / 8);
		var root = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'bm-chart', role: 'img',
			'aria-label': 'Kosten nach Monat: ' + months.map(function (m) { return monthLabel(m.month) + ' ' + dec(m.sum) + ' Euro'; }).join('; ') });
		root.appendChild(svg('line', { x1: 0, y1: H - bottom, x2: W, y2: H - bottom, class: 'bm-axis' }));
		months.forEach(function (m, i) {
			var bh = m.sum > 0 ? Math.max(2, m.sum / max * (H - top - bottom)) : 0;
			var x = i * slot + (slot - bw) / 2;
			if (bh > 0) {
				root.appendChild(svg('rect', { x: x, y: H - bottom - bh, width: bw, height: bh, rx: 2, class: 'bm-bar-cost' }));
			}
			if (m.sum === max || n <= 6) {
				if (m.sum > 0) { root.appendChild(svg('text', { x: x + bw / 2, y: H - bottom - bh - 4, class: 'bm-val', 'text-anchor': 'middle' }, ui.money(m.sum, 'EUR'))); }
			}
			if (i % every === 0) {
				root.appendChild(svg('text', { x: x + bw / 2, y: H - 8, class: 'bm-lab', 'text-anchor': 'middle' }, monthLabel(m.month)));
			}
		});
		return root;
	}

	/* ---------- Reiter ---------- */

	UI.buchTabs['marketing'] = {
		label: 'Marketing-Kosten',
		order: 55,
		render: function (host, ctx) {
			var ui = ctx.ui;
			var h = ctx.h || ui.h;
			var canAssign = ctx.canWrite !== false && ui.can('verkauf.marketing');
			var ps = presets();
			var range = { von: ps[0].von, bis: ps[0].bis };
			var data = null;

			var bar = h('div', { class: 'bm-bar-top' });
			var out = h('div');
			host.appendChild(h('p', { class: 'muted bm-intro' }, 'Was haben Marktstände, Anzeigen und Flyer gekostet und gebracht? Alle Zahlen kommen aus den Kostenstellen der Marketing-Seite: Kosten aus zugeordneten Belegen, Umsatz aus Marktkasse, Gutscheincode und Kampagnenkennung. Gezeigt werden Kampagnen, deren Veranstaltungstag (sonst Beginn) im Zeitraum liegt.'));
			host.appendChild(bar);
			host.appendChild(out);

			function drawBar() {
				bar.textContent = '';
				var von = h('input', { class: 'field', type: 'date', value: range.von, 'aria-label': 'Von' });
				var bis = h('input', { class: 'field', type: 'date', value: range.bis, 'aria-label': 'Bis' });
				var chips = h('div', { class: 'presets' }, ps.map(function (p) {
					return h('button', { class: 'chip', type: 'button', 'aria-pressed': range.von === p.von && range.bis === p.bis ? 'true' : 'false', onclick: function () {
						range = { von: p.von, bis: p.bis };
						drawBar();
						load();
					} }, p.label);
				}));
				var csv = h('button', { class: 'btn', type: 'button' }, 'CSV-Export');
				csv.addEventListener('click', function () {
					csv.disabled = true;
					ui.api('buch/marketing/export?von=' + encodeURIComponent(von.value) + '&bis=' + encodeURIComponent(bis.value)).then(function (r) {
						csv.disabled = false;
						download(r.filename, 'text/csv;charset=utf-8', r.csv);
					}, function (e) {
						csv.disabled = false;
						ui.toast(e.message, true);
					});
				});
				bar.appendChild(chips);
				bar.appendChild(h('div', { class: 'bm-range' }, von, bis,
					h('button', { class: 'btn primary', type: 'button', onclick: function () { range = { von: von.value, bis: bis.value }; drawBar(); load(); } }, 'Anzeigen'),
					csv));
			}

			function load() {
				out.textContent = '';
				out.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
				ui.api('buch/marketing?von=' + encodeURIComponent(range.von) + '&bis=' + encodeURIComponent(range.bis)).then(function (d) {
					data = d;
					draw();
				}, function (e) {
					out.textContent = '';
					out.appendChild(h('div', { class: 'notice warn' }, e.message));
				});
			}

			function tile(label, value, note) {
				return h('div', { class: 'tile' }, h('div', { class: 'lab' }, label), h('div', { class: 'val' }, value), note ? h('div', { class: 'note' }, note) : null);
			}

			function money(v) { return ui.money(v, 'EUR'); }

			function draw() {
				out.textContent = '';
				var t = data.totals;
				out.appendChild(h('div', { class: 'grid tiles' },
					tile('Budget geplant', money(t.budget), t.count + ' Kostenstelle(n)'),
					tile('Kosten (Belege)', money(t.kosten), t.ampel === 'kein_budget' ? 'ohne Budget' : pct(t.budget > 0 ? t.kosten / t.budget * 100 : 0) + ' vom Budget'),
					tile('Einsatz gesamt', money(t.einsatz), t.rabatt > 0 ? 'inkl. ' + money(t.rabatt) + ' Rabatt' : null),
					tile('Umsatz', money(t.umsatz), 'nachgewiesen'),
					tile('Ergebnis', money(t.ergebnis), 'Umsatz − Einsatz'),
					tile('Umsatz je Euro', t.roas === null ? '–' : dec(t.roas), t.roas === null ? 'noch kein Einsatz' : null)));

				out.appendChild(unassignedCard());
				out.appendChild(tableCard());
				out.appendChild(h('div', { class: 'card bm-gap' }, h('h3', null, 'Nach Kanal'),
					data.channels.length ? channelChart(h, ui, data.channels) : h('p', { class: 'muted' }, 'Keine Kampagne im Zeitraum.'),
					h('p', { class: 'muted' }, 'Kanäle mit gleichem Namen (egal ob groß oder klein geschrieben) sind zusammengefasst. Kampagnen ohne Kanal stehen unter „Ohne Kanal“.')));
				out.appendChild(h('div', { class: 'card bm-gap' }, h('h3', null, 'Kosten nach Monat'),
					monthChart(h, ui, data.months),
					h('p', { class: 'muted' }, 'Nach Belegdatum, also wann die Rechnung ausgestellt wurde – nicht nach Veranstaltungstag. So passen die Monate zur Steuer. Anteile geteilter Belege sind schon heruntergerechnet.')));
			}

			function tableCard() {
				var card = h('div', { class: 'card bm-gap' }, h('h3', null, 'Kostenstellen im Zeitraum'));
				if (!data.rows.length) {
					card.appendChild(h('p', { class: 'muted' }, 'Im Zeitraum ' + ymd(data.von) + ' bis ' + ymd(data.bis) + ' liegt keine Kampagne. Kampagnen legt die Marketing-Seite an.'));
					card.appendChild(h('a', { class: 'btn', href: '#/seite/marketing' }, 'Zu den Kampagnen'));
					return card;
				}
				var head = h('tr', null, ['Kostenstelle', 'Kanal', 'Budget', 'Kosten', 'Umsatz', 'Ergebnis', 'Umsatz je €', 'Budget-Ampel'].map(function (t, i) {
					return h('th', { class: i >= 2 && i <= 6 ? 'num' : null }, t);
				}));
				var body = h('tbody');
				data.rows.forEach(function (r) {
					body.appendChild(h('tr', null,
						h('td', null, h('a', { href: '#/kampagne/' + r.id }, r.kst + ' ' + r.name), h('div', { class: 'muted bm-sub' }, [r.art, ymd(r.datum), r.status].join(' · '))),
						h('td', null, r.kanal || '–'),
						h('td', { class: 'num' }, r.budget > 0 ? money(r.budget) : '–'),
						h('td', { class: 'num' }, money(r.kosten)),
						h('td', { class: 'num' }, money(r.umsatz)),
						h('td', { class: 'num bm-' + (r.ergebnis < 0 ? 'neg' : 'pos') }, money(r.ergebnis)),
						h('td', { class: 'num' }, r.roas === null ? '–' : dec(r.roas)),
						h('td', null, h('span', { class: 'tag ' + (AMPEL[r.ampel] || '') }, r.ampel_text + (r.auslastung !== null ? ' · ' + pct(r.auslastung) : '')))));
				});
				var t = data.totals;
				body.appendChild(h('tr', { class: 'zt-sum' },
					h('td', null, 'Summe'), h('td', null, ''),
					h('td', { class: 'num' }, money(t.budget)), h('td', { class: 'num' }, money(t.kosten)), h('td', { class: 'num' }, money(t.umsatz)),
					h('td', { class: 'num' }, money(t.ergebnis)), h('td', { class: 'num' }, t.roas === null ? '–' : dec(t.roas)), h('td', null, '')));
				card.appendChild(h('div', { class: 'zt-scroll' }, h('table', { class: 'list bm-table' }, h('thead', null, head), body)));
				card.appendChild(h('p', { class: 'muted' }, 'Kosten sind die zugeordneten Belege; Rabatte aus Gutscheinen kommen im Ergebnis dazu (Ergebnis = Umsatz − Kosten − Rabatt). Die Ampel vergleicht nur die gebuchten Kosten mit dem Budget: grün bis 80 %, gelb bis 100 %, rot darüber. Die Einzelheiten und Erklärungen stehen in der Kampagne.'));
				return card;
			}

			function unassignedCard() {
				var u = data.unassigned;
				var card = h('div', { class: 'card bm-gap' }, h('h3', null, 'Noch nicht zugeordnet'));
				if (!u.rows.length) {
					card.appendChild(h('p', { class: 'muted' }, 'Alle Belege mit Kategorie Werbung oder Marketing in diesem Zeitraum gehören schon zu einer Kampagne.'));
					return card;
				}
				card.appendChild(h('p', { class: 'muted', style: 'margin-top:0' }, u.total + ' Beleg(e) mit Kategorie Werbung oder Marketing haben keine oder nicht die ganze Kampagne. Ihre Kosten fehlen deshalb in den Kostenstellen.' + (u.total > u.rows.length ? ' Gezeigt werden die ersten ' + u.rows.length + '.' : '')));
				var list = h('div', { class: 'rows' });
				u.rows.forEach(function (b) { list.appendChild(unassignedRow(b)); });
				card.appendChild(list);
				return card;
			}

			function assign(b, campaignId, btn) {
				btn.disabled = true;
				ui.api('buch/marketing/zuordnen', { method: 'POST', body: { beleg_id: b.id, kampagne_id: campaignId, anteil: 100 } }).then(function (r) {
					ui.toast(r.message);
					load();
				}, function (e) {
					btn.disabled = false;
					ui.toast(e.message, true);
				});
			}

			function unassignedRow(b) {
				var s = b.suggestion;
				var actions = h('div', { class: 'bm-assign' });
				if (canAssign) {
					if (s) {
						var one = h('button', { class: 'btn primary', type: 'button' }, 'Zu ' + s.label + ' zuordnen');
						one.addEventListener('click', function () { assign(b, s.id, one); });
						actions.appendChild(one);
					}
					var camps = data.campaigns || [];
					if (camps.length) {
						var sel = h('select', { class: 'field', 'aria-label': 'Andere Kampagne wählen' }, h('option', { value: '' }, s ? 'Andere Kampagne …' : 'Kampagne wählen …'));
						camps.forEach(function (c) { sel.appendChild(h('option', { value: String(c.id) }, c.label)); });
						var other = h('button', { class: 'btn', type: 'button' }, 'Zuordnen');
						other.addEventListener('click', function () {
							if (!sel.value) { ui.toast('Bitte zuerst eine Kampagne wählen.', true); return; }
							assign(b, parseInt(sel.value, 10), other);
						});
						actions.appendChild(h('div', { class: 'bm-pick' }, sel, other));
					} else {
						actions.appendChild(h('a', { class: 'btn', href: '#/seite/marketing' }, 'Erst eine Kampagne anlegen'));
					}
				}
				return h('div', { class: 'row-card bm-un' },
					h('div', { class: 'row-main' }, h('b', null, b.number + ' · ' + (b.vendor || 'ohne Händler')),
						h('small', null, [ymd(b.date), b.account, b.free < 100 ? Math.round(b.free) + ' % noch frei' : ''].filter(Boolean).join(' · ')),
						s ? h('small', { class: 'bm-reason' }, 'Vorschlag: ' + s.label + ' (' + s.reason + ')') : h('small', { class: 'bm-reason' }, 'Kein Vorschlag: Datum und Text passen zu keiner Kampagne.')),
					h('div', { class: 'row-num' }, money(b.amount)),
					h('div', { class: 'row-tags' }, b.file ? h('a', { class: 'btn mk-small', href: b.file, target: '_blank', rel: 'noopener' }, 'Foto') : null),
					actions);
			}

			drawBar();
			load();
		}
	};
})();
