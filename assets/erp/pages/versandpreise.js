/*
 * Versandpreise: die vertraglichen DHL-Preise und Zuschläge, aus denen das ERP das
 * Porto je Label nachrechnet (die DHL nennt beim Label keinen Preis). Der Dialog
 * öffnet auf der Seite Versand. Server: includes/Modules/Betrieb/Dhl/DhlCost.php.
 */
(function () {
	'use strict';
	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};

	function eur(v) { return String(v).replace('.', ','); }

	UI.versandpreise = function (ui, onSaved) {
		var h = ui.h;
		var body = h('div', { class: 'run' }, h('div', { class: 'card' }, 'Wird geladen …'));
		var d = ui.dlg('Versandpreise', body, true);

		ui.api('versand/preise').then(function (data) {
			var p = data.prices;
			var save = h('button', { class: 'btn primary', type: 'button' }, 'Speichern');

			function num(value, onchange, w) {
				var i = h('input', { class: 'field', type: 'text', inputmode: 'decimal', value: eur(value), style: 'max-width:' + (w || 90) + 'px', 'aria-label': 'Preis' });
				i.oninput = function () { onchange(i.value); };
				return i;
			}

			function table(title, rows, note) {
				var grid = h('div', { style: 'display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:6px 12px' });
				rows.forEach(function (r) {
					grid.appendChild(h('label', { style: 'display:flex;gap:6px;align-items:center' },
						h('span', { style: 'min-width:60px' }, 'bis ' + eur(r[0]) + ' kg'),
						num(r[1], function (v) { r[1] = v; }, 80)));
				});
				return h('div', { class: 'card' }, h('h3', null, title), note ? h('p', { class: 'muted', style: 'margin:0 0 8px' }, note) : null, grid);
			}

			function field(label, value, set, hint, w) {
				return h('label', { style: 'display:grid;gap:2px' }, h('b', null, label), num(value, set, w), hint ? h('small', { class: 'muted' }, hint) : null);
			}

			function text(label, value, set, hint, ph) {
				var i = h('input', { class: 'field', type: 'text', value: value || '', placeholder: ph || '', style: 'max-width:160px', 'aria-label': label });
				i.oninput = function () { set(i.value); };
				return h('label', { style: 'display:grid;gap:2px' }, h('b', null, label), i, hint ? h('small', { class: 'muted' }, hint) : null);
			}

			var z = p.zuschlaege;
			var lands = [['Zone 1', data.zones[1]], ['Zone 2', data.zones[2]], ['Zone 3', data.zones[3]]];

			body.textContent = '';
			body.appendChild(h('p', { class: 'muted', style: 'margin:0 0 10px' }, 'Preise netto (ohne Mehrwertsteuer) aus Ihrem DHL-Vertrag. Das ERP rechnet daraus das Porto je Label nach und zeigt es auf der Versandkarte. Es ist eine Schätzung: Die endgültigen Beträge stehen auf der Monatsrechnung der DHL. Die Monatspauschale (z. B. 9,95 € für Tarif S) ist hier nicht enthalten.'));
			body.appendChild(table('DHL Paket (Inland, Abrechnungsnummer …0102)', p.paket));
			[1, 2, 3].forEach(function (zone) {
				body.appendChild(table('DHL Europaket, Zone ' + zone, p.euro[zone], 'Länder: ' + (data.zones[zone] || []).join(', ')));
			});
			body.appendChild(h('div', { class: 'card', style: 'display:grid;gap:10px' }, h('h3', null, 'Services und Zuschläge je Sendung'),
				h('div', { class: 'label-area' },
					field('Sperrgut Paket (€)', p.sperrgut.paket, function (v) { p.sperrgut.paket = v; }),
					field('Sperrgut Europaket (€)', p.sperrgut.euro, function (v) { p.sperrgut.euro = v; })),
				h('div', { class: 'label-area' },
					field('Maut- und CO₂-Zuschlag (€)', z.maut, function (v) { z.maut = v; }, '0 = nicht rechnen'),
					field('Peakzuschlag (€)', z.peak, function (v) { z.peak = v; }),
					text('Peak-Monate', z.peak_monate, function (v) { z.peak_monate = v; }, 'Monatszahlen, mit Komma', '11,12')),
				h('div', { class: 'label-area' },
					field('Peak-in-Peak (€)', z.pip, function (v) { z.pip = v; }, 'nur im Zeitraum rechts'),
					text('von', z.pip_von, function (v) { z.pip_von = v; }, 'JJJJ-MM-TT', '2026-12-01'),
					text('bis', z.pip_bis, function (v) { z.pip_bis = v; }, 'JJJJ-MM-TT', '2026-12-24')),
				field('Energiezuschlag (% vom Preis)', z.energie_prozent, function (v) { z.energie_prozent = v; }, 'Den aktuellen Satz nennt die DHL unter dhl.de/energiezuschlag. 0 = nicht rechnen.')));
			body.appendChild(h('details', { class: 'card' }, h('summary', null, 'Länder je Zone'),
				h('div', { class: 'kv' }, lands.map(function (l) { return [h('span', null, l[0]), h('b', null, (l[1] || []).join(', '))]; }).reduce(function (a, b) { return a.concat(b); }, [])),
				h('p', { class: 'muted' }, 'Länder, die hier fehlen, stehen nicht in Ihrer Europaket-Preisliste. Für sie zeigt die Karte kein Porto.')));
			body.appendChild(h('div', { class: 'ship-actions', style: 'margin-top:12px' }, save));

			save.onclick = function () {
				save.disabled = true;
				ui.api('versand/preise', { method: 'POST', body: { prices: p } })
					.then(function (r) { ui.toast(r.message); if (onSaved) { onSaved(); } d.close(); })
					.catch(function (e) { save.disabled = false; ui.toast(e.message, true); });
			};
		}).catch(function (e) { body.textContent = ''; body.appendChild(h('div', { class: 'notice warn' }, e.message)); });
	};
})();
