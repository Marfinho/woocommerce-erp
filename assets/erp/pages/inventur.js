/*
 * ERP-Seite „Inventur“: Zählliste, Differenzen buchen, Bestandswert zum Stichtag.
 * Schnittstelle: includes/Erp/Pages/Inventur.php. Kein innerHTML mit Daten.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	UI.pages['inventur'] = function (item, ui) {
		var h = ui.h;
		var k = UI.kit(ui);
		var state = { tab: 'liste', q: '', f: 'offen' };
		var head = h('div');
		var body = h('div');
		var meta = null;

		ui.pageHead('Inventur', 'Zum Stichtag zählen, Differenzen als Korrektur buchen und den Bestandswert ermitteln.');
		ui.view().appendChild(head);
		ui.view().appendChild(body);

		function q3(n) { return String(Math.round((Number(n) || 0) * 1000) / 1000).replace('.', ','); }

		function tabs() {
			head.textContent = '';
			var items = [['liste', 'Inventuren']];
			if (meta && meta.can_value) { items.push(['wert', 'Bestandswert']); }
			if (items.length > 1) { head.appendChild(k.pills(items, state.tab, function (t) { state.tab = t; route(); })); }
		}

		function route() {
			tabs();
			if (state.tab === 'wert') { value(); } else { list(); }
		}

		/* ---------------- Liste ---------------- */

		function list() {
			k.loading(body);
			ui.api('inventur').then(function (d) {
				meta = d;
				tabs();
				body.textContent = '';
				var open = d.rows.filter(function (r) { return r.status === 'offen'; })[0];
				var bar = h('div', { class: 'kt-bar' }, h('span'));
				if (!open) {
					bar.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: newDialog }, 'Neue Inventur'));
				}
				body.appendChild(bar);
				var box = h('div', { class: 'rows' });
				if (!d.rows.length) { box.appendChild(h('div', { class: 'card muted' }, 'Noch keine Inventur. Mit „Neue Inventur“ wird der aktuelle Lagerbestand als Soll festgehalten.')); }
				d.rows.forEach(function (r) {
					box.appendChild(h('article', { class: 'row-card kt-row', style: 'cursor:pointer', onclick: function () { detail(r.id); } },
						h('div', { class: 'row-main' }, h('b', null, r.code + ' · ' + r.title),
							h('small', null, 'Stichtag ' + ui.when(r.snapshot_at) + (r.closed_at ? ' · abgeschlossen ' + ui.when(r.closed_at) : '')),
							h('div', { class: 'row-tags' }, k.tag(r.status === 'offen' ? 'läuft' : 'abgeschlossen', r.status === 'offen' ? 'later' : 'now'))),
						h('div', { class: 'kt-num' }, h('span', { class: 'row-num' }, r.counted + '/' + r.lines), h('small', { class: 'muted' }, 'gezählt')),
						h('div', { class: 'kt-btns' })));
				});
				body.appendChild(box);
			}).catch(function (e) { k.fail(body, e); });
		}

		function newDialog() {
			var title = k.input('', { placeholder: 'z. B. Jahresinventur 2026' });
			var only = h('input', { type: 'checkbox', checked: true });
			var parts = h('input', { type: 'checkbox', checked: true });
			k.formDialog('Neue Inventur', [
				k.field('Bezeichnung', title),
				h('label', { class: 'lg-chk' }, only, ' Nur Positionen mit Bestand auf die Liste (sonst auch alle mit Sollbestand)'),
				h('label', { class: 'lg-chk' }, parts, ' Kleinteile und Kartons mitzählen'),
				h('p', { class: 'muted' }, 'Der aktuelle Bestand im Lager wird als Soll festgehalten. Weiterverkaufen geht während des Zählens: Beim Abschluss zählt der Bestand zum Zeitpunkt, an dem die Zeile gezählt wurde.')
			], 'Anlegen', function () {
				return ui.api('inventur', { method: 'POST', body: { title: title.value, nur_bestand: only.checked ? 1 : 0, mit_teilen: parts.checked ? 1 : 0 } });
			}, function (r) { detail(r.id); });
		}

		/* ---------------- Zählen ---------------- */

		function detail(id) {
			k.loading(body);
			ui.api('inventur/' + id).then(function (d) { paintDetail(d); }).catch(function (e) { k.fail(body, e); });
		}

		function paintDetail(d) {
			body.textContent = '';
			var open = d.status === 'offen';
			var s = d.summary;
			var listBox = h('div', { class: 'rows' });
			var timer = null;

			body.appendChild(h('div', { class: 'kt-bar' },
				h('button', { class: 'btn', type: 'button', onclick: list }, '← Alle Inventuren'),
				h('b', null, d.code + ' · ' + d.title)));
			body.appendChild(h('div', { class: 'grid tiles' },
				k.tile('Gezählt', s.counted + ' von ' + s.lines),
				k.tile('Mit Differenz', s.with_diff),
				k.tile('Differenz Stück', q3(s.diff_qty)),
				s.diff_value !== null ? k.tile('Differenz Wert', k.eur(s.diff_value), 'Soll ' + k.eur(s.expected_value)) : null));

			var search = k.input(state.q, { type: 'search', placeholder: 'SKU, Name, Barcode oder Platz …', 'aria-label': 'Suchen' });
			search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { state.q = search.value.trim(); paintList(); }, 200); });
			var acts = h('div', { class: 'kt-btns' });
			if (open) {
				acts.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { addDialog(d); } }, '+ Artikel nachtragen'));
				if (d.can_close) {
					acts.appendChild(h('button', { class: 'btn primary', type: 'button', onclick: function () {
						k.confirmDo('Inventur abschließen', s.with_diff + ' Zeilen mit Differenz werden als Korrektur gebucht. ' + s.open + ' nicht gezählte Zeilen bleiben unverändert. Danach lässt sich nichts mehr zählen.', 'Abschließen und buchen', function () {
							return ui.api('inventur/' + d.id + '/abschliessen', { method: 'POST', body: {} });
						}, function () { detail(d.id); });
					} }, 'Abschließen'));
				}
			}
			acts.appendChild(k.fileBtn('Zählliste (PDF)', 'inventur/' + d.id + '/pdf'));
			acts.appendChild(k.fileBtn('Blind-Zählliste', 'inventur/' + d.id + '/pdf?blind=1'));
			acts.appendChild(k.fileBtn('Protokoll (CSV)', 'inventur/' + d.id + '/csv'));
			body.appendChild(h('div', { class: 'kt-bar' },
				k.pills([['offen', 'Noch zählen'], ['diff', 'Mit Differenz'], ['gezaehlt', 'Gezählt'], ['alle', 'Alle']], state.f, function (f) { state.f = f; paintDetail(d); }),
				acts));
			body.appendChild(search);
			body.appendChild(listBox);

			function save(l, value, input, mark) {
				ui.api('inventur/' + d.id + '/zaehlen', { method: 'POST', body: { line_id: l.id, qty: value } }).then(function () {
					l.counted = value === '' ? null : parseFloat(String(value).replace(',', '.'));
					input.value = value === '' ? '' : q3(l.counted);
					mark();
				}).catch(function (e) { ui.toast(e.message, true); });
			}

			function rowOf(l) {
				var input = k.input(l.counted === null ? '' : q3(l.counted), { inputmode: 'decimal', 'aria-label': 'Gezählt', style: 'width:96px', disabled: open ? null : true });
				var diffBox = h('small', { class: 'muted' });
				function mark() {
					diffBox.textContent = '';
					if (l.counted === null) { diffBox.appendChild(document.createTextNode('nicht gezählt')); return; }
					var diff = Math.round((l.counted - l.expected) * 1000) / 1000;
					diffBox.className = diff === 0 ? 'muted' : (diff > 0 ? 'kt-pos' : 'kt-neg');
					diffBox.appendChild(document.createTextNode(diff === 0 ? 'stimmt' : (diff > 0 ? '+' : '') + q3(diff)));
				}
				input.addEventListener('change', function () { save(l, input.value.trim(), input, mark); });
				input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); input.blur(); } });
				mark();
				return h('article', { class: 'row-card kt-row' },
					h('div', { class: 'row-main' },
						h('b', null, l.sku + ' · ' + l.color_label),
						h('small', null, l.name + (l.bin ? ' · Platz ' + l.bin : '') + (l.note ? ' · ' + l.note : ''))),
					h('div', { class: 'kt-num' }, h('span', { class: 'row-num' }, q3(l.expected)), h('small', { class: 'muted' }, 'Soll' + (l.unit_label && l.unit_label !== 'Stück' ? ' (' + l.unit_label + ')' : ''))),
					h('div', { class: 'kt-btns' },
						open && l.counted === null ? h('button', { class: 'btn', type: 'button', title: 'Gezählt = Soll', onclick: function () { save(l, String(l.expected), input, mark); } }, '= Soll') : null,
						input, diffBox));
			}

			function paintList() {
				listBox.textContent = '';
				var q = state.q.toLowerCase();
				var rows = d.lines.filter(function (l) {
					if (state.f === 'offen' && l.counted !== null) { return false; }
					if (state.f === 'gezaehlt' && l.counted === null) { return false; }
					if (state.f === 'diff' && (l.counted === null || Math.abs(l.counted - l.expected) < 0.0005)) { return false; }
					return !q || (l.sku + ' ' + l.name + ' ' + l.barcode + ' ' + l.bin + ' ' + l.color_label).toLowerCase().indexOf(q) > -1;
				});
				if (!rows.length) { listBox.appendChild(h('div', { class: 'card muted' }, state.f === 'offen' && !q ? 'Alles gezählt.' : 'Nichts gefunden.')); }
				rows.slice(0, 400).forEach(function (l) { listBox.appendChild(rowOf(l)); });
			}

			paintList();
		}

		function addDialog(d) {
			var kind = k.select([['article', 'Produkt'], ['part', 'Kleinteil oder Karton']], 'article', { 'aria-label': 'Art' });
			var art = k.select(d.articles, '', { 'aria-label': 'Artikel' });
			var part = k.select(d.parts || [], '', { 'aria-label': 'Kleinteil oder Karton' });
			var color = k.select(Object.keys(d.colors).map(function (c) { return [c, d.colors[c]]; }), '', { 'aria-label': 'Farbe' });
			var qty = k.input('', { inputmode: 'decimal', required: '' });
			var artRow = k.field('Artikel', art), colorRow = k.field('Farbe', color), partRow = k.field('Position', part);
			function toggle() { var p = kind.value === 'part'; artRow.hidden = p; colorRow.hidden = p; partRow.hidden = !p; }
			kind.addEventListener('change', toggle);
			toggle();
			k.formDialog('Nachtragen', [k.field('Was?', kind), artRow, colorRow, partRow, k.field('Gezählt', qty)], 'Nachtragen', function () {
				if (kind.value === 'part') {
					var bits = String(part.value).split(':');
					return ui.api('inventur/' + d.id + '/zeile', { method: 'POST', body: { item_type: bits[0], article_id: bits[1], qty: qty.value } });
				}
				return ui.api('inventur/' + d.id + '/zeile', { method: 'POST', body: { article_id: art.value, color: color.value, qty: qty.value } });
			}, function () { detail(d.id); });
		}

		/* ---------------- Bestandswert ---------------- */

		function value() {
			var date = k.input(new Date().toISOString().slice(0, 10), { type: 'date', 'aria-label': 'Stichtag' });
			var out = h('div');
			body.textContent = '';
			body.appendChild(h('div', { class: 'kt-bar' }, k.field('Stichtag', date),
				k.fileBtn('CSV', function () { return 'inventur/bestandswert?' + k.qs({ datum: date.value, csv: 1 }); })));
			body.appendChild(out);
			function run() {
				k.loading(out);
				ui.api('inventur/bestandswert?' + k.qs({ datum: date.value })).then(function (r) {
					out.textContent = '';
					out.appendChild(h('div', { class: 'grid tiles' },
						[k.tile('Bestandswert ' + k.dmy(r.date), k.eur(r.total), r.count + ' Positionen')].concat(r.by_place.map(function (p) { return k.tile(p[0], k.eur(p[1])); }))));
					out.appendChild(h('p', { class: 'muted' }, 'Produkte: Menge aus dem Bewegungskonto bis Ende des Stichtags, bewertet mit den Herstellkosten der Kalkulation (heutiger Stand); Kommissionsware gehört dir weiter und zählt mit. ' + (r.with_parts ? 'Kleinteile und Kartons mit dem Stückpreis aus dem Einkauf, Filament mit Restmenge × Preis je kg der Charge.' : 'Kleinteile, Kartons und Filament führen keinen Verlauf – sie stehen nur beim Stichtag „heute“ dabei. Für den Jahresabschluss also am Stichtag selbst abrufen oder die Inventur nutzen.')));
					out.appendChild(k.table(['SKU', 'Artikel', 'Farbe', 'Ort', 'Menge', 'HK/Stück', 'Wert'], r.rows.map(function (x) {
						return [x.sku, x.name, x.color, x.place, q3(x.qty), k.eur(x.unit), k.eur(x.value)];
					}), [4, 5, 6]));
					if (r.count > r.rows.length) { out.appendChild(h('p', { class: 'muted' }, 'Gezeigt: die ' + r.rows.length + ' wertvollsten. Alle stehen in der CSV.')); }
				}).catch(function (e) { k.fail(out, e); });
			}
			date.addEventListener('change', run);
			run();
		}

		route();
	};
})();
