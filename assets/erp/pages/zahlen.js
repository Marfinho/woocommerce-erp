/*
 * ERP-Bereich „Zahlen“: Dashboard Finanzen, Berichte, Reporting, Buchhaltung.
 * Server: includes/Erp/Pages/Zahlen.php. Alle Daten gelangen über textContent
 * bzw. setAttribute in die Seite, nie als HTML-Text.
 */
(function () {
	'use strict';
	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	var MONTHS = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
	var SVGNS = 'http://www.w3.org/2000/svg';

	function esvg(tag, attrs) {
		var n = document.createElementNS(SVGNS, tag);
		Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
		for (var i = 2; i < arguments.length; i++) { if (arguments[i]) { n.appendChild(arguments[i]); } }
		return n;
	}

	function fail(box, ui, e) {
		box.textContent = '';
		box.appendChild(ui.h('div', { class: 'notice warn' }, e.message));
	}

	function eur(ui, v) { return v === null || v === undefined ? '—' : ui.money(v, 'EUR'); }

	function tile(ui, label, value, note, tone) {
		return ui.h('div', { class: 'tile zt' + (tone ? ' ' + tone : '') },
			ui.h('div', { class: 'lab' }, label),
			ui.h('div', { class: 'val' }, value),
			note ? ui.h('div', { class: 'muted', style: 'font-size:12px;margin-top:2px' }, note) : null);
	}

	function chips(ui, items, current, onPick) {
		var row = ui.h('div', { class: 'presets zt-chips' });
		items.forEach(function (it) {
			row.appendChild(ui.h('button', {
				class: 'chip zt-chip', type: 'button', 'aria-pressed': String(it.key) === String(current) ? 'true' : 'false',
				onclick: function () { onPick(it.key); }
			}, it.label));
		});
		return row;
	}

	function table(ui, head, rows, numCols) {
		var tr = ui.h('tr');
		head.forEach(function (t, i) { tr.appendChild(ui.h('th', { class: numCols && numCols.indexOf(i) > -1 ? 'num' : null }, t)); });
		var body = ui.h('tbody');
		rows.forEach(function (r) {
			var row = ui.h('tr', { class: r.cls || null });
			r.cells.forEach(function (c, i) { row.appendChild(ui.h('td', { class: numCols && numCols.indexOf(i) > -1 ? 'num' : null }, c)); });
			body.appendChild(row);
		});
		return ui.h('div', { class: 'zt-scroll' }, ui.h('table', { class: 'list' }, ui.h('thead', null, tr), body));
	}

	function pager(ui, d, go) {
		if (d.seiten <= 1) { return null; }
		return ui.h('div', { class: 'zt-pager' },
			ui.h('button', { class: 'btn', type: 'button', disabled: d.seite <= 1, onclick: function () { go(d.seite - 1); } }, 'Zurück'),
			ui.h('span', { class: 'muted' }, 'Seite ' + d.seite + ' von ' + d.seiten + ' · ' + d.gesamt + ' Einträge'),
			ui.h('button', { class: 'btn', type: 'button', disabled: d.seite >= d.seiten, onclick: function () { go(d.seite + 1); } }, 'Weiter'));
	}

	function download(name, mime, text) {
		var blob = new Blob([text], { type: mime });
		var url = URL.createObjectURL(blob);
		var a = document.createElement('a');
		a.href = url; a.download = name;
		document.body.appendChild(a); a.click(); a.remove();
		setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
	}

	function qs(o) {
		return Object.keys(o).filter(function (k) { return o[k] !== '' && o[k] !== null && o[k] !== undefined; })
			.map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(o[k]); }).join('&');
	}

	function dateRange(ui, von, bis, label, onGo) {
		var a = ui.h('input', { class: 'field', type: 'date', value: von, 'aria-label': 'Von' });
		var b = ui.h('input', { class: 'field', type: 'date', value: bis, 'aria-label': 'Bis' });
		return ui.h('div', { class: 'label-area zt-range' }, a, b,
			ui.h('button', { class: 'btn primary', type: 'button', onclick: function () { onGo(a.value, b.value); } }, label));
	}

	function meter(ui, k) {
		var m = k.meter || {};
		var wrap = ui.h('div', { class: 'zt-meter lv-' + k.level, role: 'img', 'aria-label': k.label + ': ' + k.text + (k.target !== null ? ', Ziel ' + k.target_text : '') + (k.alarm !== null ? ', Alarm ' + k.alarm_text : '') });
		if (m.value === null || m.value === undefined) { return ui.h('span', { class: 'muted' }, '—'); }
		wrap.appendChild(ui.h('i', { style: 'width:' + Math.round(m.value * 100) + '%' }));
		if (m.target !== null && m.target !== undefined) { wrap.appendChild(ui.h('b', { class: 'mk t', style: 'left:' + Math.round(m.target * 100) + '%' })); }
		if (m.alarm !== null && m.alarm !== undefined) { wrap.appendChild(ui.h('b', { class: 'mk a', style: 'left:' + Math.round(m.alarm * 100) + '%' })); }
		return wrap;
	}

	/* ---------- Dashboard Finanzen ---------- */

	UI.pages['finanz-dashboard'] = function (item, ui) {
		var h = ui.h;
		ui.pageHead('Dashboard Finanzen', 'Zahlen auf einen Blick: Jahr, Betrieb heute und Steuer-Rücklage.', ui.classicBtn(item));
		var box = h('div');
		ui.view().appendChild(box);
		var year = 0;

		function load() {
			box.textContent = '';
			box.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
			ui.api('zahlen/dashboard' + (year ? '?jahr=' + year : '')).then(paint).catch(function (e) { fail(box, ui, e); });
		}

		function paint(d) {
			box.textContent = '';
			year = d.jahr;
			box.appendChild(h('h3', { class: 'zt-h' }, 'Finanzübersicht ' + d.jahr));
			box.appendChild(chips(ui, d.jahre.map(function (y) { return { key: y, label: String(y) }; }), d.jahr, function (y) { year = y; load(); }));

			box.appendChild(h('div', { class: 'grid tiles' },
				tile(ui, 'Einnahmen', eur(ui, d.einnahmen), null, 'ok'),
				tile(ui, 'Ausgaben', eur(ui, d.ausgaben), null, 'bad'),
				tile(ui, 'Gewinn', eur(ui, d.gewinn), null, d.gewinn >= 0 ? 'ok' : 'bad'),
				tile(ui, 'Marge', d.marge === null ? '—' : ui.num(d.marge) + ' %', d.marge === null ? 'Ohne Einnahmen keine Marge.' : 'Gewinn je Euro Einnahmen'),
				tile(ui, 'Offene Belege', String(d.offene_belege), 'noch nicht festgeschrieben', d.offene_belege > 0 ? 'warn' : ''),
				tile(ui, 'Anlagevermögen', eur(ui, d.anlagevermoegen)),
				tile(ui, 'Kassensaldo', eur(ui, d.kassensaldo)),
				d.faellige_dauerbuchungen > 0 ? tile(ui, 'Fällige Buchungen', String(d.faellige_dauerbuchungen), 'Dauerbuchungen', 'warn') : null
			));
			if (d.kleinunternehmer) { box.appendChild(h('p', { class: 'muted', style: 'font-size:13px' }, 'Kleinunternehmer §19 UStG: keine Umsatzsteuer ausgewiesen, alle Beträge brutto.')); }

			var b = d.betrieb;
			box.appendChild(h('h3', { class: 'zt-h' }, 'Betrieb heute'));
			box.appendChild(h('div', { class: 'grid tiles' },
				tile(ui, 'Umsatz (Monat)', eur(ui, b.umsatz_monat), 'Laufendes Jahr: ' + eur(ui, b.umsatz_jahr)),
				tile(ui, 'Offene Bestellungen', String(b.offene_bestellungen), b.bestellungen_heute + ' neue heute'),
				tile(ui, 'Lagerwarnungen', String(b.lagerwarnungen), null, b.lagerwarnungen > 0 ? 'warn' : ''),
				tile(ui, 'Offene Tickets', String(b.offene_tickets)),
				tile(ui, 'Offene Picklisten', String(b.offene_picklisten)),
				tile(ui, 'Steuer-Rücklage', eur(ui, d.ruecklage.betrag), 'Gewinn lfd. Jahr: ' + eur(ui, d.ruecklage.gewinn) + ' · Richtwert, ersetzt keine steuerliche Beratung.')
			));

			// Monatsübersicht mit Balken
			var max = 1;
			d.monate.forEach(function (m) { max = Math.max(max, m.einnahmen, m.ausgaben); });
			var rows = d.monate.map(function (m) {
				return {
					cells: [MONTHS[m.monat - 1], eur(ui, m.einnahmen), eur(ui, m.ausgaben), eur(ui, m.gewinn),
						h('div', { class: 'zt-bars' },
							h('i', { class: 'in', style: 'width:' + Math.round(m.einnahmen / max * 100) + '%', title: eur(ui, m.einnahmen) }),
							h('i', { class: 'out', style: 'width:' + Math.round(m.ausgaben / max * 100) + '%', title: eur(ui, m.ausgaben) }))]
				};
			});
			rows.push({ cls: 'zt-sum', cells: ['Gesamt', eur(ui, d.einnahmen), eur(ui, d.ausgaben), eur(ui, d.gewinn), ''] });
			box.appendChild(h('h3', { class: 'zt-h' }, 'Monatliche Übersicht'));
			box.appendChild(h('div', { class: 'card' }, table(ui, ['Monat', 'Einnahmen', 'Ausgaben', 'Gewinn', ''], rows, [1, 2, 3])));

			if (d.kategorien.length) {
				var top = d.kategorien[0].summe || 1;
				box.appendChild(h('h3', { class: 'zt-h' }, 'Ausgaben nach Kategorie'));
				box.appendChild(h('div', { class: 'card' }, table(ui, ['Kategorie', 'Betrag', ''], d.kategorien.map(function (k) {
					return { cells: [k.name, eur(ui, k.summe), h('div', { class: 'bar', 'aria-hidden': 'true' }, h('i', { style: 'width:' + Math.round(k.summe / top * 100) + '%' }))] };
				}), [1])));
			}

			if (d.lager.length) {
				box.appendChild(h('h3', { class: 'zt-h' }, 'Lagerwarnungen'));
				box.appendChild(h('div', { class: 'card' }, table(ui, ['Produkt', 'Bestand', 'Mindestbestand', 'Lage'], d.lager.map(function (w) {
					return { cells: [w.name, ui.num(w.bestand) + ' ' + w.einheit, ui.num(w.min) + ' ' + w.einheit, h('span', { class: 'tag ' + (w.ampel === 'rot' ? 'later' : '') }, w.ampel === 'rot' ? 'knapp' : 'bald nachbestellen')] };
				}), [1, 2])));
			}

			if (d.bestellungen.length) {
				box.appendChild(h('h3', { class: 'zt-h' }, 'Letzte Bestellungen'));
				box.appendChild(h('div', { class: 'card' }, table(ui, ['Bestellung', 'Kunde', 'Datum', 'Betrag', 'Status'], d.bestellungen.map(function (o) {
					return { cells: [o.url ? h('a', { href: o.url, target: '_blank', rel: 'noopener' }, '#' + o.nummer) : '#' + o.nummer, o.kunde, o.datum, eur(ui, o.betrag), o.status] };
				}), [3])));
			}
		}

		load();
	};

	/* ---------- Berichte ---------- */

	function trendChart(ui, t) {
		var W = 720, H = 240, L = 64, R = 14, T = 12, B = 34;
		var vals = [];
		t.punkte.forEach(function (p) {
			[p.value, p.target, p.alarm].forEach(function (v) { if (v !== null && v !== undefined) { vals.push(v); } });
		});
		var max = vals.length ? Math.max.apply(null, vals) * 1.12 : 1;
		if (max <= 0) { max = 1; }
		var n = t.punkte.length;
		function x(i) { return n < 2 ? (L + (W - R)) / 2 : L + (W - L - R) * i / (n - 1); }
		function y(v) { return T + (H - T - B) * (1 - Math.max(0, v) / max); }

		var svg = esvg('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'zt-chart', role: 'img', 'aria-label': 'Verlauf ' + t.label });
		svg.appendChild(esvg('title', {}, document.createTextNode('Verlauf ' + t.label)));
		for (var g = 0; g <= 4; g++) {
			var gv = max * g / 4;
			svg.appendChild(esvg('line', { x1: L, x2: W - R, y1: y(gv), y2: y(gv), class: 'grid' }));
			var lab = esvg('text', { x: L - 6, y: y(gv) + 4, 'text-anchor': 'end', class: 'ax' });
			lab.textContent = ui.num(Math.round(gv * 10) / 10);
			svg.appendChild(lab);
		}
		var stride = Math.ceil(n / 8);
		t.punkte.forEach(function (p, i) {
			if (i % stride !== 0 && i !== n - 1) { return; }
			var tl = esvg('text', { x: x(i), y: H - 12, 'text-anchor': 'middle', class: 'ax' });
			tl.textContent = p.label;
			svg.appendChild(tl);
		});

		function line(field, cls) {
			var d = '', open = false;
			t.punkte.forEach(function (p, i) {
				var v = p[field];
				if (v === null || v === undefined) { open = false; return; }
				d += (open ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(v).toFixed(1) + ' ';
				open = true;
			});
			if (d) { svg.appendChild(esvg('path', { d: d, class: cls, fill: 'none' })); }
		}
		line('target', 'ln target');
		line('alarm', 'ln alarm');
		line('value', 'ln value');
		t.punkte.forEach(function (p, i) {
			if (p.value === null) { return; }
			var c = esvg('circle', { cx: x(i), cy: y(p.value), r: 4, class: 'pt lv-' + p.level });
			c.appendChild(esvg('title', {}, document.createTextNode(p.text)));
			svg.appendChild(c);
		});
		return svg;
	}

	UI.pages['berichte'] = function (item, ui) {
		var h = ui.h;
		ui.pageHead('Berichte', 'Kennzahlen, Verlauf und Ziele des Betriebs.', ui.classicBtn(item));
		var box = h('div');
		ui.view().appendChild(box);
		var q = {};

		function load() {
			box.textContent = '';
			box.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
			ui.api('zahlen/berichte?' + qs(q)).then(paint).catch(function (e) {
				fail(box, ui, e);
				box.appendChild(h('p', null, h('button', { class: 'btn', type: 'button', onclick: function () { q = {}; load(); } }, 'Zeitraum zurücksetzen')));
			});
		}

		function exportFile(was, extra) {
			ui.api('zahlen/berichte/export?' + qs(Object.assign({ was: was, von: q.von, bis: q.bis }, extra || {}))).then(function (r) {
				download(r.filename, r.mime, r.text);
			}).catch(function (e) { ui.toast(e.message, true); });
		}

		function paint(d) {
			box.textContent = '';
			q.von = d.von; q.bis = d.bis; q.raster = d.raster; q.kennzahl = d.kennzahl; q.quartal = d.quartal.key;

			box.appendChild(h('div', { class: 'card' },
				dateRange(ui, d.von, d.bis, 'Auswerten', function (v, b) { q.von = v; q.bis = b; load(); }),
				h('div', { style: 'margin-top:10px' },
					h('button', { class: 'btn', type: 'button', onclick: function () { exportFile('kennzahlen'); } }, ui.ico('download'), 'CSV-Export'))));

			// Verlauf
			if (d.trend) {
				box.appendChild(h('h3', { class: 'zt-h' }, 'Verlauf'));
				var card = h('div', { class: 'card' });
				card.appendChild(chips(ui, d.raster_liste, d.raster, function (r) { q.raster = r; load(); }));
				var sel = h('select', { class: 'field', 'aria-label': 'Kennzahl', onchange: function () { q.kennzahl = sel.value; load(); } });
				d.kennzahlen.forEach(function (k) { sel.appendChild(h('option', { value: k.key, selected: k.key === d.kennzahl ? 'selected' : null }, k.label)); });
				sel.value = d.kennzahl;
				card.appendChild(sel);
				card.appendChild(h('div', { class: 'zt-chartbox' }, trendChart(ui, d.trend)));
				card.appendChild(h('div', { class: 'zt-legend muted' }, h('span', { class: 'k value' }, 'Wert'), h('span', { class: 'k target' }, 'Ziel'), h('span', { class: 'k alarm' }, 'Alarm')));
				card.appendChild(h('p', { class: 'muted', style: 'font-size:13px' }, d.trend.erklaerung));
				card.appendChild(h('details', { class: 'zt-details' }, h('summary', null, 'Zahlen zum Verlauf'),
					table(ui, ['Zeitraum', d.trend.label, 'Ziel', 'Alarm bei', 'Status'], d.trend.punkte.map(function (p) {
						return { cells: [p.label, h('b', null, p.text_value), p.target_text, p.alarm_text, h('span', { class: 'zt-lv lv-' + p.level }, p.level_text)] };
					}), [1, 2, 3])));
				box.appendChild(card);
			}

			// Kennzahlen + Ziele
			box.appendChild(h('h3', { class: 'zt-h' }, 'Kennzahlen im Zeitraum'));
			var inputs = {};
			var kc = h('div', { class: 'card' });
			if (d.can_goals) {
				kc.appendChild(h('p', { class: 'muted', style: 'font-size:13px' }, d.erklaerung));
				kc.appendChild(h('div', { class: 'zt-chips' }, h('span', { class: 'muted' }, 'Ziele für:'),
					chips(ui, d.quartale, d.quartal.key, function (k) { q.quartal = k; load(); })));
			}
			d.kpis.forEach(function (k) {
				var row = h('div', { class: 'zt-kpi' },
					h('div', { class: 'zt-kpi-head' }, h('b', null, k.label), h('span', { class: 'zt-val' }, k.text), h('span', { class: 'zt-lv lv-' + k.level }, k.level_text)),
					meter(ui, k));
				if (d.can_goals && k.goal) {
					var g = h('div', { class: 'zt-goals' });
					[['target', 'Ziel'], ['alarm', 'Alarm bei']].forEach(function (c) {
						var spec = k.goal[c[0]];
						var inp = h('input', { class: 'field', type: 'text', inputmode: 'decimal', value: spec.value, placeholder: spec.placeholder, 'aria-label': k.label + ' – ' + c[1] });
						inputs[k.key + '|' + c[0]] = inp;
						g.appendChild(h('label', null, h('span', { class: 'muted' }, c[1] + (k.unit ? ' (' + k.unit + ')' : '')), inp, spec.derived ? h('small', { class: 'muted' }, spec.derived) : null));
					});
					row.appendChild(g);
				} else {
					row.appendChild(h('div', { class: 'muted zt-lim' }, 'Ziel ' + k.target_text + ' · Alarm bei ' + k.alarm_text));
				}
				kc.appendChild(row);
			});
			if (d.can_goals) {
				kc.appendChild(h('button', { class: 'btn primary', type: 'button', style: 'margin-top:12px', onclick: function (ev) {
					var ziel = {};
					d.kpis.forEach(function (k) { ziel[k.key] = { target: inputs[k.key + '|target'].value, alarm: inputs[k.key + '|alarm'].value }; });
					ev.target.disabled = true;
					ui.api('zahlen/berichte/ziele', { method: 'POST', body: { quartal: d.quartal.key, ziel: ziel } })
						.then(function (r) { ui.toast(r.message); load(); })
						.catch(function (e) { ev.target.disabled = false; ui.toast(e.message, true); });
				} }, 'Ziele für ' + d.quartal.label + ' speichern'));
			}
			kc.appendChild(h('p', { class: 'muted', style: 'font-size:12.5px;margin-top:10px' }, 'Balken: Wert von 0 bis über den größten beteiligten Wert; Striche markieren Ziel und Alarmgrenze.'));
			box.appendChild(kc);

			// Auszug zur Analyse (nur mit Kostenrecht)
			if (d.can_costs) {
				box.appendChild(h('h3', { class: 'zt-h' }, 'Auszug zur Analyse'));
				box.appendChild(h('div', { class: 'card' },
					h('p', null, 'Lädt den Betriebszustand als eine Textdatei herunter: Artikel mit Kalkulation und Bestand, Stücklisten, Kennzahlen, Druckjournal, Arbeitszeit, offene Aufträge und die Befunde der Systemprüfung. Die Datei nennt ihre Einheiten und am Ende die bekannten Lücken.'),
					h('p', { class: 'muted' }, 'Namen von Geschäftskunden bleiben draußen, solange du sie nicht ausdrücklich mitgibst. Was du weitergibst, verlässt den Betrieb – prüf die Datei vorher, sie ist lesbarer Text.'),
					h('div', { class: 'zt-actions' },
						h('button', { class: 'btn primary', type: 'button', onclick: function () { exportFile('analyse', { namen: '0' }); } }, ui.ico('download'), 'Auszug herunterladen (ohne Kundennamen)'),
						h('button', { class: 'btn', type: 'button', onclick: function () { exportFile('analyse', { namen: '1' }); } }, 'Mit Kundennamen'))));
			}

			// Monatsabschluss
			var now = new Date();
			var yi = h('input', { class: 'field', type: 'number', value: String(now.getFullYear()), min: '2000', max: '2100', 'aria-label': 'Jahr' });
			var mi = h('input', { class: 'field', type: 'number', value: String(now.getMonth() + 1), min: '1', max: '12', 'aria-label': 'Monat' });
			box.appendChild(h('h3', { class: 'zt-h' }, 'Monatsabschluss'));
			box.appendChild(h('div', { class: 'card' },
				h('p', null, 'Friert die automatischen Kennzahlen des gewählten Monats ein. Eingefrorene Werte ändern sich danach nicht mehr, auch wenn Daten nachgetragen werden.'),
				h('div', { class: 'label-area' }, yi, mi,
					h('button', { class: 'btn primary', type: 'button', onclick: function (ev) {
						var btn = ev.target;
						if (!window.confirm('Kennzahlen für ' + mi.value + '/' + yi.value + ' jetzt einfrieren? Eingefrorene Werte ändern sich danach nicht mehr.')) { return; }
						btn.disabled = true;
						ui.api('zahlen/berichte/abschluss', { method: 'POST', body: { year: parseInt(yi.value, 10), month: parseInt(mi.value, 10) } })
							.then(function (r) { btn.disabled = false; ui.toast(r.message); })
							.catch(function (e) { btn.disabled = false; ui.toast(e.message, true); });
					} }, 'Abschließen'))));
		}

		load();
	};

	/* ---------- Reporting ---------- */

	UI.pages['reporting'] = function (item, ui) {
		var h = ui.h;
		ui.pageHead('Reporting', 'Wichtige Kunden, Bestellstatistik, Datensicherung und Protokoll.', ui.classicBtn(item));
		var box = h('div');
		ui.view().appendChild(box);
		var q = {};

		function load() {
			box.textContent = '';
			box.appendChild(h('div', { class: 'card' }, 'Wird geladen …'));
			ui.api('zahlen/reporting?' + qs(q)).then(paint).catch(function (e) {
				fail(box, ui, e);
				box.appendChild(h('p', null, h('button', { class: 'btn', type: 'button', onclick: function () { q = {}; load(); } }, 'Zeitraum zurücksetzen')));
			});
		}

		function paint(d) {
			box.textContent = '';
			q.von = d.von; q.bis = d.bis;

			box.appendChild(h('h3', { class: 'zt-h' }, 'VIP-Kunden'));
			var c = h('div', { class: 'card' }, dateRange(ui, d.von, d.bis, 'Anzeigen', function (v, b) { q.von = v; q.bis = b; q.seite = ''; load(); }));
			if (!d.woocommerce) { c.appendChild(h('p', { class: 'muted' }, 'Der Shop (WooCommerce) ist nicht aktiv, daher gibt es keine Kundenauswertung.')); }
			else if (!d.vips.length) { c.appendChild(h('p', { class: 'muted' }, 'In diesem Zeitraum gibt es keine abgeschlossenen Bestellungen.')); }
			else { c.appendChild(table(ui, ['Kunde', 'Bestellungen', 'Gesamtwert'], d.vips.map(function (v) { return { cells: [v.name, String(v.count), eur(ui, v.total)] }; }), [1, 2])); }
			c.appendChild(h('p', { class: 'muted', style: 'font-size:13px' }, 'Reine Lese-Ansicht als Grundlage für persönliche Gesten.'));
			box.appendChild(c);

			if (d.statistik.length) {
				box.appendChild(h('h3', { class: 'zt-h' }, 'Bestellstatistik'));
				box.appendChild(h('div', { class: 'card' }, table(ui, ['Zeitraum', 'Bestellungen', 'Umsatz', 'Durchschnitt'], d.statistik.map(function (s) {
					return { cells: [h('b', null, s.zeitraum), String(s.anzahl), eur(ui, s.umsatz), eur(ui, s.schnitt)] };
				}), [1, 2, 3])));
			}

			if (d.export.darf) {
				box.appendChild(h('h3', { class: 'zt-h' }, 'Datensicherung'));
				box.appendChild(h('div', { class: 'card' },
					h('p', null, 'Sendet Belege, Lagerbewegungen und Rechnungen als CSV-Dateien per E-Mail. Empfänger: ' + d.export.empfaenger + ' (in den Einstellungen änderbar). Läuft zusätzlich wöchentlich automatisch.'),
					h('button', { class: 'btn', type: 'button', onclick: function (ev) {
						var btn = ev.target;
						btn.disabled = true;
						ui.api('zahlen/reporting/datenexport', { method: 'POST', body: {} })
							.then(function (r) { btn.disabled = false; ui.toast(r.message); })
							.catch(function (e) { btn.disabled = false; ui.toast(e.message, true); });
					} }, 'Datenexport jetzt per E-Mail senden')));
			}

			box.appendChild(h('h3', { class: 'zt-h' }, 'Protokoll (Audit-Log)'));
			var lc = h('div', { class: 'card' });
			if (!d.log.length) { lc.appendChild(h('p', { class: 'muted' }, 'Noch keine Einträge.')); }
			else {
				lc.appendChild(table(ui, ['Zeit', 'Person', 'Aktion', 'Objekt', 'Details'], d.log.map(function (r) {
					return { cells: [r.zeit, r.nutzer, r.aktion, r.objekt, h('span', { class: 'zt-detail' }, r.details)] };
				})));
				var lp = pager(ui, d, function (s) { q.seite = s; load(); });
				if (lp) { lc.appendChild(lp); }
			}
			box.appendChild(lc);
		}

		load();
	};

	/* ---------- Buchhaltung (nur lesend) ---------- */

	// Reiter der Buchhaltung: Jede Datei unter pages/ trägt ihre Reiter in UI.buchTabs ein
	// (Schlüssel → { label, order, render(host, ctx) }); die hier unten sind der Rückfall.
	UI.buchTabs = UI.buchTabs || {};

	UI.pages['buchhaltung'] = function (item, ui) {
		var h = ui.h;
		var cfg = ui.config || {};
		// Wie in der klassischen Ansicht (ActionMap): Wer die Buchhaltung öffnen darf, darf auch erfassen und buchen.
		var canWrite = ui.can('finanzen.buchhaltung');
		var tab = (UI.buchFocus && UI.buchFocus.tab) || ui.store('lw16erp-buch-tab') || 'belege';
		var bar = h('div');
		var box = h('div');
		var quick = [];

		function refresh() { drawTabs(); show(); }

		// Neuer Beleg: Kamera oder Datei; die Erfassung selbst liefert pages/buch_belege.js.
		if (canWrite && UI.belegCapture) {
			quick.push(h('button', { class: 'btn primary', type: 'button', onclick: function () { UI.belegCapture({ mode: 'camera', onSaved: function () { tab = 'belege'; refresh(); } }); } }, ui.ico('camera'), 'Beleg scannen'));
			quick.push(h('button', { class: 'btn', type: 'button', onclick: function () { UI.belegCapture({ mode: 'upload', onSaved: function () { tab = 'belege'; refresh(); } }); } }, ui.ico('upload'), 'Beleg hochladen'));
		}
		quick.push(ui.classicBtn(item));
		ui.pageHead('Buchhaltung', 'Belege erfassen und einscannen, Rechnungen, Kasse, Journal, Anlagen, EÜR und Betriebsprüfung.', quick);
		ui.view().appendChild(bar);
		ui.view().appendChild(box);

		// Gemeinsames Werkzeug für alle Reiter.
		var ctx = {
			ui: ui, h: h, canWrite: canWrite, refresh: refresh,
			eur: function (v) { return eur(ui, v); },
			table: function (head, rows, nums) { return table(ui, head, rows, nums); },
			pager: function (d, go) { return pager(ui, d, go); },
			chips: function (items, current, onPick) { return chips(ui, items, current, onPick); },
			fail: function (host, e) { fail(host, ui, e); },
			qs: qs, dateRange: function (von, bis, label, onGo) { return dateRange(ui, von, bis, label, onGo); },
			loading: function (host) { host.textContent = ''; host.appendChild(h('div', { class: 'card' }, 'Wird geladen …')); },
			status: function (s) { return status(s); },
			download: download,
			goTab: function (k) { tab = k; ui.store('lw16erp-buch-tab', k); drawTabs(); show(); }
		};
		UI.zt = ctx;

		function loading() { box.textContent = ''; box.appendChild(h('div', { class: 'card' }, 'Wird geladen …')); }

		function list(path, head, rowFn, nums, hint) {
			function go(seite) {
				loading();
				ui.api('zahlen/buchhaltung/' + path + '?seite=' + seite).then(function (d) {
					box.textContent = '';
					var c = h('div', { class: 'card' });
					if (hint) { c.appendChild(h('p', { class: 'muted', style: 'font-size:13px' }, hint)); }
					if (!d.rows.length) { c.appendChild(h('p', { class: 'muted' }, 'Hier gibt es noch keine Einträge.')); }
					else { c.appendChild(table(ui, head, d.rows.map(rowFn), nums)); }
					var pg = pager(ui, d, go);
					if (pg) { c.appendChild(pg); }
					box.appendChild(c);
				}).catch(function (e) { fail(box, ui, e); });
			}
			go(1);
		}

		function status(s) {
			var t = { storniert: 'storniert', festgeschrieben: 'festgeschrieben', offen: 'offen' }[s] || s;
			return h('span', { class: 'tag ' + (s === 'festgeschrieben' ? 'now' : (s === 'offen' ? 'later' : '')) }, t);
		}

		// Rückfall-Reiter (nur lesend), solange kein Reiter derselben Art eingetragen ist.
		var CORE = {
			belege: { label: 'Belege', order: 10, render: function () {
				list('belege', ['Nr.', 'Datum', 'Händler', 'Kategorie', 'Betrag', 'Quelle', 'Foto', 'Status'], function (b) {
					return { cls: b.status === 'storniert' ? 'zt-storno' : null, cells: [b.nummer, b.datum, b.haendler, b.kategorie, eur(ui, b.betrag), b.quelle,
						b.datei ? h('a', { href: b.datei, target: '_blank', rel: 'noopener' }, 'ansehen') : '–', status(b.status)] };
				}, [4]);
			} },
			rechnungen: { label: 'Rechnungen', order: 20, render: function () {
				list('rechnungen', ['Rechnungsnr.', 'Bestellung', 'Betrag', 'Erstellt', 'Status'], function (r) {
					return { cls: r.storniert ? 'zt-storno' : null, cells: [h('a', { href: r.url, target: '_blank', rel: 'noopener' }, r.nummer), '#' + r.bestellung, eur(ui, r.betrag), r.erstellt, status(r.storniert ? 'storniert' : 'festgeschrieben')] };
				}, [2]);
			} },
			journal: { label: 'Journal', order: 40, render: function () {
				list('journal', ['Nr.', 'Datum', 'Typ', 'Betrag', 'Kategorie', 'Beschreibung', 'Status'], function (b) {
					return { cls: b.storniert ? 'zt-storno' : null, cells: [b.nummer, b.datum, b.typ, eur(ui, b.betrag), b.kategorie, b.beschreibung, b.storniert ? 'storniert' : 'aktiv'] };
				}, [3]);
			} },
			euer: { label: 'EÜR', order: 70, render: function () { euer({}); } },
			gobd: { label: 'GoBD-Prüfung', order: 80, render: function () { gobd(); } },
			export: { label: 'Betriebsprüfung', order: 90, render: function () { exportTab(); } }
		};

		function tabList() {
			var all = {};
			Object.keys(CORE).forEach(function (k) { all[k] = CORE[k]; });
			Object.keys(UI.buchTabs).forEach(function (k) { all[k] = UI.buchTabs[k]; });

			return Object.keys(all).map(function (k) { return { key: k, label: all[k].label, order: all[k].order || 100, tab: all[k], core: CORE[k] === all[k] }; })
				.sort(function (a, b) { return a.order - b.order; });
		}

		function drawTabs() {
			var list = tabList();
			if (!list.some(function (t) { return t.key === tab; })) { tab = list[0].key; }
			bar.textContent = '';
			bar.appendChild(chips(ui, list, tab, function (k) { tab = k; ui.store('lw16erp-buch-tab', k); drawTabs(); show(); }));
		}

		function show() {
			var t = tabList().filter(function (x) { return x.key === tab; })[0];
			if (!t) { return; }
			if (t.core) { t.tab.render(); return; }
			box.textContent = '';
			try { t.tab.render(box, ctx); } catch (e) { fail(box, ui, e); }
		}

		function euer(q) {
			loading();
			ui.api('zahlen/buchhaltung/euer?' + qs(q)).then(function (d) {
				box.textContent = '';
				var rows = [{ cells: [h('b', null, 'Einnahmen (WooCommerce, abzgl. Refunds)'), eur(ui, d.einnahmen)] }];
				rows.push({ cells: [h('b', null, 'Ausgaben nach Kontengruppe (SKR03)'), ''] });
				d.gruppen.forEach(function (g) { rows.push({ cells: ['   ' + g.name, eur(ui, g.summe)] }); });
				if (d.kuerzung > 0) { rows.push({ cells: ['   darin nicht abziehbarer Anteil (z. B. 30 % der Bewirtung)', '− ' + eur(ui, d.kuerzung)] }); }
				rows.push({ cells: [h('b', null, 'Ausgaben gesamt (abziehbar)'), eur(ui, d.ausgaben)] });
				rows.push({ cls: 'zt-sum', cells: [h('b', null, 'Gewinn'), h('b', null, eur(ui, d.gewinn))] });
				box.appendChild(h('div', { class: 'card' },
					dateRange(ui, d.von, d.bis, 'Berechnen', function (v, b) { euer({ von: v, bis: b }); }),
					table(ui, ['Position', 'Betrag'], rows, [1]),
					d.kleinunternehmer ? h('p', { class: 'muted', style: 'font-size:13px' }, 'Kleinunternehmer §19 UStG: keine Umsatzsteuer ausgewiesen, alle Beträge brutto.') : null,
					h('p', null, h('a', { class: 'btn primary', href: d.pdf_url, target: '_blank', rel: 'noopener' }, ui.ico('download'), 'EÜR als PDF exportieren (inkl. Belege)'))));
			}).catch(function (e) {
				fail(box, ui, e);
				box.appendChild(h('p', null, h('button', { class: 'btn', type: 'button', onclick: function () { euer({}); } }, 'Zeitraum zurücksetzen')));
			});
		}

		function gobd() {
			loading();
			ui.api('zahlen/buchhaltung/gobd').then(function (d) {
				box.textContent = '';
				var yi = h('input', { class: 'field', type: 'number', value: String(new Date().getFullYear()), min: '2020', max: '2099', 'aria-label': 'Jahr' });
				var out = h('div');
				var res = h('div');
				function results(list) {
					res.textContent = '';
					if (!list.length) { return; }
					res.appendChild(h('h3', { class: 'zt-h' }, 'Letzte Prüfungen'));
					res.appendChild(table(ui, ['Datum', 'Jahr', 'Ergebnis', 'Fehler', 'Warnungen'], list.map(function (r) {
						return { cells: [r.datum, r.jahr, h('span', { class: 'tag ' + (r.ergebnis === 'ok' ? 'now' : 'later') }, r.ergebnis.toUpperCase()), String(r.fehler), String(r.warnungen)] };
					}), [3, 4]));
				}
				results(d.ergebnisse);
				box.appendChild(h('div', { class: 'card' },
					h('p', null, 'Prüft Hash-Ketten, Nummern-Lückenlosigkeit und zeitgerechte Erfassung. Empfehlung: mindestens jährlich vor dem Jahresabschluss durchführen.'),
					h('div', { class: 'label-area' }, yi, h('button', { class: 'btn primary', type: 'button', onclick: function (ev) {
						var btn = ev.target;
						btn.disabled = true;
						out.textContent = 'Prüfung läuft …';
						ui.api('zahlen/buchhaltung/gobd', { method: 'POST', body: { jahr: parseInt(yi.value, 10) } }).then(function (r) {
							btn.disabled = false;
							out.textContent = '';
							out.appendChild(h('div', { class: 'notice' + (r.status === 'ok' ? '' : ' warn') }, r.message));
							(r.pruefungen || []).forEach(function (p) {
								out.appendChild(h('div', { class: 'muted', style: 'font-size:13px;margin-top:4px' }, p.name + ': ' + p.status + (p.details ? ' – ' + p.details : '')));
							});
							results(r.ergebnisse || []);
						}).catch(function (e) { btn.disabled = false; out.textContent = ''; ui.toast(e.message, true); });
					} }, 'Prüfung starten')),
					out),
					res);
			}).catch(function (e) { fail(box, ui, e); });
		}

		function exportTab() {
			loading();
			ui.api('zahlen/buchhaltung').then(function (d) {
				box.textContent = '';
				function field(n, v) { return h('input', { type: 'hidden', name: n, value: v }); }
				var form = h('form', { method: 'post', action: d.admin_post, class: 'zt-form' },
					field('action', 'lw16_bp_export'), field('_wpnonce', d.bp_nonce),
					h('div', { class: 'label-area' }, h('label', null, h('span', { class: 'muted' }, 'Von'), h('input', { class: 'field', type: 'date', name: 'from', value: d.jahr + '-01-01' })),
						h('label', null, h('span', { class: 'muted' }, 'Bis'), h('input', { class: 'field', type: 'date', name: 'to', value: d.jahr + '-12-31' }))),
					h('button', { class: 'btn primary', type: 'submit' }, ui.ico('download'), 'ZIP-Export herunterladen'));
				box.appendChild(h('div', { class: 'card' },
					h('h3', null, 'Export für Betriebsprüfung'),
					h('p', null, 'Strukturierter Datenexport gemäß §147 Abs. 6 AO (Z3: Datenträgerüberlassung). Enthält alle Belege, Rechnungen, Buchungen, Audit-Log und EÜR-Zusammenfassung als CSV-Dateien mit maschinenlesbarer Strukturbeschreibung (INDEX.xml).'),
					form,
					h('p', { style: 'margin-top:14px' }, h('a', { class: 'btn', href: d.doku_url, target: '_blank', rel: 'noopener' }, 'Verfahrensdokumentation als PDF'))));
			}).catch(function (e) { fail(box, ui, e); });
		}

		refresh();
	};
})();
