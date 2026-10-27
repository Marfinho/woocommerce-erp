/*
 * ERP-Seite „Prognose & Planung“: Kennzahlen mit Prognoseband gegen Ziele,
 * Bedarfsprognose je Artikel und Farbe, Lieferzusage, Einstellungen und
 * geplante Ereignisse. Server: includes/Erp/Pages/Prognose.php. Alle Daten
 * gelangen über textContent bzw. setAttribute in die Seite, nie als HTML.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	var SVGNS = 'http://www.w3.org/2000/svg';
	var STATUS = {
		gruen: ['ok', 'Ziel wahrscheinlich erreicht'],
		gelb: ['warn', 'Ziel gefährdet'],
		rot: ['bad', 'Ziel kaum erreichbar'],
		neutral: ['', 'Kein Ziel gesetzt']
	};
	var LEVEL = { normal: 'Normal', attention: 'Aufmerksamkeit', critical: 'Kritisch' };
	var REL = { hoch: 'Güte hoch', mittel: 'Güte mittel', niedrig: 'Güte niedrig', keine: 'Zu wenig Verlauf für eine Güteaussage' };

	function esvg(tag, attrs, text) {
		var n = document.createElementNS(SVGNS, tag);
		Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
		if (text) { n.textContent = text; }
		return n;
	}

	function nf(v, d) {
		if (v === null || v === undefined) { return '—'; }
		return new Intl.NumberFormat('de-DE', { minimumFractionDigits: d || 0, maximumFractionDigits: d === undefined ? 0 : d }).format(v);
	}

	function val(unit, v, d) {
		if (v === null || v === undefined) { return '—'; }
		if (unit === '€') { return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(v); }
		return nf(v, d === undefined ? (unit === '%' ? 1 : (unit ? 1 : 0)) : d) + (unit ? ' ' + unit : '');
	}

	function pct(p) { return p === null || p === undefined ? '—' : Math.round(p * 100) + ' %'; }

	/* ---------- Diagramm: Verlauf, Prognose, Bänder, Ziel ---------- */

	/*
	 * points: [{label, value?, mean?, lo80?, hi80?, lo95?, hi95?, target?, alarm?}]
	 * Verlauf = value, Prognose = mean mit Bändern. Die Skala beginnt bei null;
	 * Lücken bleiben Lücken; Ziel- und Alarmlinien sind eigene Linien.
	 */
	function fanChart(ui, points, unit, title) {
		var W = 720, H = 260, L = 70, R = 14, T = 14, B = 38;
		var vals = [];
		points.forEach(function (p) {
			['value', 'mean', 'hi95', 'hi80', 'target', 'alarm', 'actual'].forEach(function (k) { if (p[k] !== null && p[k] !== undefined) { vals.push(p[k]); } });
		});
		var max = vals.length ? Math.max.apply(null, vals) * 1.1 : 1;
		if (max <= 0) { max = 1; }
		var n = points.length;
		function x(i) { return n < 2 ? (L + W - R) / 2 : L + (W - L - R) * i / (n - 1); }
		function y(v) { return T + (H - T - B) * (1 - Math.max(0, Math.min(v, max)) / max); }

		var svg = esvg('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'pg-chart', role: 'img', 'aria-label': title });
		svg.appendChild(esvg('title', {}, title));

		for (var g = 0; g <= 4; g++) {
			var gv = max * g / 4;
			svg.appendChild(esvg('line', { x1: L, x2: W - R, y1: y(gv), y2: y(gv), class: 'grid' }));
			svg.appendChild(esvg('text', { x: L - 6, y: y(gv) + 4, 'text-anchor': 'end', class: 'ax' }, val(unit, gv, unit === '€' ? 0 : 1)));
		}

		var stride = Math.max(1, Math.ceil(n / 9));
		points.forEach(function (p, i) {
			if (i % stride === 0 || i === n - 1) { svg.appendChild(esvg('text', { x: x(i), y: H - 14, 'text-anchor': 'middle', class: 'ax' }, p.label)); }
		});

		function band(lo, hi, cls) {
			var up = [], down = [];
			points.forEach(function (p, i) {
				if (p[lo] === null || p[lo] === undefined || p[hi] === null || p[hi] === undefined) { return; }
				up.push(x(i).toFixed(1) + ' ' + y(p[hi]).toFixed(1));
				down.unshift(x(i).toFixed(1) + ' ' + y(p[lo]).toFixed(1));
			});
			if (up.length > 1) { svg.appendChild(esvg('path', { d: 'M' + up.join(' L') + ' L' + down.join(' L') + ' Z', class: cls })); }
		}
		band('lo95', 'hi95', 'band95');
		band('lo80', 'hi80', 'band80');

		function line(field, cls) {
			var d = '', open = false;
			points.forEach(function (p, i) {
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
		line('actual', 'ln value');
		line('mean', 'ln mean');

		// Trennlinie „heute“ zwischen Ist und Prognose.
		var firstFc = -1;
		points.forEach(function (p, i) { if (firstFc < 0 && p.mean !== undefined && p.mean !== null) { firstFc = i; } });
		if (firstFc > 0) {
			var xm = (x(firstFc - 1) + x(firstFc)) / 2;
			svg.appendChild(esvg('line', { x1: xm, x2: xm, y1: T, y2: H - B, class: 'today' }));
			svg.appendChild(esvg('text', { x: xm + 4, y: T + 10, class: 'ax' }, 'heute'));
		}

		points.forEach(function (p, i) {
			var v = p.value !== undefined && p.value !== null ? p.value : (p.actual !== undefined && p.actual !== null ? p.actual : p.mean);
			if (v === null || v === undefined) { return; }
			var c = esvg('circle', { cx: x(i), cy: y(v), r: 3.5, class: 'pt' + (p.mean !== undefined && p.mean !== null ? ' fc' : '') });
			var tip = p.label + ': ' + val(unit, v);
			if (p.lo80 !== undefined && p.lo80 !== null) { tip += ' (80 %: ' + val(unit, p.lo80) + ' bis ' + val(unit, p.hi80) + ')'; }
			c.appendChild(esvg('title', {}, tip));
			svg.appendChild(c);
		});

		return svg;
	}

	function legend(ui, hasAlarm) {
		var h = ui.h;
		return h('p', { class: 'pg-legend muted' },
			h('span', { class: 'k value' }, 'Ist'), h('span', { class: 'k mean' }, 'Erwartung'),
			h('span', { class: 'k b80' }, '80-%-Band'), h('span', { class: 'k b95' }, '95-%-Band'),
			h('span', { class: 'k target' }, 'Ziel'), hasAlarm ? h('span', { class: 'k alarm' }, 'Alarmgrenze') : null);
	}

	function dataTable(ui, rows, unit) {
		var h = ui.h;
		var body = h('tbody');
		rows.forEach(function (p) {
			body.appendChild(h('tr', null,
				h('td', null, p.label),
				h('td', { class: 'num' }, val(unit, p.value !== undefined && p.value !== null ? p.value : (p.actual !== undefined && p.actual !== null ? p.actual : p.mean))),
				h('td', { class: 'num' }, p.lo80 !== undefined && p.lo80 !== null ? val(unit, p.lo80) + ' – ' + val(unit, p.hi80) : '—'),
				h('td', { class: 'num' }, p.target !== undefined && p.target !== null ? val(unit, p.target) : '—')));
		});
		return h('details', { class: 'pg-data' }, h('summary', null, 'Zahlen zum Diagramm'),
			h('div', { class: 'zt-scroll' }, h('table', { class: 'list' },
				h('thead', null, h('tr', null, h('th', null, 'Zeitraum'), h('th', { class: 'num' }, 'Wert / Erwartung'), h('th', { class: 'num' }, '80-%-Band'), h('th', { class: 'num' }, 'Ziel'))), body)));
	}

	/* ---------- Kennzahlen-Karte ---------- */

	function meter(ui, p) {
		var h = ui.h;
		var lv = p === null ? '' : (p >= 0.5 ? 'gruen' : (p >= 0.15 ? 'gelb' : 'rot'));
		var bar = h('div', { class: 'pg-meter ' + lv, role: 'img', 'aria-label': 'Wahrscheinlichkeit ' + pct(p) }, h('i', { style: 'width:' + (p === null ? 0 : Math.round(p * 100)) + '%' }));
		return bar;
	}

	function reliabilityNote(k) {
		var t = REL[k.reliability] || '';
		if (k.accuracy !== null && k.accuracy !== undefined) { t += ' – Einschrittprognosen lagen zuletzt im Schnitt ' + nf(k.accuracy, 0) + ' % daneben'; }
		return t + (k.method === 'holt-saison' ? ', mit Saison' : '') + '.';
	}

	function kpiCard(ui, k) {
		var h = ui.h;
		var st = STATUS[k.status] || STATUS.neutral;
		var card = h('section', { class: 'card pg-kpi ' + st[0] });
		card.appendChild(h('div', { class: 'pg-head' },
			h('h3', null, k.label),
			h('span', { class: 'pg-tag ' + (k.status === 'gruen' ? 'ok' : (k.status === 'gelb' ? 'warn' : (k.status === 'rot' ? 'bad' : ''))) }, st[1])));

		if (k.type === 'sum') {
			var q = k.quarter;
			var pj = q.projection;
			card.appendChild(h('div', { class: 'pg-facts' },
				fact('Ist bis heute', val(k.unit, q.actual), q.label),
				fact('Prognose Quartalsende', val(k.unit, pj.mean), '80 %: ' + val(k.unit, pj.lo80) + ' – ' + val(k.unit, pj.hi80)),
				fact('Ziel', q.target === null ? '—' : val(k.unit, q.target), q.alarm === null ? 'keine Alarmgrenze' : 'Alarm unter ' + val(k.unit, q.alarm)),
				fact('Chance aufs Ziel', pct(k.p_goal), q.required === null ? '' : 'Dazu nötig: ' + val(k.unit, q.required) + ' pro Woche (' + nf(q.weeks_left, 1) + ' Wochen)')));
			if (k.p_goal !== null) { card.appendChild(meter(ui, k.p_goal)); }
			if (k.p_alarm !== null && k.p_alarm >= 0.2) {
				card.appendChild(h('div', { class: 'notice warn' }, 'Mit ' + pct(k.p_alarm) + ' Wahrscheinlichkeit landet das Quartal unter der Alarmgrenze.'));
			}
			if (q.target === null) {
				card.appendChild(h('p', { class: 'muted' }, 'Für dieses Quartal ist kein Ziel eingetragen. ', h('a', { href: '#/seite/berichte' }, 'Ziele unter Berichte setzen'), ' – dann erscheinen Wahrscheinlichkeit und Auslöser.'));
			}
			card.appendChild(h('h4', { class: 'pg-sub' }, 'Quartalspfad (kumuliert)'));
			card.appendChild(h('div', { class: 'zt-chartbox' }, fanChart(ui, k.cumulative, k.unit, 'Kumulierter Quartalspfad ' + k.label)));
			card.appendChild(legend(ui, false));
			card.appendChild(dataTable(ui, k.cumulative, k.unit));

			var weekly = k.history.map(function (p) { return { label: p.label, value: p.value, target: p.target }; })
				.concat(k.forecast.map(function (p) { return { label: p.label, mean: p.mean, lo80: p.lo80, hi80: p.hi80, lo95: p.lo95, hi95: p.hi95, target: p.target }; }));
			var det = h('details', { class: 'pg-more' }, h('summary', null, 'Wochenverlauf und Prognose'));
			det.appendChild(h('div', { class: 'zt-chartbox' }, fanChart(ui, weekly, k.unit, 'Wochenverlauf ' + k.label)));
			det.appendChild(legend(ui, false));
			card.appendChild(det);
		} else {
			var f = k.forecast[0];
			card.appendChild(h('div', { class: 'pg-facts' },
				fact('Erwartung ' + f.label, val(k.unit, f.mean), '80 %: ' + val(k.unit, f.lo80) + ' – ' + val(k.unit, f.hi80)),
				fact('Ziel', f.target === null ? '—' : val(k.unit, f.target), f.alarm === null ? '' : 'Alarm bei ' + val(k.unit, f.alarm)),
				fact('Chance aufs Ziel', pct(k.p_goal), ''),
				fact('Risiko Alarmgrenze', pct(k.p_alarm), '')));
			if (k.p_goal !== null) { card.appendChild(meter(ui, k.p_goal)); }
			var pts = k.history.map(function (p) { return { label: p.label, value: p.value, target: p.target, alarm: p.alarm }; })
				.concat(k.forecast.map(function (p) { return { label: p.label, mean: p.mean, lo80: p.lo80, hi80: p.hi80, lo95: p.lo95, hi95: p.hi95, target: p.target, alarm: p.alarm }; }));
			card.appendChild(h('div', { class: 'zt-chartbox' }, fanChart(ui, pts, k.unit, 'Verlauf und Prognose ' + k.label)));
			card.appendChild(legend(ui, true));
			card.appendChild(dataTable(ui, pts, k.unit));
		}

		card.appendChild(h('p', { class: 'muted pg-rel' }, reliabilityNote(k)));
		return card;

		function fact(label, value, note) {
			return h('div', { class: 'pg-fact' }, h('div', { class: 'lab' }, label), h('div', { class: 'big' }, value), note ? h('div', { class: 'muted' }, note) : null);
		}
	}

	/* ---------- Seite ---------- */

	UI.pages['prognose'] = function (item, ui) {
		var h = ui.h;
		ui.pageHead('Prognose & Planung', 'Wohin sich Umsatz, Bestellungen und Quoten entwickeln – mit Band, Ziel und Auslösern.', ui.classicBtn(item));

		var tabs = [['kpi', 'Kennzahlen'], ['bedarf', 'Artikelbedarf'], ['zusage', 'Lieferzusage'], ['einstellungen', 'Auslöser & Ereignisse']];
		var current = 'kpi';
		var strip = h('div', { class: 'tabs', role: 'tablist' });
		var box = h('div');
		ui.view().appendChild(strip);
		ui.view().appendChild(box);

		function post(path, body) { return ui.api(path, { method: 'POST', body: body || {} }); }
		function fail(e) { box.textContent = ''; box.appendChild(h('div', { class: 'notice warn' }, e.message)); }
		function loading() { box.textContent = ''; box.appendChild(h('div', { class: 'card' }, 'Wird berechnet …')); }

		function drawTabs() {
			strip.textContent = '';
			tabs.forEach(function (t) {
				strip.appendChild(h('button', {
					class: 'tabx' + (t[0] === current ? ' on' : ''), type: 'button', role: 'tab', 'aria-selected': t[0] === current ? 'true' : 'false',
					onclick: function () { current = t[0]; drawTabs(); show(); }
				}, t[1]));
			});
		}

		function show() {
			loading();
			({ kpi: kpis, bedarf: bedarf, zusage: zusage, einstellungen: einstellungen })[current]();
		}

		/* --- Kennzahlen --- */
		function kpis() {
			ui.api('prognose').then(function (d) {
				box.textContent = '';
				if (!d.kpis.length) {
					box.appendChild(h('div', { class: 'notice' }, 'Noch zu wenig Verlauf: Für eine Prognose braucht es Bestellungen aus mindestens einigen Wochen.'));
					return;
				}
				var worst = d.kpis.filter(function (k) { return k.status === 'rot'; }).length;
				var warn = d.kpis.filter(function (k) { return k.status === 'gelb'; }).length;
				box.appendChild(h('div', { class: 'notice ' + (worst ? 'warn' : '') },
					worst + ' Kennzahl(en) kaum erreichbar, ' + warn + ' gefährdet. ',
					d.last_run ? 'Auslöser zuletzt geprüft: ' + ui.when(d.last_run) + '.' : 'Auslöser noch nie geprüft.'));
				var pending = h('button', { class: 'btn', type: 'button' }, 'Auslöser jetzt prüfen');
				pending.addEventListener('click', function () {
					pending.disabled = true;
					post('prognose/pruefen').then(function (r) { pending.disabled = false; ui.toast(r.message); }, function (e) { pending.disabled = false; ui.toast(e.message, true); });
				});
				box.appendChild(h('p', null, pending));
				d.kpis.forEach(function (k) { box.appendChild(kpiCard(ui, k)); });
			}).catch(fail);
		}

		/* --- Artikelbedarf --- */
		function reachText(r) {
			return r === null || r === undefined ? '> 12 Wochen' : nf(r, 1) + ' Wochen';
		}

		function bedarf() {
			ui.api('prognose/artikel').then(function (d) {
				box.textContent = '';
				if (!d.rows.length) { box.appendChild(h('div', { class: 'notice' }, 'Noch keine Verkäufe von ERP-Artikeln im Verlauf.')); return; }
				box.appendChild(h('p', { class: 'muted' }, 'Prognose je Artikel aus den Wochenverkäufen der letzten 26 Wochen. Empfohlen wird so viel, dass Vorlauf (' + nf(d.settings.lead_weeks, 1) + ' Wochen) und Reichweite (' + nf(d.settings.cover_weeks, 1) + ' Wochen) mit ' + d.settings.service_level + ' % Sicherheit gedeckt sind – abzüglich Bestand, offener Bestellungen und laufender Fertigung.'));
				var body = h('tbody');
				d.rows.forEach(function (r) {
					body.appendChild(rowFor(r, false));
					r.colors.forEach(function (c) { body.appendChild(rowFor(c, true, r)); });
				});
				box.appendChild(h('div', { class: 'card' }, h('div', { class: 'zt-scroll' }, h('table', { class: 'list pg-art' },
					h('thead', null, h('tr', null, h('th', null, 'Artikel'), h('th', { class: 'num' }, 'Verfügbar'), h('th', { class: 'num' }, 'Ø je Woche (80 %)'), h('th', null, 'Reicht (erwartet · schlecht · gut)'), h('th', { class: 'num' }, 'Sicherheit'), h('th', { class: 'num' }, 'Empfohlen'), h('th', null, 'Güte'), h('th', null, 'Stufe'))),
					body))));

				function rowFor(r, isColor) {
					var lv = r.level === 'critical' ? 'bad' : (r.level === 'attention' ? 'warn' : 'ok');
					return h('tr', { class: isColor ? 'pg-color' : null },
						h('td', null, isColor ? '↳ ' + r.label : r.name, isColor ? null : h('div', { class: 'muted' }, r.sku)),
						h('td', { class: 'num' }, nf(r.available)),
						h('td', { class: 'num' }, nf(r.per_week, 1) + ' (' + nf(r.per_week_lo, 1) + '–' + nf(r.per_week_hi, 1) + ')'),
						h('td', null, reachText(r.reach) + ' · ' + reachText(r.reach_worst) + ' · ' + reachText(r.reach_best)),
						h('td', { class: 'num' }, nf(r.safety)),
						h('td', { class: 'num' }, r.recommended ? h('strong', null, nf(r.recommended)) : '0'),
						h('td', null, REL[r.reliability] ? REL[r.reliability].replace('Güte ', '') : '—'),
						h('td', null, isColor ? '' : h('span', { class: 'pg-tag ' + lv }, LEVEL[r.level])));
				}
			}).catch(fail);
		}

		/* --- Lieferzusage --- */
		function zusage() {
			ui.api('prognose/artikel').then(function (d) {
				box.textContent = '';
				var sel = h('select', { class: 'field' });
				d.articles.forEach(function (a) { sel.appendChild(h('option', { value: a.id }, a.label)); });
				var qty = h('input', { class: 'field', type: 'number', min: '1', step: '1', value: '10', style: 'max-width:120px' });
				var color = h('input', { class: 'field', type: 'text', placeholder: 'Farbe (optional, z. B. anthrazit)' });
				var go = h('button', { class: 'btn primary', type: 'submit' }, 'Wann kann ich liefern?');
				var out = h('div');
				var form = h('form', { class: 'vw-form' },
					h('label', { class: 'vw-f' }, h('span', null, 'Artikel'), sel),
					h('label', { class: 'vw-f' }, h('span', null, 'Menge'), qty),
					h('label', { class: 'vw-f' }, h('span', null, 'Farbe'), color), go);
				form.addEventListener('submit', function (e) {
					e.preventDefault();
					go.disabled = true;
					post('prognose/zusage', { article_id: parseInt(sel.value, 10), qty: parseFloat(qty.value), color: color.value }).then(function (r) {
						go.disabled = false;
						paintPromise(out, r.result);
					}, function (er) { go.disabled = false; ui.toast(er.message, true); });
				});
				box.appendChild(h('section', { class: 'card' }, h('h3', null, 'Lieferzusage'),
					h('p', { class: 'muted' }, 'Was im Lager liegt (nach Abzug offener Bestellungen) geht sofort. Für den Rest zählt, was vorher in der Fertigung ansteht und wie viel Druck- und Handarbeitszeit eine Woche hergibt. Das späte Datum rechnet mit 70 % der Kapazität – das nennst du dem Kunden.'),
					form, out));
			}).catch(fail);
		}

		function paintPromise(out, r) {
			out.textContent = '';
			var lines = [];
			if (r.unknown) { out.appendChild(h('div', { class: 'notice warn' }, r.hint)); return; }
			if (r.immediate) {
				out.appendChild(h('div', { class: 'notice' }, 'Sofort lieferbar: ' + nf(r.available) + ' ' + r.name + ' sind frei (angefragt ' + nf(r.qty) + ').'));
				return;
			}
			lines.push(nf(r.available) + ' sofort, ' + nf(r.missing) + ' müssen gefertigt werden.');
			if (r.date_from) {
				lines.push('Fertigung braucht ca. ' + nf(r.weeks, 1) + ' Wochen, begrenzt durch ' + (r.limiting === 'arbeitszeit' ? 'die Arbeitszeit' : 'die Druckzeit') + '.');
				lines.push('Frühestens lieferbar zwischen ' + dmy(r.date_from) + ' und ' + dmy(r.date_to) + ' (inkl. Versand).');
			} else {
				lines.push('Ohne hinterlegte Kapazität lässt sich kein Termin errechnen (Einstellungen → Kalkulation).');
			}
			out.appendChild(h('div', { class: 'notice warn' }, lines.map(function (l) { return h('div', null, l); })));
		}

		function dmy(iso) { var p = iso.split('-'); return p[2] + '.' + p[1] + '.' + p[0]; }

		/* --- Einstellungen und Ereignisse --- */
		function einstellungen() {
			ui.api('prognose').then(function (d) {
				box.textContent = '';
				var s = d.settings;
				function num(v, attrs) { return h('input', Object.assign({ class: 'field', type: 'number', value: String(v), style: 'max-width:120px' }, attrs || {})); }
				var pa = num(s.p_attention, { min: '5', max: '95' });
				var pc = num(s.p_critical, { min: '1', max: '90' });
				var sl = num(s.service_level, { min: '50', max: '99' });
				var lead = num(s.lead_weeks, { min: '0', max: '12', step: '0.5' });
				var cover = num(s.cover_weeks, { min: '0.5', max: '12', step: '0.5' });
				var useModel = h('input', { type: 'checkbox' }); useModel.checked = !!s.use_model;
				var shopText = h('input', { type: 'checkbox' }); shopText.checked = !!s.shop_text;
				var go = h('button', { class: 'btn primary', type: 'submit' }, 'Speichern');
				function f(label, el, hint) { return h('label', { class: 'vw-f' }, h('span', null, label), el, hint ? h('small', { class: 'muted' }, hint) : null); }

				var form = h('form', { class: 'vw-form' },
					f('Aufmerksamkeit, wenn die Chance aufs Ziel unter … % liegt', pa, 'Löst den Eintrag „Aufmerksamkeit“ im Ereignisprotokoll aus.'),
					f('Kritisch, wenn die Chance unter … % liegt', pc, 'Außerdem kritisch: Die Alarmgrenze wird mit mindestens 50 % Wahrscheinlichkeit verletzt.'),
					f('Dienstgrad für Fertigungsempfehlungen (%)', sl, 'Mit welcher Sicherheit der Bedarf gedeckt sein soll. 90 % heißt: Neun von zehn Wochen reicht der Bestand.'),
					f('Vorlauf der Fertigung (Wochen)', lead, 'Wie lange es von der Entscheidung bis zur fertigen Ware dauert.'),
					f('Reichweite nach dem Vorlauf (Wochen)', cover, ''),
					h('label', { class: 'vw-check' }, useModel, h('span', null, 'Prognosemodell für den Fertigungsbedarf verwenden (statt Wochenmittel mal Reichweite) – gilt für Artikel mit mindestens acht Wochen Verlauf.')),
					h('label', { class: 'vw-check' }, shopText, h('span', null, 'Verfügbarkeit und Lieferzeit im Shop anzeigen („Auf Lager“, „Nur noch 2“, „lieferbar in ca. 9 Tagen“).')),
					go);
				form.addEventListener('submit', function (e) {
					e.preventDefault();
					go.disabled = true;
					post('prognose/einstellungen', {
						p_attention: parseInt(pa.value, 10), p_critical: parseInt(pc.value, 10), service_level: parseInt(sl.value, 10),
						lead_weeks: parseFloat(lead.value), cover_weeks: parseFloat(cover.value), use_model: useModel.checked, shop_text: shopText.checked
					}).then(function (r) { go.disabled = false; ui.toast(r.message); einstellungen(); }, function (er) { go.disabled = false; ui.toast(er.message, true); });
				});
				box.appendChild(h('section', { class: 'card' }, h('h3', null, 'Auslöser'),
					h('p', { class: 'muted' }, 'Täglich prüft das System alle Kennzahlen und Artikel gegen diese Schwellen. Gemeldet wird nur, wenn sich eine Stufe ändert – im Ereignisprotokoll unter Aufmerksamkeit bzw. Kritisch.'),
					form));

				box.appendChild(eventsCard(d));
			}).catch(fail);
		}

		function eventsCard(d) {
			var rows = d.events.slice();
			var list = h('div');
			function draw() {
				list.textContent = '';
				rows.forEach(function (r, i) {
					var name = h('input', { class: 'field', type: 'text', value: r.name, placeholder: 'Markt, Aktion …' });
					var von = h('input', { class: 'field', type: 'date', value: r.von });
					var bis = h('input', { class: 'field', type: 'date', value: r.bis });
					var p = h('input', { class: 'field', type: 'number', value: String(r.pct), step: '5', style: 'max-width:90px', 'aria-label': 'Aufschlag in Prozent' });
					name.addEventListener('input', function () { r.name = name.value; });
					von.addEventListener('input', function () { r.von = von.value; });
					bis.addEventListener('input', function () { r.bis = bis.value; });
					p.addEventListener('input', function () { r.pct = parseFloat(p.value) || 0; });
					var del = h('button', { class: 'btn', type: 'button', 'aria-label': 'Ereignis entfernen' }, '✕');
					del.addEventListener('click', function () { rows.splice(i, 1); draw(); });
					list.appendChild(h('div', { class: 'pg-event' }, name, von, bis, p, h('span', { class: 'muted' }, '%'), del));
				});
				if (!rows.length) { list.appendChild(h('p', { class: 'muted' }, 'Keine Ereignisse geplant.')); }
			}
			draw();
			var add = h('button', { class: 'btn', type: 'button' }, '+ Ereignis');
			add.addEventListener('click', function () {
				var today = new Date().toISOString().slice(0, 10);
				rows.push({ name: '', von: today, bis: today, pct: 30 });
				draw();
			});
			var save = h('button', { class: 'btn primary', type: 'button' }, 'Ereignisse speichern');
			save.addEventListener('click', function () {
				save.disabled = true;
				post('prognose/ereignisse', { events: rows }).then(function (r) { save.disabled = false; ui.toast(r.message); }, function (e) { save.disabled = false; ui.toast(e.message, true); });
			});
			var hint = d.market.per_day !== null
				? 'Zur Orientierung: Die Märkte der letzten 12 Monate brachten ' + d.market.days + ' Kassentage mit durchschnittlich ' + val('€', d.market.per_day) + ' Umsatz je Tag.'
				: 'Noch keine abgeschlossenen Kassentage – der Aufschlag ist eine Schätzung.';
			return h('section', { class: 'card' }, h('h3', null, 'Geplante Ereignisse'),
				h('p', { class: 'muted' }, 'Märkte, Aktionen oder Messen heben den erwarteten Absatz in den betroffenen Wochen an – anteilig nach Tagen. Ein Aufschlag von 70 % an drei von sieben Tagen hebt die Woche um 30 % an. ' + hint),
				list, h('p', null, add, ' ', save));
		}

		drawTabs();
		show();
	};
})();
