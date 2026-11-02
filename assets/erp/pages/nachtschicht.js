/*
 * ERP-Seite „Nachtschicht“: welche Platte heute Abend auf welchen Drucker kommt.
 * Schnittstelle: includes/Erp/Pages/Nachtschicht.php. Kein innerHTML mit Daten.
 */
(function () {
	'use strict';

	var UI = window.LW16ERP_UI = window.LW16ERP_UI || {};
	UI.pages = UI.pages || {};

	UI.pages['nachtschicht'] = function (item, ui) {
		var h = ui.h;
		var k = UI.kit(ui);
		var body = h('div');

		ui.pageHead('Nachtschicht', 'Über Nacht wechselt niemand die Platte – jeder freie Drucker bekommt die Platte, die das Nachtfenster am besten füllt und deren Material schon im AMS steckt.',
			ui.findItem('workflow') ? h('a', { class: 'btn', href: '#/seite/workflow' }, 'Zum Workflow') : null);
		ui.view().appendChild(body);

		function hm(min) { min = Math.max(0, Math.round(min)); return Math.floor(min / 60) + ' h ' + ('0' + (min % 60)).slice(-2) + ' min'; }
		function clock(s) { return (s || '').slice(11, 16); }
		function swatch(hex) { return hex ? h('span', { style: 'display:inline-block;width:12px;height:12px;border-radius:3px;vertical-align:-1px;margin-right:4px;border:1px solid var(--line);background:#' + hex }) : null; }

		function card(a) {
			var p = a.plate;
			var tags = h('div', { class: 'row-tags' },
				k.tag(a.fill + ' % des Fensters', a.fill >= 70 ? 'now' : ''),
				a.refit ? k.tag('umrüsten: ' + (p.material || '?') + ' ' + (p.color || ''), 'later') : k.tag('AMS ' + a.tray.unit + '/' + a.tray.tray + ' passt', 'now'));
			if (a.enough === false) { tags.appendChild(k.tag('Rolle reicht evtl. nicht', 'later')); }
			return h('article', { class: 'row-card kt-row' },
				h('div', { class: 'row-main' },
					h('b', null, a.printer.name + ' → ' + (p.batch || 'Platte ' + p.id)),
					h('small', null, swatch(p.color_hex), (p.material || '') + ' ' + (p.color || '') + ' · ' + p.label),
					h('small', { class: 'muted' }, a.why.join(' · ') + (a.printer.reason ? ' · ' + a.printer.reason : '')),
					tags),
				h('div', { class: 'kt-num' }, h('span', { class: 'row-num' }, hm(p.minutes)), h('small', { class: 'muted' }, p.grams ? Math.round(p.grams) + ' g' : '')),
				h('div', { class: 'kt-btns' }, h('a', { class: 'btn primary', href: '#/platte/' + p.id }, 'Platte öffnen')));
		}

		function settingsBox(s) {
			var leave = k.input(s.feierabend, { type: 'time' });
			var back = k.input(s.aufstehen, { type: 'time' });
			var push = h('input', { type: 'checkbox', checked: !!s.push });
			var btn = h('button', { class: 'btn', type: 'submit' }, 'Speichern');
			var form = h('form', null, h('div', { class: 'kt-grid kt-gap' }, k.field('Feierabend', leave), k.field('Wieder in der Werkstatt', back)),
				h('label', { class: 'lg-chk' }, push, ' Plan eine Stunde vor dem Feierabend aufs Handy schicken'),
				h('div', { class: 'kt-actions' }, btn));
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				btn.disabled = true;
				ui.api('nachtschicht/einstellungen', { method: 'POST', body: { feierabend: leave.value, aufstehen: back.value, push: push.checked ? 1 : 0 } })
					.then(function (r) { ui.toast(r.message); load(); }).catch(function (e2) { ui.toast(e2.message, true); btn.disabled = false; });
			});
			return h('details', { class: 'card kt-gap' }, h('summary', null, 'Nachtfenster'), form);
		}

		function paint(d) {
			body.textContent = '';
			var plan = d.plan;
			var used = plan.assignments.reduce(function (n, a) { return n + Math.min(a.plate.minutes, d.window.minutes); }, 0);
			body.appendChild(h('div', { class: 'grid tiles' },
				k.tile('Nachtfenster', clock(d.window.start) + '–' + clock(d.window.end), hm(d.window.minutes)),
				k.tile('Platten heute Nacht', plan.assignments.length, 'von ' + d.plates + ' freigegebenen'),
				k.tile('Druckstunden', hm(used), d.printers + ' Drucker'),
				k.tile('Umrüsten', plan.assignments.filter(function (a) { return a.refit; }).length)));

			var list = h('div', { class: 'rows kt-gap' });
			if (!plan.assignments.length) {
				list.appendChild(h('div', { class: 'card muted' }, d.plates ? 'Kein freier Drucker passt – siehe unten.' : 'Keine freigegebene Platte. Im Workflow neue Platten freigeben, dann kommt hier der Vorschlag.'));
			}
			plan.assignments.forEach(function (a) { list.appendChild(card(a)); });
			body.appendChild(list);

			if (plan.idle.length) {
				body.appendChild(h('h3', { class: 'kt-h' }, 'Ohne Platte'));
				body.appendChild(h('div', { class: 'rows' }, plan.idle.map(function (i) {
					return h('div', { class: 'row-card kt-row kt-dim' }, h('div', { class: 'row-main' }, h('b', null, i.printer.name), h('small', null, i.reason)), h('div'), h('div'));
				})));
			}
			if (plan.left.length) {
				body.appendChild(h('details', { class: 'card kt-gap' }, h('summary', null, plan.left.length + ' Platten bleiben für den Tag'),
					k.table(['Platte', 'Inhalt', 'Dauer', 'Material'], plan.left.map(function (p) { return [p.batch || '#' + p.id, p.label, hm(p.minutes), (p.material || '') + ' ' + (p.color || '')]; }), [2])));
			}
			body.appendChild(settingsBox(d.settings));
		}

		function load() {
			ui.api('nachtschicht').then(paint).catch(function (e) { k.fail(body, e); });
		}

		k.loading(body);
		load();
		ui.every(load, 120000);
	};
})();
