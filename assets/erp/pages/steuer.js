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
				box.appendChild(h('h3', { class: 'kt-h' }, 'Umsatzsteuer-Voranmeldung'));
				box.appendChild(h('div', { class: 'kt-bar' },
					k.pills([['monat', 'Monatlich'], ['quartal', 'Quartalsweise']], state.modus, function (m) { state.modus = m; load(); }),
					k.fileBtn('Zahlen als CSV', function () { return 'steuer/ustva?' + k.qs(state); })));
				var sum = { kz81: 0, ust19: 0, kz86: 0, ust7: 0, summe: 0, kz66: 0, zahllast: 0 };
				var rows = d.ust.map(function (r) {
					Object.keys(sum).forEach(function (key) { sum[key] += r[key]; });
					return [r.label, k.eur(r.kz81), k.eur(r.ust19), k.eur(r.kz86), k.eur(r.ust7), k.eur(r.kz66) + (r.belege_offen ? ' (' + r.belege_offen + ' Belege fehlen)' : ''),
						h('b', { class: r.zahllast < 0 ? 'kt-pos' : '' }, k.eur(r.zahllast)),
						h('button', { class: 'btn', type: 'button', onclick: function () { vatDialog(r); } }, 'Vorsteuer')];
				});
				rows.push([h('b', null, 'Summe'), k.eur(sum.kz81), k.eur(sum.ust19), k.eur(sum.kz86), k.eur(sum.ust7), k.eur(sum.kz66), h('b', null, k.eur(sum.zahllast)), '']);
				box.appendChild(k.table(['Zeitraum', 'Kz 81 (19 %, netto)', 'USt 19 %', 'Kz 86 (7 %, netto)', 'USt 7 %', 'Kz 66 Vorsteuer', 'Zahllast', ''], rows, [1, 2, 3, 4, 5, 6]));
				box.appendChild(h('div', { class: 'notice kt-gap' }, 'Umsätze: abgeschlossene Shop-Bestellungen nach den Steuersätzen aus WooCommerce (Erstattungen abgezogen) plus manuelle Aufträge, die mit 19 % aus dem Bruttowert gerechnet werden. Vorsteuer: aus den Angaben je Beleg – fehlt sie, zählt der Beleg nicht. Negative Zahllast = Erstattung. Die Voranmeldung selbst geht über ELSTER oder den Steuerberater.'));
			}
			return box;
		}

		function vatDialog(r) {
			var box = h('div', { class: 'sheet-body' }, h('p', { class: 'muted' }, 'Wird geladen …'));
			var dlg = ui.dlg('Vorsteuer ' + r.label, box, true);
			var only = h('input', { type: 'checkbox' });
			function load2() {
				ui.api('steuer/vorsteuer?' + k.qs({ von: r.from, bis: r.to, nur_offen: only.checked ? 1 : '' })).then(function (d) {
					box.textContent = '';
					only.onchange = load2;
					box.appendChild(h('div', { class: 'kt-bar' }, h('label', { class: 'lg-chk' }, only, ' nur Belege ohne Angabe'), h('small', { class: 'muted' }, 'Satz wählen genügt – die Vorsteuer wird aus dem Bruttobetrag gerechnet. Abweichend (Mischsätze) einfach den Betrag eintragen.')));
					if (!d.rows.length) { box.appendChild(h('div', { class: 'card muted' }, 'Keine Belege in diesem Zeitraum.')); }
					d.rows.forEach(function (b) {
						var rate = k.select([[19, '19 %'], [7, '7 %'], [0, '0 % / ohne']], b.satz === null ? 19 : b.satz, { 'aria-label': 'Satz' });
						var tax = k.input(b.vorsteuer === null ? '' : String(b.vorsteuer).replace('.', ','), { inputmode: 'decimal', placeholder: 'berechnet', 'aria-label': 'Vorsteuer', style: 'width:100px' });
						var mark = h('small', { class: 'muted' }, b.vorsteuer === null ? 'fehlt' : 'erfasst');
						function save(remove) {
							ui.api('steuer/vorsteuer', { method: 'POST', body: { beleg_id: b.id, satz: rate.value, vorsteuer: tax.value, remove: remove ? 1 : 0 } }).then(function (res) {
								if (res.row) { tax.value = String(res.row.vorsteuer).replace('.', ','); mark.textContent = 'erfasst'; } else { tax.value = ''; mark.textContent = 'fehlt'; }
							}).catch(function (e) { ui.toast(e.message, true); });
						}
						rate.addEventListener('change', function () { tax.value = ''; save(false); });
						tax.addEventListener('change', function () { save(false); });
						box.appendChild(h('article', { class: 'row-card kt-row' },
							h('div', { class: 'row-main' }, h('b', null, (b.number || '#' + b.id) + ' · ' + b.vendor), h('small', null, k.dmy(b.date) + ' · ' + b.konto)),
							h('div', { class: 'kt-num' }, h('span', { class: 'row-num' }, k.eur(b.gross)), h('small', { class: 'muted' }, 'brutto')),
							h('div', { class: 'kt-btns' }, rate, tax, h('button', { class: 'btn', type: 'button', onclick: function () { save(false); } }, 'OK'), mark)));
					});
				}).catch(function (e) { k.fail(box, e); });
			}
			load2();
			dlg.addEventListener('close', load);
		}

		function datevBlock(d) {
			var s = d.datev;
			var box = h('div');
			var y = d.jahr;
			var from = k.input(y + '-01-01', { type: 'date', 'aria-label': 'von' });
			var to = k.input(y === new Date().getFullYear() ? new Date().toISOString().slice(0, 10) : y + '-12-31', { type: 'date', 'aria-label': 'bis' });
			var btn = k.fileBtn('DATEV-Datei herunterladen', function () { return 'steuer/datev?' + k.qs({ von: from.value, bis: to.value }); }, true);

			box.appendChild(h('h3', { class: 'kt-h' }, 'DATEV-Export für den Steuerberater'));
			box.appendChild(h('p', { class: 'muted' }, 'Alle Buchungen des Journals im Format „DATEV Buchungsstapel“ (EXTF 700, SKR03). Einnahmen: Bank an ' + d.erloes_konto + ', Ausgaben: Aufwandskonto an Bank' + (d.kleinunternehmer ? '' : ' mit BU-Schlüssel 9 bzw. 8, wenn die Vorsteuer erfasst ist') + ', Stornos als Gegenbuchung. Bitte den ersten Export zusammen mit dem Steuerberater prüfen.'));
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
