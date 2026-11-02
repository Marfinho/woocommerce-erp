/*
 * ERP-Seite „Rückblick“: die Woche in ein paar Sätzen und ein Tipp.
 * Schnittstelle: includes/Erp/Pages/Rueckblick.php. Kein innerHTML mit Daten.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	UI.pages['rueckblick'] = function (item, ui) {
		var h = ui.h;
		var k = UI.kit(ui);
		var state = { woche: 0 };
		var body = h('div');

		ui.pageHead('Wochenrückblick', 'Deine Woche als kurze Geschichte – freitags auch per Mail.');
		ui.view().appendChild(body);

		function settingsBox(s) {
			var on = h('input', { type: 'checkbox', checked: !!s.aktiv });
			var hour = k.select(Array.apply(null, Array(24)).map(function (x, i) { return [i, ('0' + i).slice(-2) + ':00']; }), s.stunde);
			var btn = h('button', { class: 'btn', type: 'submit' }, 'Speichern');
			var send = h('button', { class: 'btn', type: 'button', onclick: function () {
				send.disabled = true;
				ui.api('rueckblick/senden', { method: 'POST', body: {} }).then(function (r) { ui.toast(r.message); }).catch(function (e) { ui.toast(e.message, true); }).then(function () { send.disabled = false; });
			} }, 'Jetzt per Mail schicken');
			var form = h('form', null, h('label', { class: 'lg-chk' }, on, ' Freitags per Mail (an die Adresse des täglichen Überblicks)'), k.field('Uhrzeit', hour), h('div', { class: 'kt-actions' }, send, btn));
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				btn.disabled = true;
				ui.api('rueckblick/einstellungen', { method: 'POST', body: { aktiv: on.checked ? 1 : 0, stunde: hour.value } }).then(function (r) { ui.toast(r.message); btn.disabled = false; })
					.catch(function (e2) { ui.toast(e2.message, true); btn.disabled = false; });
			});
			return h('details', { class: 'card kt-gap' }, h('summary', null, 'Versand'), form);
		}

		function paint(d) {
			body.textContent = '';
			body.appendChild(h('div', { class: 'kt-bar' },
				h('button', { class: 'btn', type: 'button', onclick: function () { state.woche++; load(); } }, '← Vorwoche'),
				h('b', null, d.week.label + ' · ' + k.dmy(d.week.from) + ' – ' + k.dmy(d.week.to)),
				h('button', { class: 'btn', type: 'button', disabled: state.woche === 0 ? true : null, onclick: function () { state.woche = Math.max(0, state.woche - 1); load(); } }, 'Nächste →')));
			body.appendChild(h('div', { class: 'card' },
				h('h3', { style: 'margin:0 0 10px' }, d.headline),
				h('ul', { style: 'margin:0;padding-left:20px;display:grid;gap:6px' }, d.lines.map(function (l) { return h('li', null, l); }))));
			body.appendChild(h('div', { class: 'notice kt-gap' }, h('b', null, 'Tipp für nächste Woche: '), d.tip));
			var f = d.facts;
			body.appendChild(h('div', { class: 'grid tiles kt-gap' },
				k.tile('Umsatz', k.eur(f.revenue), 'Vorwoche ' + k.eur(f.revenue_prev)),
				k.tile('Stundenlohn', f.rate === null ? '—' : k.eur(f.rate), f.rate_prev === null ? '' : 'Vorwoche ' + k.eur(f.rate_prev)),
				k.tile('Platten', f.printers.reduce(function (n, p) { return n + p.plates; }, 0), f.printers.reduce(function (n, p) { return n + p.scrap; }, 0) + ' mit Ausschuss'),
				k.tile('Lager', f.empty + ' leer', f.below_target + ' unter Soll')));
			body.appendChild(settingsBox(d.settings));
		}

		function load() {
			k.loading(body);
			ui.api('rueckblick?' + k.qs({ woche: state.woche })).then(paint).catch(function (e) { k.fail(body, e); });
		}

		load();
	};
})();
