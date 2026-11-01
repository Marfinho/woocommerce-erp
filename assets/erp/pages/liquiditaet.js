/*
 * ERP-Seite „Liquidität“: Kontostand der nächsten 12 Wochen.
 * Schnittstelle: includes/Erp/Pages/Liquiditaet.php. Kein innerHTML mit Daten.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	UI.pages['liquiditaet'] = function (item, ui) {
		var h = ui.h;
		var k = UI.kit(ui);
		var body = h('div');

		ui.pageHead('Liquidität', 'Was in den nächsten 12 Wochen auf dem Konto passiert – eine Schätzung aus offenen Posten, Daueraufträgen, Löhnen und Einkäufen.');
		ui.view().appendChild(body);

		var kinds = { rechnung: 'Rechnung', ueberfaellig: 'überfällig', dauer: 'Dauerauftrag', lohn: 'Löhne', einkauf: 'Einkauf', shop: 'Shop', steuer: 'Umsatzsteuer' };

		function chart(rows, buffer) {
			var max = Math.max.apply(null, rows.map(function (r) { return Math.abs(r.balance); }).concat([Math.abs(buffer), 1]));
			return h('div', { class: 'card' },
				h('div', { class: 'kt-bars', role: 'img', 'aria-label': 'Kontostand je Woche' }, rows.map(function (r) {
					return h('div', { class: r.balance < buffer ? 'neg' : '', style: 'height:' + Math.max(3, Math.abs(r.balance) / max * 100).toFixed(1) + '%', title: r.label + ': ' + k.eur(r.balance) });
				})),
				h('div', { style: 'display:flex;justify-content:space-between;font-size:11px', class: 'muted' }, h('span', null, rows[0].label), h('span', null, rows[rows.length - 1].label)));
		}

		function settingsBox(s, dg) {
			var f = {
				saldo: k.input(String(s.saldo).replace('.', ','), { inputmode: 'decimal' }),
				saldo_datum: k.input(s.saldo_datum, { type: 'date' }),
				puffer: k.input(String(s.puffer).replace('.', ','), { inputmode: 'decimal' }),
				shop_wochen: k.input(s.shop_wochen, { inputmode: 'numeric' })
			};
			var shop = h('input', { type: 'checkbox', checked: !!s.shop_einrechnen });
			var dOn = h('input', { type: 'checkbox', checked: !!dg.aktiv });
			var dTo = k.input(dg.an, { type: 'email', placeholder: 'leer = Firmenadresse aus den Einstellungen' });
			var btn = h('button', { class: 'btn', type: 'submit' }, 'Speichern');
			var form = h('form', null,
				h('div', { class: 'kt-grid kt-gap' },
					k.field('Kontostand von Hand', f.saldo, 'Nur nötig ohne CAMT-Auszug im Bankabgleich.'),
					k.field('Stand vom', f.saldo_datum),
					k.field('Mindestpuffer', f.puffer, 'Darunter wird die Woche rot.'),
					k.field('Shop-Schnitt aus … Wochen', f.shop_wochen)),
				h('label', { class: 'lg-chk' }, shop, ' Üblichen Shop-Umsatz einrechnen'),
				h('label', { class: 'lg-chk' }, dOn, ' Täglicher Überblick per Mail (fällige Mahnungen, Angebote, Urlaubsanträge, Bank, Engpässe)'),
				k.field('Überblick an', dTo),
				h('div', { class: 'kt-actions' }, btn));
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				btn.disabled = true;
				ui.api('liquiditaet/einstellungen', { method: 'POST', body: { saldo: f.saldo.value, saldo_datum: f.saldo_datum.value, puffer: f.puffer.value, shop_wochen: f.shop_wochen.value, shop_einrechnen: shop.checked ? 1 : 0, digest_aktiv: dOn.checked ? 1 : 0, digest_an: dTo.value } })
					.then(function (r) { ui.toast(r.message); load(); }).catch(function (e2) { ui.toast(e2.message, true); btn.disabled = false; });
			});
			return h('details', { class: 'card kt-gap' }, h('summary', null, 'Startwert und Annahmen'), form);
		}

		function paint(d) {
			body.textContent = '';
			var low = d.first_below > -1 ? d.rows[d.first_below] : null;
			body.appendChild(h('div', { class: 'grid tiles' },
				k.tile('Kontostand heute', k.eur(d.start.amount), d.start.date ? (d.start.source === 'vivid' ? 'live von Vivid, ' : (d.start.source === 'bank' ? 'laut Auszug vom ' : 'von Hand, Stand ')) + k.dmy(d.start.date) : 'noch nicht eingetragen'),
				k.tile('In 12 Wochen', k.eur(d.rows[d.rows.length - 1].balance)),
				k.tile('Tiefster Stand', k.eur(d.min)),
				k.tile('Shop je Woche', d.shop_week ? k.eur(d.shop_week) : '—', d.payroll_month ? 'Löhne je Monat ' + k.eur(d.payroll_month) : null)));
			if (!d.start.date) {
				body.appendChild(h('div', { class: 'notice warn kt-gap' }, 'Es gibt noch keinen Kontostand. Verbinde im Bankabgleich Vivid, lies einen CAMT-Auszug ein oder trag ihn unten von Hand ein.'));
			}
			if (low) {
				body.appendChild(h('div', { class: 'notice warn kt-gap' }, 'In ' + low.label + ' (' + k.dmy(low.from) + ') fällt der Kontostand unter den Puffer von ' + k.eur(d.buffer) + ': ' + k.eur(low.balance) + '.'));
			}
			body.appendChild(chart(d.rows, d.buffer));

			var list = h('div', { class: 'rows kt-gap' });
			d.rows.forEach(function (r) {
				var det = h('details', null, h('summary', { class: 'muted' }, r.items.length + ' Posten'),
					r.items.length ? k.table(['Datum', 'Was', 'Art', 'Betrag'], r.items.map(function (e) {
						return [k.dmy(e.date), e.label, kinds[e.kind] || e.kind, h('span', { class: e.amount < 0 ? 'kt-neg' : 'kt-pos' }, k.eur(e.amount))];
					}), [3]) : null);
				list.appendChild(h('article', { class: 'row-card kt-row' + (r.balance < d.buffer ? ' warn' : '') },
					h('div', { class: 'row-main' }, h('b', null, r.label + ' · ' + k.dmy(r.from) + ' – ' + k.dmy(r.to)),
						h('small', null, 'Eingang ' + k.eur(r.in) + ' · Ausgang ' + k.eur(r.out)), det),
					h('div', { class: 'kt-num' }, h('span', { class: 'row-num ' + (r.balance < d.buffer ? 'kt-neg' : '') }, k.eur(r.balance))),
					h('div', { class: 'kt-btns' })));
			});
			body.appendChild(list);
			body.appendChild(settingsBox(d.settings, d.digest));
		}

		function load() {
			ui.api('liquiditaet').then(paint).catch(function (e) { k.fail(body, e); });
		}

		k.loading(body);
		load();
	};
})();
