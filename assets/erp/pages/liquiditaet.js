/*
 * ERP-Seite „Liquidität“: Kontostand der nächsten 12 Wochen.
 * Schnittstelle: includes/Erp/Pages/Liquiditaet.php. Kein innerHTML mit Daten.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	// Was wäre wenn: die Posten der Vorschau mit Annahmen neu rechnen (reine Rechnung, auch für Tests).
	// o = { shop: ±%, preis: ±%, verzug: Tage, einmal: Betrag, einmal_woche: Index, monatlich: Betrag, ohne: [Kundennamen], personal_h: Std./Woche, personal_satz: €/Std. }
	UI.liquiScenario = function (rows, start, o) {
		o = o || {};
		var weeks = rows.map(function (r) { return { from: r.from, to: r.to, label: r.label }; });
		var events = [];
		rows.forEach(function (r) { (r.items || []).forEach(function (e) { events.push(e); }); });
		function addDays(ymd, n) { var d = new Date(ymd + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); }
		var ohne = (o.ohne || []).reduce(function (m, n) { m[n] = true; return m; }, {});
		var list = [];
		events.forEach(function (e) {
			var x = { date: e.date, amount: Number(e.amount) || 0, label: e.label, kind: e.kind };
			if ((x.kind === 'rechnung' || x.kind === 'ueberfaellig') && e.who && ohne[e.who]) { return; }
			if (x.kind === 'shop') { x.amount *= 1 + (Number(o.shop) || 0) / 100; }
			if (x.amount > 0 && (x.kind === 'shop' || x.kind === 'rechnung' || x.kind === 'ueberfaellig')) { x.amount *= 1 + (Number(o.preis) || 0) / 100; }
			if ((x.kind === 'rechnung' || x.kind === 'ueberfaellig') && Number(o.verzug)) { x.date = addDays(x.kind === 'ueberfaellig' ? weeks[0].from : x.date, Number(o.verzug)); }
			list.push(x);
		});
		if (Number(o.einmal)) {
			var w = weeks[Math.max(0, Math.min(weeks.length - 1, Number(o.einmal_woche) || 0))];
			list.push({ date: w.from, amount: -Math.abs(Number(o.einmal)), label: 'Einmalige Ausgabe', kind: 'szenario' });
		}
		var weekly = (Number(o.monatlich) || 0) * 12 / 52 + (Number(o.personal_h) || 0) * (Number(o.personal_satz) || 0);
		if (weekly) { weeks.forEach(function (w) { list.push({ date: w.to, amount: -weekly, label: 'Laufende Mehrkosten', kind: 'szenario' }); }); }
		var bal = Number(start) || 0;
		return weeks.map(function (w, i) {
			var inn = 0, out = 0;
			list.forEach(function (e) {
				if ((e.date >= w.from && e.date <= w.to) || (i === 0 && e.date < w.from)) { if (e.amount >= 0) { inn += e.amount; } else { out += e.amount; } }
			});
			bal += inn + out;
			return { from: w.from, to: w.to, label: w.label, in: Math.round(inn * 100) / 100, out: Math.round(out * 100) / 100, balance: Math.round(bal * 100) / 100 };
		});
	};

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

		function whatIf(d) {
			var who = {};
			d.rows.forEach(function (r) { (r.items || []).forEach(function (e) { if (e.who && (e.kind === 'rechnung' || e.kind === 'ueberfaellig')) { who[e.who] = (who[e.who] || 0) + e.amount; } }); });
			var names = Object.keys(who).sort();
			var o = { shop: 0, preis: 0, verzug: 0, einmal: 0, einmal_woche: 0, monatlich: 0, ohne: [], personal_h: 0, personal_satz: 15 };
			try { var saved = JSON.parse(window.localStorage.getItem('lw16_liqui_szenario') || 'null'); if (saved) { Object.keys(o).forEach(function (key) { if (saved[key] !== undefined) { o[key] = saved[key]; } }); } } catch (e) { /* ohne Speicher */ }
			var out = h('div');
			function range(key, min, max, step, label, unit) {
				var val = h('b', null, '');
				var r = h('input', { type: 'range', min: String(min), max: String(max), step: String(step), value: String(o[key]), style: 'width:100%', 'aria-label': label });
				function show() { val.textContent = (o[key] > 0 && unit === '%' ? '+' : '') + String(o[key]).replace('.', ',') + ' ' + unit; }
				r.addEventListener('input', function () { o[key] = Number(r.value); show(); calc(); });
				show();
				return h('label', { class: 'kt-f' }, h('span', { class: 'kt-l' }, label, ' ', val), r);
			}
			function num(key, label, hint) {
				var i = k.input(String(o[key] || ''), { inputmode: 'decimal', placeholder: '0' });
				i.addEventListener('input', function () { o[key] = Number(String(i.value).replace(/\./g, '').replace(',', '.')) || 0; calc(); });
				return k.field(label, i, hint);
			}
			var weekSel = k.select(d.rows.map(function (r, i) { return [i, r.label + ' (' + k.dmy(r.from) + ')']; }), o.einmal_woche, { 'aria-label': 'Woche' });
			weekSel.addEventListener('change', function () { o.einmal_woche = Number(weekSel.value); calc(); });
			var drop = h('div', { class: 'presets' }, names.map(function (n) {
				var b = h('button', { class: 'chip', type: 'button', 'aria-pressed': o.ohne.indexOf(n) > -1 ? 'true' : 'false' }, n + ' (' + k.eur(who[n]) + ')');
				b.addEventListener('click', function () {
					var at = o.ohne.indexOf(n);
					if (at > -1) { o.ohne.splice(at, 1); } else { o.ohne.push(n); }
					b.setAttribute('aria-pressed', at > -1 ? 'false' : 'true');
					calc();
				});
				return b;
			}));
			function minOf(rows) { return Math.min.apply(null, rows.map(function (r) { return r.balance; })); }
			function firstBelow(rows) { for (var i = 0; i < rows.length; i++) { if (rows[i].balance < d.buffer) { return rows[i]; } } return null; }
			function calc() {
				try { window.localStorage.setItem('lw16_liqui_szenario', JSON.stringify(o)); } catch (e) { /* egal */ }
				var sc = UI.liquiScenario(d.rows, d.start.amount, o);
				var base = d.rows;
				var max = Math.max.apply(null, base.concat(sc).map(function (r) { return Math.abs(r.balance); }).concat([Math.abs(d.buffer), 1]));
				out.textContent = '';
				var end0 = base[base.length - 1].balance, end1 = sc[sc.length - 1].balance;
				var low = firstBelow(sc);
				out.appendChild(h('div', { class: 'grid tiles kt-gap' },
					k.tile('In 12 Wochen', k.eur(end1), (end1 - end0 >= 0 ? '+' : '') + k.eur(end1 - end0) + ' gegenüber heute geplant'),
					k.tile('Tiefster Stand', k.eur(minOf(sc)), 'ohne Änderung ' + k.eur(minOf(base))),
					k.tile('Unter dem Puffer', low ? low.label : 'nie', low ? 'ab ' + k.dmy(low.from) : 'Puffer ' + k.eur(d.buffer))));
				out.appendChild(h('div', { class: 'kt-bars', role: 'img', 'aria-label': 'Vergleich: grau heute geplant, farbig mit den Annahmen' }, sc.map(function (r, i) {
					return h('div', { style: 'background:transparent;display:flex;align-items:flex-end;gap:2px' },
						h('span', { style: 'flex:1;border-radius:3px 3px 0 0;background:var(--surface-muted);height:' + Math.max(3, Math.abs(base[i].balance) / max * 100).toFixed(1) + '%' }),
						h('span', { style: 'flex:1;border-radius:3px 3px 0 0;background:' + (r.balance < d.buffer ? 'var(--bad,#b3261e)' : 'var(--accent,#c8a24a)') + ';height:' + Math.max(3, Math.abs(r.balance) / max * 100).toFixed(1) + '%', title: r.label + ': ' + k.eur(r.balance) }));
				})));
				out.appendChild(h('small', { class: 'muted' }, 'Grau: so wie geplant. Farbig: mit deinen Annahmen.'));
			}
			var reset = h('button', { class: 'btn', type: 'button', onclick: function () {
				try { window.localStorage.removeItem('lw16_liqui_szenario'); } catch (e) { /* egal */ }
				body.textContent = ''; paint(d);
			} }, 'Zurücksetzen');
			var box = h('details', { class: 'card kt-gap', open: true },
				h('summary', null, h('b', null, 'Was wäre wenn …')),
				h('div', { class: 'kt-grid kt-gap' },
					range('shop', -50, 50, 5, 'Shop-Umsatz', '%'),
					range('preis', -20, 20, 1, 'Preise', '%'),
					range('verzug', 0, 60, 5, 'Kunden zahlen später', 'Tage')),
				h('div', { class: 'kt-grid kt-gap' },
					num('einmal', 'Einmalige Ausgabe (€)', 'z. B. neuer Drucker'), k.field('in Woche', weekSel),
					num('monatlich', 'Mehrkosten je Monat (€)', 'z. B. Werkstattmiete'),
					num('personal_h', 'Aushilfe: Stunden je Woche'), num('personal_satz', 'Stundenlohn (€)')),
				names.length ? h('div', { class: 'kt-gap' }, h('span', { class: 'kt-l' }, 'Wenn diese Kunden nicht zahlen'), drop) : null,
				out,
				h('div', { class: 'kt-actions' }, reset));
			calc();
			return box;
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
			body.appendChild(whatIf(d));

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
