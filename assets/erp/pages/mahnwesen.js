/*
 * ERP-Seite „Offene Posten“: offene Rechnungen und wartende Shop-Bestellungen,
 * Erinnerung und Mahnstufen, Gebühren und Verzugszinsen, Sammelversand.
 * Schnittstelle: includes/Erp/Pages/Mahnwesen.php. Kein innerHTML mit Daten.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	UI.pages['mahnwesen'] = function (item, ui) {
		var h = ui.h;
		var k = UI.kit(ui);
		var state = { f: 'ueberfaellig', art: '' };
		var body = h('div');
		var data = null;

		var allBtn = h('button', { class: 'btn primary', type: 'button', hidden: true, onclick: sendAll }, 'Alle fälligen verschicken');
		ui.pageHead('Offene Posten', 'Offene Rechnungen und Shop-Bestellungen, die auf die Überweisung warten. Erinnern und mahnen in Stufen, mit Protokoll.', allBtn);
		ui.view().appendChild(body);

		function path(r) { return 'mahnwesen/' + r.kind + '/' + r.id; }

		function sendAll() {
			k.confirmDo('Alle fälligen Schreiben verschicken', data.due_now + ' Schreiben gehen mit dem Vorgabetext und PDF per Mail raus – jeweils die nächste fällige Stufe. Posten ohne Mailadresse werden übersprungen.', 'Verschicken', function () {
				return ui.api('mahnwesen/sammeln', { method: 'POST', body: {} });
			}, load);
		}

		function sendDialog(r, level) {
			ui.api(path(r) + '/entwurf?level=' + level).then(function (d) {
				var to = k.input(d.to, { type: 'email', required: '' });
				var subject = k.input(d.subject, { required: '' });
				var text = k.area(d.body, { rows: 14 });
				var fee = k.input(String(d.fee).replace('.', ','), { inputmode: 'decimal' });
				var fields = [k.field('An', to, d.to ? null : 'Keine Adresse hinterlegt – bitte in der Kundenakte bzw. Bestellung nachtragen.'), k.field('Betreff', subject), k.field('Text', text, 'Das Schreiben hängt zusätzlich als PDF an. Neue Frist: ' + k.dmy(d.new_due) + '. Offen insgesamt: ' + k.eur(d.total) + '.')];
				if (level > 1) { fields.splice(1, 0, k.field('Mahngebühr', fee, 'Wird genannt und gebucht, sobald sie bezahlt ist.')); }
				k.formDialog(d.subject, fields, 'Senden', function () {
					return ui.api(path(r) + '/senden', { method: 'POST', body: { level: level, to: to.value, subject: subject.value, body: text.value, fee: fee.value } });
				}, load, true);
			}).catch(function (e) { ui.toast(e.message, true); });
		}

		function paidDialog(r) {
			var date = k.input(new Date().toISOString().slice(0, 10), { type: 'date' });
			var fee = h('input', { type: 'checkbox', checked: r.extras_open > 0 });
			var fields = [h('p', null, r.company + ' · ' + r.number + ' · ' + k.eur(r.value)), k.field('Zahlungseingang am', date, 'Tipp: Im Bankabgleich geht das automatisch.')];
			if (r.extras_open > 0) { fields.push(h('label', { class: 'lg-chk' }, fee, ' Mahngebühr/Zinsen (' + k.eur(r.extras_open) + ') wurden mitbezahlt – als Einnahme buchen')); }
			if (r.kind === 'shop') { fields.push(h('p', { class: 'muted' }, 'Die Shop-Bestellung bekommt den Zahlungseingang und läuft wie gewohnt weiter (Mail an den Kunden, Bearbeitung).')); }
			k.formDialog('Bezahlt', fields, 'Als bezahlt markieren', function () {
				return ui.api(path(r) + '/bezahlt', { method: 'POST', body: { date: date.value, fee_paid: fee.checked ? 1 : 0 } });
			}, load);
		}

		function invoiceDialog(r) {
			var date = k.input(new Date().toISOString().slice(0, 10), { type: 'date' });
			var terms = k.input(String(r.terms || 14), { inputmode: 'numeric' });
			k.formDialog('Rechnungsdatum: ' + r.number, [k.field('Rechnung gestellt am', date), k.field('Zahlungsziel (Tage)', terms)], 'Speichern', function () {
				return ui.api('mahnwesen/auftrag/' + r.id + '/rechnungsdatum', { method: 'POST', body: { date: date.value, terms: terms.value } });
			}, load);
		}

		function rowCard(r) {
			var tags = h('div', { class: 'row-tags' }, k.tag(r.kind === 'shop' ? 'Shop' + (r.payment ? ' · ' + r.payment : '') : 'Auftrag'));
			if (!r.due) { tags.appendChild(k.tag('ohne Rechnungsdatum')); }
			else if (r.overdue > 0) { tags.appendChild(k.tag(r.overdue + ' Tage überfällig', r.overdue > 30 ? 'later' : '')); }
			else { tags.appendChild(k.tag('fällig am ' + k.dmy(r.due), 'now')); }
			if (r.last_level) { tags.appendChild(k.tag(r.last_label + ' am ' + k.dmy(r.last_sent))); }
			if (r.extras_open > 0) { tags.appendChild(k.tag('+ ' + k.eur(r.extras_open) + ' Gebühren/Zinsen')); }

			var btns = h('div', { class: 'kt-btns' });
			if (!r.due) {
				btns.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { invoiceDialog(r); } }, 'Rechnungsdatum'));
			} else if (r.next_level) {
				btns.appendChild(h('button', { class: 'btn' + (r.next_due ? ' primary' : ''), type: 'button', title: r.next_due ? '' : 'Regulär erst in ' + r.next_wait + ' Tagen', onclick: function () { sendDialog(r, r.next_level); } }, r.next_label + (r.next_due ? '' : ' (in ' + r.next_wait + ' T.)')));
				btns.appendChild(k.fileBtn('PDF', path(r) + '/pdf?level=' + r.next_level));
			}
			btns.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { paidDialog(r); } }, 'Bezahlt'));

			return h('article', { class: 'row-card kt-row' },
				h('div', { class: 'row-main' },
					h('b', null, r.company + ' · ' + r.number),
					h('small', null, (r.kind === 'shop' ? 'bestellt ' + k.dmy(r.order_date) + ' · Frist ' + r.terms + ' Tage' : (r.invoice_date ? 'Rechnung ' + k.dmy(r.invoice_date) + ' · Ziel ' + r.terms + ' Tage' : 'Auftrag vom ' + k.dmy(r.order_date))) + (r.email ? ' · ' + r.email : ' · keine Mailadresse')),
					tags),
				h('div', { class: 'kt-num' }, h('span', { class: 'row-num' }, k.eur(r.value))),
				btns);
		}

		function settingsBox(s) {
			var f = {
				tage_erinnerung: k.input(s.tage_erinnerung, { inputmode: 'numeric' }),
				tage_stufe2: k.input(s.tage_stufe2, { inputmode: 'numeric' }),
				tage_stufe3: k.input(s.tage_stufe3, { inputmode: 'numeric' }),
				frist_tage: k.input(s.frist_tage, { inputmode: 'numeric' }),
				gebuehr_stufe2: k.input(String(s.gebuehr_stufe2).replace('.', ','), { inputmode: 'decimal' }),
				gebuehr_stufe3: k.input(String(s.gebuehr_stufe3).replace('.', ','), { inputmode: 'decimal' }),
				shop_tage: k.input(s.shop_tage, { inputmode: 'numeric' }),
				basiszins: k.input(String(s.basiszins).replace('.', ','), { inputmode: 'decimal' }),
				konto: k.input(s.konto, { inputmode: 'numeric', placeholder: 'leer = Erlöskonto' })
			};
			var flags = {
				shop_mahnen: h('input', { type: 'checkbox', checked: !!s.shop_mahnen }),
				zinsen: h('input', { type: 'checkbox', checked: !!s.zinsen }),
				pauschale: h('input', { type: 'checkbox', checked: !!s.pauschale })
			};
			var btn = h('button', { class: 'btn', type: 'submit' }, 'Speichern');
			var form = h('form', null,
				h('div', { class: 'kt-grid kt-gap' },
					k.field('Erinnerung ab Tag … nach Fälligkeit', f.tage_erinnerung, '0 = am Tag nach Fälligkeit'),
					k.field('1. Mahnung … Tage nach Erinnerung', f.tage_stufe2),
					k.field('Letzte Mahnung … Tage danach', f.tage_stufe3),
					k.field('Neue Frist (Tage)', f.frist_tage),
					k.field('Gebühr 1. Mahnung', f.gebuehr_stufe2),
					k.field('Gebühr letzte Mahnung', f.gebuehr_stufe3)),
				h('label', { class: 'lg-chk' }, flags.shop_mahnen, ' Shop-Bestellungen mit Überweisung/Vorkasse einbeziehen'),
				k.field('Zahlungsfrist Shop-Bestellungen (Tage)', f.shop_tage),
				h('label', { class: 'lg-chk' }, flags.zinsen, ' Verzugszinsen in der letzten Mahnung (§ 288 BGB: Basiszins + 9 Punkte unter Unternehmern, + 5 bei Verbrauchern)'),
				k.field('Aktueller Basiszinssatz in %', f.basiszins, 'Steht bei der Deutschen Bundesbank, ändert sich zum 1.1. und 1.7.'),
				h('label', { class: 'lg-chk' }, flags.pauschale, ' 40-€-Verzugspauschale bei Unternehmern (§ 288 Abs. 5 BGB)'),
				k.field('Konto für Mahngebühren/Zinsen (DATEV)', f.konto, 'Mit dem Steuerberater abstimmen.'),
				h('div', { class: 'kt-actions kt-gap' }, btn));
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				btn.disabled = true;
				var b = {};
				Object.keys(f).forEach(function (key) { b[key] = f[key].value; });
				Object.keys(flags).forEach(function (key) { b[key] = flags[key].checked ? 1 : 0; });
				ui.api('mahnwesen/einstellungen', { method: 'POST', body: b }).then(function (r) { ui.toast(r.message); load(); })
					.catch(function (e2) { ui.toast(e2.message, true); btn.disabled = false; });
			});
			return h('details', { class: 'card kt-gap' }, h('summary', null, 'Fristen, Gebühren und Zinsen'), form);
		}

		function paint() {
			var d = data;
			body.textContent = '';
			allBtn.hidden = !d.due_now;
			allBtn.textContent = 'Alle fälligen verschicken (' + d.due_now + ')';
			body.appendChild(h('div', { class: 'grid tiles' },
				k.tile('Offen gesamt', k.eur(d.sums.offen), d.rows.length + ' Posten'),
				k.tile('Überfällig', k.eur(d.sums.ueberfaellig)),
				k.tile('1–30 Tage', k.eur(d.sums['1_30'])),
				k.tile('31–60 Tage', k.eur(d.sums['31_60'])),
				k.tile('über 60 Tage', k.eur(d.sums.ueber_60)),
				d.sums.nebenforderungen ? k.tile('Gebühren/Zinsen offen', k.eur(d.sums.nebenforderungen)) : null));
			var filters = [['ueberfaellig', 'Überfällig'], ['dran', 'Mahnung dran'], ['nicht_faellig', 'Noch nicht fällig'], ['ohne_rechnung', 'Ohne Rechnungsdatum'], ['alle', 'Alle']];
			var art = k.select([['', 'Aufträge und Shop'], ['auftrag', 'nur Aufträge'], ['shop', 'nur Shop']], state.art, { 'aria-label': 'Art' });
			art.addEventListener('change', function () { state.art = art.value; paint(); });
			body.appendChild(h('div', { class: 'kt-bar' }, k.pills(filters, state.f, function (f) { state.f = f; paint(); }), art));
			var rows = d.rows.filter(function (r) {
				if (state.art && r.kind !== state.art) { return false; }
				if (state.f === 'alle') { return true; }
				if (state.f === 'ueberfaellig') { return r.due && r.overdue > 0; }
				if (state.f === 'dran') { return r.next_level && r.next_due; }
				return r.bucket === state.f;
			});
			var list = h('div', { class: 'rows' });
			if (!rows.length) { list.appendChild(h('div', { class: 'card muted' }, d.rows.length ? 'Hier ist nichts.' : 'Keine offenen Posten.')); }
			rows.forEach(function (r) { list.appendChild(rowCard(r)); });
			body.appendChild(list);
			body.appendChild(settingsBox(d.settings));
			if (d.history.length) {
				body.appendChild(h('details', { class: 'card kt-gap' }, h('summary', null, 'Zuletzt verschickt'),
					k.table(['Datum', 'Posten', 'Stufe', 'Betrag', 'Gebühr/Zinsen', 'An'], d.history.map(function (n) {
						return [k.dmy(n.sent_at), n.number, n.level, k.eur(n.amount), n.fee ? k.eur(n.fee) + (n.booked ? ' (gebucht)' : '') : '—', n.sent_to];
					}), [3, 4])));
			}
		}

		function load() {
			ui.api('mahnwesen').then(function (d) { data = d; paint(); }).catch(function (e) { k.fail(body, e); });
		}

		k.loading(body);
		load();
	};
})();
