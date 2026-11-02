/*
 * ERP-Seite „Stundenlohn“: Was pro gearbeiteter Stunde bleibt – je Produkt, Kanal, Markt.
 * Schnittstelle: includes/Erp/Pages/Stundenlohn.php. Kein innerHTML mit Daten.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	UI.pages['stundenlohn'] = function (item, ui) {
		var h = ui.h;
		var k = UI.kit(ui);
		var state = { zeitraum: 'quartal', tab: 'products' };
		var body = h('div');

		ui.pageHead('Ehrlicher Stundenlohn', 'Was dir pro gearbeiteter Stunde wirklich bleibt – nach Material, Maschine, Standgebühren und der Zeit für Nachbearbeitung, Packen, Vertrieb und Markt.');
		ui.view().appendChild(body);

		function hrs(n) { return String(Math.round(n * 10) / 10).replace('.', ',') + ' h'; }
		function rate(n) { return n === null ? '—' : k.eur(n); }

		function bars(list) {
			var max = Math.max.apply(null, list.map(function (g) { return Math.abs(g.rate || 0); }).concat([1]));
			return h('div', { class: 'rows' }, list.map(function (g) {
				var w = g.rate === null ? 0 : Math.abs(g.rate) / max * 100;
				return h('article', { class: 'row-card kt-row' },
					h('div', { class: 'row-main' },
						h('b', null, g.label || '—'),
						h('small', null, 'Erlös ' + k.eur(g.revenue) + ' · Material/Maschine ' + k.eur(g.material) + (g.costs ? ' · Kosten ' + k.eur(g.costs) : '') + ' · ' + hrs(g.hours) + (g.qty ? ' · ' + Math.round(g.qty) + ' Stück' : '')),
						h('div', { style: 'height:8px;border-radius:4px;background:var(--surface-muted);margin-top:6px;overflow:hidden' },
							h('div', { style: 'height:100%;width:' + w.toFixed(1) + '%;background:' + (g.rate !== null && g.rate < 15 ? 'var(--bad,#b3261e)' : 'var(--accent,#c8a24a)') }))),
					h('div', { class: 'kt-num' }, h('span', { class: 'row-num' }, rate(g.rate)), h('small', { class: 'muted' }, 'je Stunde')),
					h('div', { class: 'kt-btns' }));
			}));
		}

		function paint(d) {
			body.textContent = '';
			var t = d.totals;
			body.appendChild(h('div', { class: 'kt-bar' },
				k.pills([['monat', 'Monat'], ['vormonat', 'Vormonat'], ['quartal', 'Quartal'], ['jahr', 'Jahr'], ['12m', '12 Monate']], state.zeitraum, function (z) { state.zeitraum = z; load(); }),
				h('small', { class: 'muted' }, k.dmy(d.period.from) + ' – ' + k.dmy(d.period.to) + ' · Beträge ' + d.basis)));
			body.appendChild(h('div', { class: 'grid tiles' },
				k.tile('Je Arbeitsstunde (alles)', rate(t.rate_all), hrs(t.all_hours) + ' gearbeitet'),
				k.tile('Je Stunde an Produkt & Kunde', rate(t.rate_direct), hrs(t.direct_hours)),
				k.tile('Deckungsbeitrag', k.eur(t.db), 'Erlös ' + k.eur(t.revenue)),
				k.tile('Gemeinzeit', hrs(t.overhead_hours), 'Verwaltung, Marketing, Konstruktion …')));
			d.insights.forEach(function (s) { body.appendChild(h('div', { class: 'notice kt-gap' }, s)); });
			body.appendChild(h('div', { class: 'kt-bar' }, k.pills([['products', 'Produkte'], ['channels', 'Kanäle'], ['markets', 'Märkte']], state.tab, function (x) { state.tab = x; paint(d); })));
			var list = d[state.tab];
			body.appendChild(list.length ? bars(list) : h('div', { class: 'card muted' }, 'Im Zeitraum gibt es hier nichts.'));
			body.appendChild(h('details', { class: 'card kt-gap' }, h('summary', null, 'So wird gerechnet'),
				h('p', null, 'Deckungsbeitrag = Erlös minus Herstellkosten ohne Arbeit (Material, Maschine, Komponenten) minus Stand- und Kampagnenkosten der Märkte. Stunden: Nachbearbeitungszeit aus der Kalkulation, hochgerechnet auf die tatsächlich erfassten Produktionsstunden (Faktor ' + String(t.scaled).replace('.', ',') + '), dazu Packen und Versand je Bestellung, Vertriebszeit für Händler und Vereine und die Standzeit auf dem Markt (Kasse auf bis Kasse zu).'),
				h('p', { class: 'muted' }, 'Die Zeile „alles“ teilt durch sämtliche erfassten Stunden einschließlich Verwaltung und Marketing – das ist dein ehrlicher Stundenlohn vor Steuern und vor festen Kosten wie Miete. Erfasste Stunden: Produktion ' + hrs(d.time.direct) + ', Versand ' + hrs(d.time.versand) + ', Vertrieb ' + hrs(d.time.vertrieb) + ', Sonstiges ' + hrs(d.time.overhead) + '.')));
		}

		function load() {
			k.loading(body);
			ui.api('stundenlohn?' + k.qs({ zeitraum: state.zeitraum })).then(paint).catch(function (e) { k.fail(body, e); });
		}

		load();
	};
})();
