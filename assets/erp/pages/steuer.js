/*
 * ERP-Seite „Steuer & DATEV“: Umsatzsteuer bzw. Kleinunternehmergrenzen, DATEV-Export.
 * Schnittstelle: includes/Erp/Pages/Steuer.php. Kein innerHTML mit Daten.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	UI.pages['steuer'] = function (item, ui) {
		var h = ui.h;
		var k = UI.kit(ui);
		var state = { jahr: new Date().getFullYear(), modus: 'monat' };
		var body = h('div');

		ui.pageHead('Steuer & DATEV', 'Umsatzsteuer im Blick behalten und die Buchungen für den Steuerberater ausgeben.');
		ui.view().appendChild(body);

		function meter(label, value, limit) {
			var pct = Math.min(100, limit ? value / limit * 100 : 0);
			return h('div', { class: 'card' },
				h('div', { class: 'lab muted' }, label),
				h('div', { class: 'val', style: 'font-size:22px;font-weight:700' }, k.eur(value), h('small', { class: 'muted' }, ' von ' + k.eur(limit))),
				h('div', { style: 'height:10px;border-radius:5px;background:var(--surface-muted);margin-top:8px;overflow:hidden' },
					h('div', { style: 'height:100%;width:' + pct.toFixed(1) + '%;background:' + (pct >= 100 ? 'var(--bad,#b3261e)' : pct >= 80 ? '#d9a400' : 'var(--ok,#2f7d32)') })));
		}

		function ustBlock(d) {
			var box = h('div');
			var u = d.umsatz;
			if (d.kleinunternehmer) {
				box.appendChild(h('h3', { class: 'kt-h' }, 'Kleinunternehmerregelung (§ 19 UStG)'));
				box.appendChild(h('div', { class: 'grid tiles' },
					meter('Umsatz ' + (d.jahr - 1) + ' (Grenze Vorjahr)', u.vorjahr.summe, u.grenzen.vorjahr),
					meter('Umsatz ' + d.jahr + ' bis heute', u.laufend.summe, u.grenzen.vorjahr),
					meter('Umsatz ' + d.jahr + ' (harte Grenze im Jahr)', u.laufend.summe, u.grenzen.laufend)));
				box.appendChild(h('p', { class: 'muted' }, 'Hochgerechnet auf ' + d.jahr + ': ' + k.eur(u.status.prognose) + '. Darin Shop ' + k.eur(u.laufend.shop) + ' und manuelle Aufträge ' + k.eur(u.laufend.manuell) + '.'));
				u.status.text.forEach(function (t) {
					box.appendChild(h('div', { class: 'notice' + (u.status.level === 'ok' ? '' : ' warn') + ' kt-gap' }, t));
				});
			} else {
				box.appendChild(h('h3', { class: 'kt-h' }, 'Umsatzsteuer aus dem Shop'));
				box.appendChild(h('div', { class: 'kt-bar' },
					k.pills([['monat', 'Monatlich'], ['quartal', 'Quartalsweise']], state.modus, function (m) { state.modus = m; load(); })));
				var sum = { kz81: 0, ust19: 0, kz86: 0, ust7: 0, summe: 0 };
				var rows = d.ust.map(function (r) {
					Object.keys(sum).forEach(function (key) { sum[key] += r[key]; });
					return [r.label, k.eur(r.kz81), k.eur(r.ust19), k.eur(r.kz86), k.eur(r.ust7), k.eur(r.summe)];
				});
				rows.push([h('b', null, 'Summe'), k.eur(sum.kz81), k.eur(sum.ust19), k.eur(sum.kz86), k.eur(sum.ust7), h('b', null, k.eur(sum.summe))]);
				box.appendChild(k.table(['Zeitraum', 'Kz 81 (19 %, netto)', 'USt 19 %', 'Kz 86 (7 %, netto)', 'USt 7 %', 'USt gesamt'], rows, [1, 2, 3, 4, 5]));
				box.appendChild(h('div', { class: 'notice kt-gap' }, 'Nur Shop-Bestellungen (abgeschlossen, Erstattungen abgezogen). Die Vorsteuer (Kz 66) steht nicht in den Belegen – sie kommt aus den Eingangsrechnungen. Manuelle Aufträge mit Umsatzsteuer bitte ebenfalls ergänzen. Die Voranmeldung selbst geht über ELSTER oder den Steuerberater.'));
			}
			return box;
		}

		function datevBlock(d) {
			var s = d.datev;
			var box = h('div');
			var y = d.jahr;
			var from = k.input(y + '-01-01', { type: 'date', 'aria-label': 'von' });
			var to = k.input(y === new Date().getFullYear() ? new Date().toISOString().slice(0, 10) : y + '-12-31', { type: 'date', 'aria-label': 'bis' });
			var btn = k.fileBtn('DATEV-Datei herunterladen', function () { return 'steuer/datev?' + k.qs({ von: from.value, bis: to.value }); }, true);

			box.appendChild(h('h3', { class: 'kt-h' }, 'DATEV-Export für den Steuerberater'));
			box.appendChild(h('p', { class: 'muted' }, 'Alle Buchungen des Journals im Format „DATEV Buchungsstapel“ (EXTF 700, SKR03). Einnahmen: Bank an ' + d.erloes_konto + ', Ausgaben: Aufwandskonto an Bank, Stornos als Gegenbuchung. Bitte den ersten Export zusammen mit dem Steuerberater prüfen.'));
			box.appendChild(h('div', { class: 'kt-grid' }, k.field('Von', from), k.field('Bis', to), h('div', null, btn)));

			var f = {
				berater: k.input(s.berater, { inputmode: 'numeric', placeholder: 'z. B. 1234567' }),
				mandant: k.input(s.mandant, { inputmode: 'numeric', placeholder: 'z. B. 10001' }),
				sachkonten: k.select([[4, '4'], [5, '5'], [6, '6'], [7, '7'], [8, '8']], s.sachkonten),
				wj_beginn: k.input(s.wj_beginn, { placeholder: '01-01' }),
				bank_konto: k.input(s.bank_konto, { inputmode: 'numeric' }),
				kasse_konto: k.input(s.kasse_konto, { inputmode: 'numeric' }),
				diktat: k.input(s.diktat, { maxlength: 2 })
			};
			var save = h('button', { class: 'btn', type: 'submit' }, 'Speichern');
			var form = h('form', { class: 'card kt-gap' },
				h('b', null, 'Einstellungen'),
				h('div', { class: 'kt-grid kt-gap' },
					k.field('Beraternummer', f.berater), k.field('Mandantennummer', f.mandant), k.field('Sachkontenlänge', f.sachkonten),
					k.field('Beginn Wirtschaftsjahr (MM-TT)', f.wj_beginn), k.field('Bankkonto', f.bank_konto), k.field('Kassenkonto', f.kasse_konto), k.field('Kürzel', f.diktat)),
				h('div', { class: 'kt-actions kt-gap' }, save));
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				save.disabled = true;
				var b = {};
				Object.keys(f).forEach(function (key) { b[key] = f[key].value; });
				ui.api('steuer/einstellungen', { method: 'POST', body: b }).then(function (r) { ui.toast(r.message); })
					.catch(function (e2) { ui.toast(e2.message, true); }).then(function () { save.disabled = false; });
			});
			box.appendChild(form);
			return box;
		}

		function paint(d) {
			body.textContent = '';
			var years = [];
			for (var y = new Date().getFullYear(); y >= new Date().getFullYear() - 5; y--) { years.push([y, String(y)]); }
			var ysel = k.select(years, d.jahr, { 'aria-label': 'Jahr' });
			ysel.addEventListener('change', function () { state.jahr = parseInt(ysel.value, 10); load(); });
			body.appendChild(h('div', { class: 'kt-bar' }, h('div', null, d.kleinunternehmer ? k.tag('Kleinunternehmer', 'now') : k.tag('Regelbesteuerung')), ysel));
			body.appendChild(ustBlock(d));
			body.appendChild(datevBlock(d));
		}

		function load() {
			k.loading(body);
			ui.api('steuer?' + k.qs(state)).then(paint).catch(function (e) { k.fail(body, e); });
		}

		load();
	};
})();
