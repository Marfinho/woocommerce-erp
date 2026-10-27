/*
 * ERP-Seite „Tagesplan“: Was heute auf welchem Drucker läuft, was in die Nacht
 * gehört und was am Tag zu bestücken ist – dazu der Stand des Betriebs.
 * Server: includes/Erp/Pages/Prognose.php (GET erp/tagesplan). Alle Daten
 * gelangen über textContent bzw. setAttribute in die Seite, nie als HTML.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	function nf(v, d) {
		return new Intl.NumberFormat('de-DE', { minimumFractionDigits: d || 0, maximumFractionDigits: d === undefined ? 0 : d }).format(v);
	}

	UI.pages['tagesplan'] = function (item, ui) {
		var h = ui.h;
		ui.pageHead('Tagesplan', 'Was heute gedruckt, bestückt und montiert wird – lange Platten über Nacht, kurze am Tag.', ui.classicBtn(item));
		var box = h('div');
		ui.view().appendChild(box);
		box.appendChild(h('div', { class: 'card' }, 'Wird berechnet …'));

		function tile(label, value, note, tone) {
			return h('div', { class: 'tile zt' + (tone ? ' ' + tone : '') },
				h('div', { class: 'lab' }, label), h('div', { class: 'val' }, value),
				note ? h('div', { class: 'muted', style: 'font-size:12px;margin-top:2px' }, note) : null);
		}

		function slot(label, plates, cls) {
			if (!plates.length) { return null; }
			var hours = plates.reduce(function (s, p) { return s + p.hours; }, 0);
			var row = h('div', { class: 'tp-slot' });
			plates.forEach(function (p) { row.appendChild(h('span', { class: 'tp-plate' }, p.name + ' · ' + nf(p.hours, 1) + ' h')); });
			return h('div', { class: cls }, h('div', { class: 'tp-slot-h' }, label + ' · ' + nf(hours, 1) + ' h'), row);
		}

		ui.api('tagesplan').then(function (d) {
			box.textContent = '';
			var days = d.plan.days;
			var crit = d.events.critical, att = d.events.attention;

			box.appendChild(h('div', { class: 'tp-tiles' },
				tile('Offene Bestellungen', nf(d.orders), 'in Bearbeitung oder wartend', d.orders > 0 ? 'warn' : 'ok'),
				tile('Maschine insgesamt', nf(d.totals.machine_h, 1) + ' h', 'auf ' + nf(d.weeks) + ' Woche(n) verteilt'),
				tile('Handarbeit insgesamt', nf(d.totals.operator_h, 1) + ' h', 'Kapazität ' + nf(d.cap.operator_h, 0) + ' h je Woche'),
				tile('Ereignisse heute', crit + ' kritisch', att + ' mit Aufmerksamkeit', crit > 0 ? 'bad' : (att > 0 ? 'warn' : 'ok'))));

			if (!days.length) {
				box.appendChild(h('div', { class: 'notice' }, 'Heute ist nichts zu drucken – alle Bedarfe sind durch Bestand und laufende Aufträge gedeckt.'));
			}

			days.forEach(function (day, i) {
				var card = h('section', { class: 'card' }, h('h3', null, (i === 0 ? 'Heute' : 'Tag ' + day.day) + ' – ' + day.plates + ' Platte(n), ' + nf(day.machine_h, 1) + ' Maschinenstunden'));
				day.printers.forEach(function (p) {
					if (!p.night.length && !p.day.length) { return; }
					card.appendChild(h('div', { class: 'tp-printer' },
						h('strong', null, 'Drucker ' + p.printer),
						slot('Nacht (läuft ohne dich)', p.night, 'tp-night'),
						slot('Tag (du wechselst die Platte)', p.day, 'tp-day')));
				});
				if (i === 0) { box.appendChild(card); }
				else {
					var det = h('details', null, h('summary', null, (i === 0 ? 'Heute' : 'Tag ' + day.day) + ': ' + day.plates + ' Platte(n)'));
					det.appendChild(card);
					box.appendChild(det);
				}
			});
			if (d.plan.left > 0) { box.appendChild(h('div', { class: 'notice warn' }, d.plan.left + ' Platte(n) passen in die nächsten 14 Tage nicht mehr hinein.')); }

			if (d.hands.length) {
				var body = h('tbody');
				d.hands.forEach(function (s) {
					body.appendChild(h('tr', null, h('td', null, s.name), h('td', null, s.action), h('td', { class: 'num' }, nf(s.qty)), h('td', { class: 'num' }, nf(s.operator_h, 1) + ' h')));
				});
				box.appendChild(h('section', { class: 'card' }, h('h3', null, 'Montage und Zukauf'),
					h('div', { class: 'zt-scroll' }, h('table', { class: 'list' }, h('thead', null, h('tr', null, h('th', null, 'Artikel'), h('th', null, 'Was'), h('th', { class: 'num' }, 'Menge'), h('th', { class: 'num' }, 'Handarbeit'))), body))));
			}

			if (d.risks.length) {
				var rb = h('tbody');
				d.risks.forEach(function (r) {
					rb.appendChild(h('tr', null, h('td', null, r.name),
						h('td', { class: 'num' }, nf(r.available)), h('td', { class: 'num' }, nf(r.demand_lead, 1)),
						h('td', null, h('span', { class: 'pg-tag ' + (r.level === 'critical' ? 'bad' : 'warn') }, r.level === 'critical' ? 'Kritisch' : 'Aufmerksamkeit')),
						h('td', { class: 'num' }, r.recommended ? h('strong', null, nf(r.recommended)) : '0')));
				});
				box.appendChild(h('section', { class: 'card' }, h('h3', null, 'Engpässe'),
					h('p', { class: 'muted' }, 'Artikel, deren Bestand voraussichtlich nicht bis zum Nachschub reicht.'),
					h('div', { class: 'zt-scroll' }, h('table', { class: 'list' }, h('thead', null, h('tr', null, h('th', null, 'Artikel'), h('th', { class: 'num' }, 'Verfügbar'), h('th', { class: 'num' }, 'Bedarf im Vorlauf'), h('th', null, 'Stufe'), h('th', { class: 'num' }, 'Empfohlen'))), rb)),
					h('p', null, h('a', { href: '#/seite/prognose' }, 'Zur Prognose'))));
			}
		}).catch(function (e) { box.textContent = ''; box.appendChild(h('div', { class: 'notice warn' }, e.message)); });
	};
})();
