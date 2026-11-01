/*
 * ERP-Seite „Offene Posten“: offene Rechnungen, Erinnerung und Mahnstufen.
 * Schnittstelle: includes/Erp/Pages/Mahnwesen.php. Kein innerHTML mit Daten.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	UI.pages['mahnwesen'] = function (item, ui) {
		var h = ui.h;
		var k = UI.kit(ui);
		var state = { f: 'ueberfaellig' };
		var body = h('div');
		var data = null;

		ui.pageHead('Offene Posten', 'Alle offenen Rechnungen mit Fälligkeit. Erinnern und mahnen in Stufen, alles mit Protokoll.');
		ui.view().appendChild(body);

		function sendDialog(r, level) {
			ui.api('mahnwesen/' + r.id + '/entwurf?level=' + level).then(function (d) {
				var to = k.input(d.to, { type: 'email', required: '' });
				var subject = k.input(d.subject, { required: '' });
				var text = k.area(d.body, { rows: 14 });
				var fee = k.input(String(d.fee).replace('.', ','), { inputmode: 'decimal' });
				var fields = [k.field('An', to, d.to ? null : 'In der Kundenakte ist keine Adresse hinterlegt.'), k.field('Betreff', subject), k.field('Text', text, 'Das Schreiben hängt zusätzlich als PDF an. Neue Frist: ' + k.dmy(d.new_due) + '.')];
				if (level > 1) { fields.splice(1, 0, k.field('Mahngebühr', fee, 'Wird im Schreiben genannt, nicht gebucht.')); }
				k.formDialog(d.subject, fields, 'Senden', function () {
					return ui.api('mahnwesen/' + r.id + '/senden', { method: 'POST', body: { level: level, to: to.value, subject: subject.value, body: text.value, fee: fee.value } });
				}, load, true);
			}).catch(function (e) { ui.toast(e.message, true); });
		}

		function paidDialog(r) {
			var date = k.input(new Date().toISOString().slice(0, 10), { type: 'date' });
			k.formDialog('Bezahlt: ' + r.number, [h('p', null, r.company + ' · ' + k.eur(r.value)), k.field('Zahlungseingang am', date, 'Tipp: Im Bankabgleich geht das automatisch mit dem Kontoauszug.')], 'Als bezahlt markieren', function () {
				return ui.api('mahnwesen/' + r.id + '/bezahlt', { method: 'POST', body: { date: date.value } });
			}, load);
		}

		function invoiceDialog(r) {
			var date = k.input(new Date().toISOString().slice(0, 10), { type: 'date' });
			var terms = k.input(String(r.terms || 14), { inputmode: 'numeric' });
			k.formDialog('Rechnungsdatum: ' + r.number, [k.field('Rechnung gestellt am', date), k.field('Zahlungsziel (Tage)', terms)], 'Speichern', function () {
				return ui.api('mahnwesen/' + r.id + '/rechnungsdatum', { method: 'POST', body: { date: date.value, terms: terms.value } });
			}, load);
		}

		function rowCard(r) {
			var tags = h('div', { class: 'row-tags' });
			if (!r.due) { tags.appendChild(k.tag('ohne Rechnungsdatum')); }
			else if (r.overdue > 0) { tags.appendChild(k.tag(r.overdue + ' Tage überfällig', r.overdue > 30 ? 'later' : '')); }
			else { tags.appendChild(k.tag('fällig am ' + k.dmy(r.due), 'now')); }
			if (r.last_level) { tags.appendChild(k.tag(r.last_label + ' am ' + k.dmy(r.last_sent))); }

			var btns = h('div', { class: 'kt-btns' });
			if (!r.due) {
				btns.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { invoiceDialog(r); } }, 'Rechnungsdatum'));
			} else if (r.next_level) {
				btns.appendChild(h('button', { class: 'btn' + (r.next_due ? ' primary' : ''), type: 'button', title: r.next_due ? '' : 'Regulär erst in ' + r.next_wait + ' Tagen', onclick: function () { sendDialog(r, r.next_level); } }, r.next_label + (r.next_due ? '' : ' (in ' + r.next_wait + ' T.)')));
				btns.appendChild(k.fileBtn('PDF', 'mahnwesen/' + r.id + '/pdf?level=' + r.next_level));
			}
			btns.appendChild(h('button', { class: 'btn', type: 'button', onclick: function () { paidDialog(r); } }, 'Bezahlt'));

			return h('article', { class: 'row-card kt-row' },
				h('div', { class: 'row-main' },
					h('b', null, r.company + ' · ' + r.number),
					h('small', null, (r.invoice_date ? 'Rechnung ' + k.dmy(r.invoice_date) + ' · Ziel ' + r.terms + ' Tage' : 'Auftrag vom ' + k.dmy(r.order_date))),
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
				gebuehr_stufe3: k.input(String(s.gebuehr_stufe3).replace('.', ','), { inputmode: 'decimal' })
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
				h('div', { class: 'kt-actions kt-gap' }, btn));
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				btn.disabled = true;
				var b = {};
				Object.keys(f).forEach(function (key) { b[key] = f[key].value; });
				ui.api('mahnwesen/einstellungen', { method: 'POST', body: b }).then(function (r) { ui.toast(r.message); load(); })
					.catch(function (e2) { ui.toast(e2.message, true); btn.disabled = false; });
			});
			return h('details', { class: 'card kt-gap' }, h('summary', null, 'Fristen und Gebühren'), form);
		}

		function paint() {
			var d = data;
			body.textContent = '';
			body.appendChild(h('div', { class: 'grid tiles' },
				k.tile('Offen gesamt', k.eur(d.sums.offen), d.rows.length + ' Posten'),
				k.tile('Überfällig', k.eur(d.sums.ueberfaellig)),
				k.tile('1–30 Tage', k.eur(d.sums['1_30'])),
				k.tile('31–60 Tage', k.eur(d.sums['31_60'])),
				k.tile('über 60 Tage', k.eur(d.sums.ueber_60))));
			if (d.shop_waiting) {
				body.appendChild(h('div', { class: 'notice kt-gap' }, d.shop_waiting + ' Shop-Bestellung(en) warten seit über einer Woche auf die Überweisung („In Wartestellung“). Mit dem Kontoauszug im Bankabgleich lassen sie sich direkt als bezahlt verbuchen.'));
			}
			var filters = [['ueberfaellig', 'Überfällig'], ['dran', 'Mahnung dran'], ['nicht_faellig', 'Noch nicht fällig'], ['ohne_rechnung', 'Ohne Rechnungsdatum'], ['alle', 'Alle']];
			body.appendChild(h('div', { class: 'kt-bar' }, k.pills(filters, state.f, function (f) { state.f = f; paint(); })));
			var rows = d.rows.filter(function (r) {
				if (state.f === 'alle') { return true; }
				if (state.f === 'ueberfaellig') { return r.due && r.overdue > 0; }
				if (state.f === 'dran') { return r.next_level && r.next_due; }
				return r.bucket === state.f;
			});
			var list = h('div', { class: 'rows' });
			if (!rows.length) { list.appendChild(h('div', { class: 'card muted' }, d.rows.length ? 'Hier ist nichts.' : 'Keine offenen Rechnungen.')); }
			rows.forEach(function (r) { list.appendChild(rowCard(r)); });
			body.appendChild(list);
			body.appendChild(settingsBox(d.settings));
			if (d.history.length) {
				body.appendChild(h('details', { class: 'card kt-gap' }, h('summary', null, 'Zuletzt verschickt'),
					k.table(['Datum', 'Auftrag', 'Stufe', 'Betrag', 'Gebühr', 'An'], d.history.map(function (n) {
						return [k.dmy(n.sent_at), 'A-' + n.order_op_id, n.level, k.eur(n.amount), n.fee ? k.eur(n.fee) : '—', n.sent_to];
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
